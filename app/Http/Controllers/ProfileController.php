<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user=$request->user()->loadMissing(['department','organizationUnit','portalRole','groups:id,name,department_id,portal_role_id','externalAccessApprover:id,name']);
        $network=SystemSetting::valueFor('security.network',['enabled'=>false]);
        return Inertia::render('Profile', [
            'user'=>$user,
            'networkPolicy'=>[
                'enabled'=>(bool)($network['enabled']??false),
                'current_access_source'=>$request->session()->get('network_access_source','unknown'),
            ],
        ]);
    }

    public function updateInfo(Request $request, AuditService $audit)
    {
        $user=$request->user();
        $data=$request->validate([
            'name'=>'required|string|max:255',
            'email'=>'required|email|unique:users,email,'.$user->id,
            'phone'=>'nullable|string|max:20',
            'preferred_language'=>'nullable|in:en,gu',
            'ui_theme'=>['nullable',Rule::in(User::UI_THEMES)],
        ]);
        $before=$user->only(['name','email','phone','preferred_language','ui_theme']);
        $user->update($data);
        $audit->log($request,'profile.updated',$user,'User profile updated.',['before'=>$before,'after'=>$data]);
        return back()->with('success','Profile preferences saved successfully.');
    }

    /**
     * Switch interface language on its own.
     *
     * The full profile form needs a name and an email; changing language from
     * the header should not, so it has its own endpoint. The reply is a plain
     * redirect, which makes Inertia re-fetch the page with the new catalogue.
     */
    public function updateLanguage(Request $request, AuditService $audit)
    {
        $data=$request->validate(['preferred_language'=>'required|in:en,gu']);
        $user=$request->user();
        $before=$user->preferred_language;
        if($before===$data['preferred_language'])return back();
        $user->update($data);
        $audit->log($request,'profile.language-changed',$user,'Interface language changed.',['before'=>$before,'after'=>$data['preferred_language']]);
        return back();
    }

    public function updatePassword(Request $request, AuditService $audit)
    {
        $min=max(8,(int)(SystemSetting::valueFor('security.auth',[])['password_min_length']??12));
        $request->validate(['current_password'=>['required','current_password'],'password'=>['required','confirmed',Password::min($min)]]);
        $request->user()->update(['password'=>Hash::make($request->password),'must_change_password'=>false]);
        $audit->log($request,'profile.password-changed',$request->user(),'User changed password.');
        return back()->with('success','Password changed successfully.');
    }
}
