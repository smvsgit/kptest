<?php
namespace App\Http\Controllers;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\Request;
class SecuritySettingsController extends Controller {
 public function update(Request $r,AuditService $audit){$d=$r->validate(['failed_login_limit'=>'required|integer|min:3|max:20','lockout_minutes'=>'required|integer|min:1|max:1440','session_timeout_minutes'=>'required|integer|min:5|max:1440','password_min_length'=>'required|integer|min:8|max:64']);$before=SystemSetting::valueFor('security.auth',[]);SystemSetting::put('security.auth',$d);$audit->log($r,'settings.security-auth.updated',null,'Authentication/session settings updated.',['before'=>$before,'after'=>$d]);return back()->with('success','Security session settings saved.');}
 public function updateAccess(Request $r,AuditService $audit){$d=$r->validate(['default_expiry_hours'=>'required|integer|min:0|max:8760','signed_download_minutes'=>'required|integer|min:1|max:1440','max_bulk_request_files'=>'required|integer|min:1|max:500']);$before=SystemSetting::valueFor('access.maturity',[]);SystemSetting::put('access.maturity',$d);$audit->log($r,'settings.access-maturity.updated',null,'Access maturity settings updated.',['before'=>$before,'after'=>$d]);return back()->with('success','Access maturity settings saved.');}
}
