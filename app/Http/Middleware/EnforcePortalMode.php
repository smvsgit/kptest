<?php
namespace App\Http\Middleware;
use App\Models\SystemSetting; use App\Services\NetworkPolicyService; use Closure; use Illuminate\Http\Request; use Symfony\Component\HttpFoundation\Response;
class EnforcePortalMode { public function handle(Request $r,Closure $next): Response { $m=SystemSetting::valueFor('maintenance.settings',['enabled'=>false]); $u=$r->user(); if(($m['enabled']??false) && !($u && $u->role==='super-admin' && ($m['allow_super_admin_bypass']??true))) return response($m['message']??'Maintenance in progress.',503,['Retry-After'=>'600']); if($u && !app(NetworkPolicyService::class)->allows($r,$u)) abort(403,'Access is restricted to the approved internal network or VPN.'); return $next($r); } }
