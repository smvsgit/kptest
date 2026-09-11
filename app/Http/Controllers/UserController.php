<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\OrganizationUnit;
use App\Models\PermissionSet;
use App\Models\PortalRole;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\UserLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    private const ROLES=['super-admin','department-admin','department-operator','viewer'];

    public function store(Request $request, AuditService $audit)
    {
        $actor=$request->user();
        abort_unless(in_array($actor->role,['super-admin','department-admin'],true),403);
        $data=$request->validate([
            'name'=>'required|string|max:255',
            'email'=>'required|email|max:255|unique:users,email',
            'phone'=>'nullable|string|max:30',
            'department_id'=>'nullable|integer|exists:departments,id',
            'organization_unit_id'=>'nullable|integer|exists:organization_units,id',
            'portal_role_id'=>'required|integer|exists:portal_roles,id',
            'send_reset_email'=>'nullable|boolean',
        ]);
        $role=PortalRole::whereKey($data['portal_role_id'])->where('is_active',true)->firstOrFail();
        if($actor->role==='department-admin'){
            $data['department_id']=$actor->department_id;
            if(in_array($role->base_role,['super-admin','department-admin'],true)) throw ValidationException::withMessages(['portal_role_id'=>'Department Admin can create only Department Operator/Viewer based users.']);
        }
        if($role->base_role!=='super-admin' && empty($data['department_id'])) throw ValidationException::withMessages(['department_id'=>'Department is required for this role.']);
        if(!empty($data['organization_unit_id'])){
            $unit=OrganizationUnit::findOrFail($data['organization_unit_id']);
            if((int)$unit->department_id !== (int)($data['department_id']??0)) throw ValidationException::withMessages(['organization_unit_id'=>'Organization unit does not belong to selected department.']);
        }
        $user=User::create([
            'name'=>$data['name'], 'email'=>strtolower(trim($data['email'])), 'phone'=>$data['phone']??null,
            'department_id'=>$data['department_id']??null, 'organization_unit_id'=>$data['organization_unit_id']??null,
            'role'=>$role->base_role, 'portal_role_id'=>$role->id, 'status'=>'active',
            'password'=>Hash::make(Str::random(48)), 'must_change_password'=>false, 'preferred_language'=>'en', 'ui_theme'=>'smvs-dark',
        ]);
        $mailSent=false;
        if($data['send_reset_email']??true) $mailSent=$this->sendResetLink($user);
        $audit->log($request,'user.created',$user,'Administrator created user account.',[
            'portal_role'=>$role->name,'department_id'=>$user->department_id,'password_setup_email_sent'=>$mailSent,
        ]);
        return response()->json(['message'=>$mailSent?'User created and password setup email sent.':'User created. Mail delivery could not be confirmed; use Send Password Reset after mail setup.','user_id'=>$user->id]);
    }

    public function updateRole(Request $request, User $user, AuditService $audit)
    {
        $this->assertUserManageScope($request,$user,true);
        if ($request->user()->is($user)) throw ValidationException::withMessages(['role'=>'You cannot change your own role from the admin console.']);
        $data=$request->validate(['role'=>['required',Rule::in(self::ROLES)]]);
        if($request->user()->role==='department-admin' && in_array($data['role'],['super-admin','department-admin'],true)) abort(403);
        if($data['role']!=='super-admin'&&$user->department_id===null)throw ValidationException::withMessages(['role'=>'Assign a department before granting a department-scoped role.']);
        $builtin=PortalRole::where('slug',$data['role'])->where('is_builtin',true)->first();
        $before=['role'=>$user->role,'portal_role_id'=>$user->portal_role_id];
        $user->update(['role'=>$data['role'],'portal_role_id'=>$builtin?->id]);
        $audit->log($request,'user.role.updated',$user,'User role updated.',['before'=>$before,'after'=>['role'=>$data['role'],'portal_role_id'=>$builtin?->id]]);
        return back()->with('success','User role updated successfully.');
    }

    public function updateDepartment(Request $request, User $user, AuditService $audit, UserLifecycleService $lifecycle)
    {
        $this->assertUserManageScope($request,$user,true);
        abort_unless($request->user()->role==='super-admin',403,'Department transfer is Super Admin only.');
        $data=$request->validate(['department_id'=>['nullable','integer','exists:departments,id'],'organization_unit_id'=>['nullable','integer','exists:organization_units,id'],'successor_user_id'=>['nullable','integer','exists:users,id']]);
        if($user->role!=='super-admin'&&empty($data['department_id']))throw ValidationException::withMessages(['department_id'=>'A department is required for non-Super Admin users.']);
        if(!empty($data['organization_unit_id'])){$unit=OrganizationUnit::findOrFail($data['organization_unit_id']);if($unit->department_id!==(int)$data['department_id'])throw ValidationException::withMessages(['organization_unit_id'=>'Organization unit does not belong to selected department.']);}
        $before=['department_id'=>$user->department_id,'organization_unit_id'=>$user->organization_unit_id];
        $lifecycle->transferDepartment($user,$data['department_id']??null,$data['organization_unit_id']??null,$request->user(),$data['successor_user_id']??null);
        $audit->log($request,'user.department.updated',$user,'User department/organization unit updated with controlled access transition.',['before'=>$before,'after'=>['department_id'=>$data['department_id']??null,'organization_unit_id'=>$data['organization_unit_id']??null]]);
        return back()->with('success','User organization assignment updated; old protected entitlements/requests were transitioned safely.');
    }

    public function updateStatus(Request $request, User $user, AuditService $audit, UserLifecycleService $lifecycle)
    {
        abort_unless($request->user()->role==='super-admin',403);
        abort_if($request->user()->is($user),422,'You cannot disable/inactivate your own account.');
        $data=$request->validate(['status'=>['required',Rule::in(['active','disabled','inactive'])],'reason'=>'nullable|string|max:2000','successor_user_id'=>'nullable|integer|exists:users,id']);
        if($data['status']!=='active'&&blank($data['reason']??null))throw ValidationException::withMessages(['reason'=>'Reason is required for Disabled/Inactive.']);
        $before=$user->status??'active';$lifecycle->changeStatus($user,$data['status'],$data['reason']??null,$request->user(),$data['successor_user_id']??null);
        $audit->log($request,'user.status.updated',$user,'User lifecycle status updated.',['before'=>$before,'after'=>$data['status'],'reason'=>$data['reason']??null]);
        return response()->json(['message'=>"{$user->name} is now {$data['status']}."]);
    }

    public function updatePermissionSet(Request $request, User $user, AuditService $audit)
    {
        abort_unless($request->user()->role==='super-admin',403);
        $data=$request->validate(['permission_set_id'=>'nullable|integer|exists:permission_sets,id']);$before=$user->permission_set_id;$user->update(['permission_set_id'=>$data['permission_set_id']??null]);$audit->log($request,'user.permission-set.updated',$user,'User permission set updated.',['before'=>$before,'after'=>$user->permission_set_id]);return back()->with('success','Permission set updated.');
    }

    public function temporaryPassword(Request $request, User $user, AuditService $audit, UserLifecycleService $lifecycle)
    {
        abort_unless($request->user()->role==='super-admin',403);
        abort_if($request->user()->is($user),422,'Use Change Password for your own account.');$password=$lifecycle->temporaryPassword($user);$audit->log($request,'user.temporary-password.issued',$user,'Administrator issued a one-time temporary password and revoked sessions.');return response()->json(['message'=>'Temporary password issued. User must change it after login.','temporary_password'=>$password]);
    }

    public function sendPasswordReset(Request $request, User $user, AuditService $audit)
    {
        $this->assertUserManageScope($request,$user,false);
        abort_unless($user->isActive(),422,'Password reset email can only be sent to an active user.');
        $sent=$this->sendResetLink($user);
        $audit->log($request,'user.password-reset.requested',$user,'Administrator requested password reset email.',['mail_sent'=>$sent]);
        return response()->json(['message'=>$sent?'Password reset email sent.':'Password reset token created, but mail delivery failed. Check Notification/SMTP settings and retry.']);
    }

    public function sendPasswordResetAll(Request $request, AuditService $audit)
    {
        abort_unless($request->user()->role==='super-admin',403);
        $data=$request->validate(['confirm'=>'required|accepted']);
        $sent=0;$failed=0;
        User::where('status','active')->where('id','<>',$request->user()->id)->orderBy('id')->chunkById(100,function($users)use(&$sent,&$failed){foreach($users as $u){$this->sendResetLink($u)?$sent++:$failed++;}});
        $audit->log($request,'users.password-reset.bulk-requested',null,'Super Admin requested password reset emails for all active users.',['sent'=>$sent,'failed'=>$failed]);
        return response()->json(['message'=>"Password reset email run complete: {$sent} sent, {$failed} failed."]);
    }

    public function updateExternalAccess(Request $request, User $user, AuditService $audit)
    {
        $actor=$request->user();
        $network=SystemSetting::valueFor('security.network',['department_admin_can_manage_external_access'=>false]);
        if($actor->role==='department-admin'){
            abort_unless((bool)($network['department_admin_can_manage_external_access']??false),403,'Department Admin external-access management is disabled by Super Admin.');
            abort_unless((int)$user->department_id===(int)$actor->department_id,403);
            abort_if(in_array($user->role,['super-admin','department-admin'],true),403,'Department Admin cannot grant external access to administrator accounts.');
        } else abort_unless($actor->role==='super-admin',403);

        $data=$request->validate([
            'allowed'=>'required|boolean',
            'starts_at'=>'nullable|date',
            'expires_at'=>'nullable|date',
            'reason'=>'nullable|string|max:2000',
        ]);
        if($data['allowed'] && blank($data['reason']??null)) throw ValidationException::withMessages(['reason'=>'Reason is required when external Internet access is allowed.']);
        $effectiveStart = $data['allowed'] ? (!empty($data['starts_at']) ? \Illuminate\Support\Carbon::parse($data['starts_at']) : now()) : null;
        $effectiveExpiry = $data['allowed'] && !empty($data['expires_at']) ? \Illuminate\Support\Carbon::parse($data['expires_at']) : null;
        if ($effectiveExpiry && $effectiveStart && $effectiveExpiry->lte($effectiveStart)) {
            throw ValidationException::withMessages(['expires_at'=>'Expiry must be later than the External Internet Access start time.']);
        }
        $before=$user->only(['external_access_allowed','external_access_starts_at','external_access_expires_at','external_access_reason','external_access_approved_by']);
        $user->update([
            'external_access_allowed'=>(bool)$data['allowed'],
            'external_access_starts_at'=>$effectiveStart,
            'external_access_expires_at'=>$effectiveExpiry,
            'external_access_reason'=>$data['allowed']?($data['reason']??null):null,
            'external_access_approved_by'=>$data['allowed']?$actor->id:null,
        ]);
        if(!$data['allowed']) DB::table('sessions')->where('user_id',$user->id)->delete();
        $audit->log($request,$data['allowed']?'user.external-access.allowed':'user.external-access.blocked',$user,'External Internet access policy changed.',[
            'before'=>$before,'after'=>$user->fresh()->only(['external_access_allowed','external_access_starts_at','external_access_expires_at','external_access_reason','external_access_approved_by']),
        ]);
        return response()->json(['message'=>$data['allowed']?'External Internet access allowed for this user.':'External Internet access blocked and active sessions revoked.']);
    }

    public function exportCsv(Request $request, AuditService $audit): StreamedResponse
    {
        abort_unless(in_array($request->user()->role,['super-admin','department-admin'],true),403);
        $actor=$request->user();$audit->log($request,'user.exported',null,'User access review CSV exported.');
        return response()->streamDownload(function()use($actor){$out=fopen('php://output','wb');fputcsv($out,['name','email','phone','department','organization_unit','role','portal_role','permission_set','status','external_internet','external_starts','external_expires','groups','last_login_at','last_seen_at']);User::with(['department','organizationUnit','permissionSet','portalRole','groups'])->when($actor->role==='department-admin',fn($q)=>$q->where('department_id',$actor->department_id))->orderBy('name')->chunkById(250,function($users)use($out){foreach($users as $u)fputcsv($out,[$u->name,$u->email,$u->phone,$u->department?->name,$u->organizationUnit?->name,$u->role,$u->portalRole?->name,$u->permissionSet?->name,$u->status,$u->external_access_allowed?'Allowed':'Blocked',$u->external_access_starts_at?->toIso8601String(),$u->external_access_expires_at?->toIso8601String(),$u->groups->pluck('name')->join('; '),$u->last_login_at?->toIso8601String(),$u->last_seen_at?->toIso8601String()]);});fclose($out);},'karyalay-user-access-review-'.now()->format('Ymd-His').'.csv',['Content-Type'=>'text/csv']);
    }

    public function importCsv(Request $request, AuditService $audit)
    {
        abort_unless($request->user()->role==='super-admin',403);
        $request->validate(['file'=>'required|file|max:5120']);$fh=fopen($request->file('file')->getRealPath(),'rb');$headers=fgetcsv($fh);if(!$headers)throw ValidationException::withMessages(['file'=>'CSV header is missing.']);$headers=array_map(fn($h)=>strtolower(trim((string)$h)),$headers);$required=['name','email','department','role'];foreach($required as $h)if(!in_array($h,$headers,true))throw ValidationException::withMessages(['file'=>"Missing CSV column: {$h}"]);
        $created=0;$updated=0;$errors=[];$rowNo=1;
        while(($row=fgetcsv($fh))!==false){$rowNo++;$row=array_slice(array_pad($row,count($headers),''),0,count($headers));$data=array_combine($headers,$row);try{$dept=Department::where('name',trim($data['department']??''))->first();if(!$dept)throw new \RuntimeException('Unknown department.');$role=trim($data['role']??'viewer');if(!in_array($role,self::ROLES,true))throw new \RuntimeException('Invalid role.');$email=strtolower(trim($data['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Invalid email.');$unit=null;if(trim($data['organization_unit']??'')!=='')$unit=OrganizationUnit::where('department_id',$dept->id)->where('name',trim($data['organization_unit']))->first();$set=null;if(trim($data['permission_set']??'')!=='')$set=PermissionSet::where('name',trim($data['permission_set']))->where('is_active',true)->first();$builtin=PortalRole::where('slug',$role)->where('is_builtin',true)->first();$user=User::where('email',$email)->first();$status=in_array(trim($data['status']??'active'),['active','disabled','inactive'],true)?trim($data['status']??'active'):'active';$attrs=['name'=>trim($data['name']),'phone'=>trim($data['phone']??'')?:null,'department_id'=>$dept->id,'organization_unit_id'=>$unit?->id,'role'=>$role,'portal_role_id'=>$builtin?->id,'permission_set_id'=>$set?->id,'status'=>$status];if($user){if((int)$user->department_id!==$dept->id)throw new \RuntimeException('Existing user department transfer must use controlled UI with successor/access transition.');if(($user->status??'active')!==$status)throw new \RuntimeException('Existing user lifecycle status change must use controlled UI.');$user->update($attrs);$updated++;}else{$attrs['email']=$email;$attrs['password']=Hash::make(Str::random(48));$attrs['must_change_password']=false;$user=User::create($attrs);$created++;}}catch(\Throwable $e){$errors[]="Row {$rowNo}: {$e->getMessage()}";}}
        fclose($fh);$audit->log($request,'user.imported',null,'User CSV import completed.',['created'=>$created,'updated'=>$updated,'errors'=>count($errors)]);return response()->json(['message'=>"Import complete: {$created} created, {$updated} updated, ".count($errors).' error(s).','errors'=>array_slice($errors,0,25)]);
    }

    private function assertUserManageScope(Request $request, User $target, bool $roleChange): void
    {
        $actor=$request->user();
        abort_unless(in_array($actor->role,['super-admin','department-admin'],true),403);
        if($actor->role==='department-admin'){
            abort_unless((int)$target->department_id===(int)$actor->department_id,403);
            if($roleChange && in_array($target->role,['super-admin','department-admin'],true)) abort(403);
        }
    }

    private function sendResetLink(User $user): bool
    {
        $token=Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email'=>$user->email],['token'=>Hash::make($token),'created_at'=>now()]);
        $url=url('/reset-password/'.$token).'?email='.urlencode($user->email);
        $branding=SystemSetting::valueFor('branding.settings',['portal_name'=>'Karyalay Portal']);
        $portalName=trim((string)($branding['portal_name']??''))?:'Karyalay Portal';
        try{
            Mail::raw("Set or reset your {$portalName} password: {$url}\nThis link is single-use and expires automatically.",fn($m)=>$m->to($user->email)->subject($portalName.' password setup/reset'));
            return true;
        }catch(\Throwable){return false;}
    }
}
