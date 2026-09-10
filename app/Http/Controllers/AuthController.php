<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\PortalRole;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NetworkPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function showLogin(){return Inertia::render('Auth/Login');}

    public function login(Request $request, AuditService $audit, NetworkPolicyService $network)
    {
        $credentials=$request->validate(['email'=>'required|email','password'=>'required']);
        $email=strtolower(trim($credentials['email']));
        $user=User::whereRaw('LOWER(email)=?',[$email])->first();
        if($user?->locked_until&&$user->locked_until->isPast())$user->forceFill(['locked_until'=>null,'failed_login_count'=>0])->save();
        $settings=SystemSetting::valueFor('security.auth',[]);$limit=max(3,(int)($settings['failed_login_limit']??5));$lock=max(1,(int)($settings['lockout_minutes']??15));
        if($user && ($user->status??'active')!=='active'){$audit->log($request,'auth.login-blocked',$user,'Login blocked for non-active user.',['status'=>$user->status]);return back()->withErrors(['email'=>'This account is disabled or inactive. Contact an administrator.'])->onlyInput('email');}
        if($user?->locked_until && $user->locked_until->isFuture()){$audit->log($request,'auth.login-locked',$user,'Login attempted while account lockout is active.');return back()->withErrors(['email'=>'This account is temporarily locked. Try again later or contact an administrator.'])->onlyInput('email');}

        // Authenticate credentials before evaluating a user-specific external-access exception.
        // This avoids exposing whether a known email has an external Internet entitlement.
        if(!Auth::attempt(['email'=>$user?->email ?? $credentials['email'],'password'=>$credentials['password']],$request->boolean('remember'))){
            if($user){$count=(int)$user->failed_login_count+1;$values=['failed_login_count'=>$count];if($count>=$limit)$values['locked_until']=now()->addMinutes($lock);$user->forceFill($values)->save();}
            $audit->log($request,'auth.login-failed',$user,'Invalid login attempt.',['email'=>$email,'attempt_count'=>$user?->failed_login_count]);
            return back()->withErrors(['email'=>'These credentials do not match our records.'])->onlyInput('email');
        }

        $user=$request->user();
        $source=$network->accessSource($request,$user);
        if($source===NetworkPolicyService::SOURCE_BLOCKED){
            $audit->log($request,'auth.login-network-blocked',$user,'Login blocked by network/VPN/external-access policy.',['ip'=>$request->ip(),'access_source'=>'blocked']);
            Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();
            return back()->withErrors(['email'=>'This account is restricted to the approved internal network or VPN. Ask an administrator to allow External Internet Access if required.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->put('policy_last_activity',time());
        $request->session()->put('network_access_source',$source);
        $user->forceFill(['failed_login_count'=>0,'locked_until'=>null,'last_login_at'=>now(),'last_seen_at'=>now()])->save();
        $audit->log($request,'auth.login',$user,'User login successful.',['access_source'=>$source,'ip'=>$request->ip()]);
        if($user->must_change_password)return redirect()->route('profile')->with('warning','Administrator-issued temporary password must be changed before continuing.');
        $twoFactor=SystemSetting::valueFor('security.two_factor',['enabled'=>true]);
        if(($twoFactor['enabled']??true) && $user->two_factor_confirmed_at){$request->session()->forget('2fa_passed_at');return redirect()->route('security.2fa.challenge-page');}
        return redirect()->intended(route('dashboard'));
    }

    public function showRegister(){return Inertia::render('Auth/Register');}
    public function register(Request $request, NetworkPolicyService $network, AuditService $audit)
    {
        if ($network->accessSource($request, null) === NetworkPolicyService::SOURCE_BLOCKED) {
            abort(403, 'New account registration is restricted to the approved internal network or VPN.');
        }
        $min=max(8,(int)(SystemSetting::valueFor('security.auth',[])['password_min_length']??12));
        $data=$request->validate(['name'=>'required|string|max:255','email'=>'required|email|unique:users','phone'=>'nullable|string|max:20','password'=>['required','confirmed',Password::min($min)]]);
        $defaultDepartmentId=Department::where('is_system',true)->value('id');
        $viewerRole=PortalRole::where('slug','viewer')->where('is_builtin',true)->first();
        $user=User::create(['name'=>$data['name'],'email'=>strtolower(trim($data['email'])),'phone'=>$data['phone']??null,'department_id'=>$defaultDepartmentId,'password'=>Hash::make($data['password']),'role'=>'viewer','portal_role_id'=>$viewerRole?->id,'status'=>'active','ui_theme'=>'smvs']);
        Auth::login($user);
        $request->session()->regenerate();
        $source=$network->accessSource($request,$user);
        $request->session()->put('policy_last_activity',time());
        $request->session()->put('network_access_source',$source);
        $user->forceFill(['last_login_at'=>now(),'last_seen_at'=>now()])->save();
        $audit->log($request,'auth.register',$user,'User self-registration completed and session started.',['access_source'=>$source,'ip'=>$request->ip()]);
        return redirect()->route('dashboard');
    }

    public function logout(Request $request, AuditService $audit)
    {
        if($request->user())$audit->log($request,'auth.logout',$request->user(),'User logged out.',['access_source'=>$request->session()->get('network_access_source')]);Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();return redirect()->route('login');
    }
}
