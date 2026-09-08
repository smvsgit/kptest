<?php
namespace App\Http\Middleware;
use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class EnforceUserSessionPolicy {
 public function handle(Request $request,Closure $next): mixed {
  $user=$request->user(); if(!$user)return $next($request);
  if(($user->status??'active')!=='active'){Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();return redirect()->route('login')->withErrors(['email'=>'Your account is not active. Contact an administrator.']);}
  if($user->locked_until && $user->locked_until->isFuture()){Auth::logout();$request->session()->invalidate();return redirect()->route('login')->withErrors(['email'=>'Your account is temporarily locked.']);}
  $settings=SystemSetting::valueFor('security.auth',[]);$timeout=max(5,(int)($settings['session_timeout_minutes']??120))*60;$last=(int)$request->session()->get('policy_last_activity',time());
  if(time()-$last>$timeout){Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();return redirect()->route('login')->withErrors(['email'=>'Your session expired due to inactivity.']);}
  $request->session()->put('policy_last_activity',time());
  if(!$user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(5)))$user->forceFill(['last_seen_at'=>now()])->saveQuietly();
  if($user->must_change_password && !$request->routeIs('profile','profile.password','logout')) return redirect()->route('profile')->with('warning','Change your temporary password before continuing.');
  return $next($request);
 }
}
