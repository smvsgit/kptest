<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\OrganizationUnit;
use App\Models\PermissionSet;
use App\Models\User;
use App\Services\AuditService;
use App\Services\UserLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    private const ROLES=['super-admin','department-admin','department-operator','viewer'];

    public function updateRole(Request $request, User $user, AuditService $audit)
    {
        if ($request->user()->is($user)) throw ValidationException::withMessages(['role'=>'You cannot change your own role from the admin console.']);
        $data=$request->validate(['role'=>['required',Rule::in(self::ROLES)]]);
        if($data['role']!=='super-admin'&&$user->department_id===null)throw ValidationException::withMessages(['role'=>'Assign a department before granting a department-scoped role.']);
        $before=$user->role;$user->update(['role'=>$data['role']]);
        $audit->log($request,'user.role.updated',$user,'User role updated.',['before'=>$before,'after'=>$data['role']]);
        return back()->with('success','User role updated successfully.');
    }

    public function updateDepartment(Request $request, User $user, AuditService $audit, UserLifecycleService $lifecycle)
    {
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
        $data=$request->validate(['status'=>['required',Rule::in(['active','disabled','inactive'])],'reason'=>'nullable|string|max:2000','successor_user_id'=>'nullable|integer|exists:users,id']);
        if($data['status']!=='active'&&blank($data['reason']??null))throw ValidationException::withMessages(['reason'=>'Reason is required for Disabled/Inactive.']);
        $before=$user->status??'active';$lifecycle->changeStatus($user,$data['status'],$data['reason']??null,$request->user(),$data['successor_user_id']??null);
        $audit->log($request,'user.status.updated',$user,'User lifecycle status updated.',['before'=>$before,'after'=>$data['status'],'reason'=>$data['reason']??null]);
        return response()->json(['message'=>"{$user->name} is now {$data['status']}."]);
    }

    public function updatePermissionSet(Request $request, User $user, AuditService $audit)
    {
        $data=$request->validate(['permission_set_id'=>'nullable|integer|exists:permission_sets,id']);$before=$user->permission_set_id;$user->update(['permission_set_id'=>$data['permission_set_id']??null]);$audit->log($request,'user.permission-set.updated',$user,'User permission set updated.',['before'=>$before,'after'=>$user->permission_set_id]);return back()->with('success','Permission set updated.');
    }

    public function temporaryPassword(Request $request, User $user, AuditService $audit, UserLifecycleService $lifecycle)
    {
        abort_if($request->user()->is($user),422,'Use Change Password for your own account.');$password=$lifecycle->temporaryPassword($user);$audit->log($request,'user.temporary-password.issued',$user,'Administrator issued a one-time temporary password and revoked sessions.');return response()->json(['message'=>'Temporary password issued. User must change it after login.','temporary_password'=>$password]);
    }

    public function exportCsv(Request $request, AuditService $audit): StreamedResponse
    {
        $audit->log($request,'user.exported',null,'User access review CSV exported.');
        return response()->streamDownload(function(){ $out=fopen('php://output','wb');fputcsv($out,['name','email','phone','department','organization_unit','role','permission_set','status','last_login_at','last_seen_at']);User::with(['department','organizationUnit','permissionSet'])->orderBy('name')->chunkById(250,function($users)use($out){foreach($users as $u)fputcsv($out,[$u->name,$u->email,$u->phone,$u->department?->name,$u->organizationUnit?->name,$u->role,$u->permissionSet?->name,$u->status,$u->last_login_at?->toIso8601String(),$u->last_seen_at?->toIso8601String()]);});fclose($out);},'karyalay-user-access-review-'.now()->format('Ymd-His').'.csv',['Content-Type'=>'text/csv']);
    }

    public function importCsv(Request $request, AuditService $audit)
    {
        $request->validate(['file'=>'required|file|max:5120']);$fh=fopen($request->file('file')->getRealPath(),'rb');$headers=fgetcsv($fh);if(!$headers)throw ValidationException::withMessages(['file'=>'CSV header is missing.']);$headers=array_map(fn($h)=>strtolower(trim((string)$h)),$headers);$required=['name','email','department','role'];foreach($required as $h)if(!in_array($h,$headers,true))throw ValidationException::withMessages(['file'=>"Missing CSV column: {$h}"]);
        $created=0;$updated=0;$errors=[];$rowNo=1;
        while(($row=fgetcsv($fh))!==false){$rowNo++;$row=array_slice(array_pad($row,count($headers),''),0,count($headers));$data=array_combine($headers,$row);try{$dept=Department::where('name',trim($data['department']??''))->first();if(!$dept)throw new \RuntimeException('Unknown department.');$role=trim($data['role']??'viewer');if(!in_array($role,self::ROLES,true))throw new \RuntimeException('Invalid role.');$email=strtolower(trim($data['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Invalid email.');$unit=null;if(trim($data['organization_unit']??'')!=='')$unit=OrganizationUnit::where('department_id',$dept->id)->where('name',trim($data['organization_unit']))->first();$set=null;if(trim($data['permission_set']??'')!=='')$set=PermissionSet::where('name',trim($data['permission_set']))->where('is_active',true)->first();$user=User::where('email',$email)->first();$status=in_array(trim($data['status']??'active'),['active','disabled','inactive'],true)?trim($data['status']??'active'):'active';$attrs=['name'=>trim($data['name']),'phone'=>trim($data['phone']??'')?:null,'department_id'=>$dept->id,'organization_unit_id'=>$unit?->id,'role'=>$role,'permission_set_id'=>$set?->id,'status'=>$status];if($user){if((int)$user->department_id!==$dept->id)throw new \RuntimeException('Existing user department transfer must use controlled UI with successor/access transition.');if(($user->status??'active')!==$status)throw new \RuntimeException('Existing user lifecycle status change must use controlled UI.');$user->update($attrs);$updated++;}else{$temp=trim($data['temporary_password']??'');$attrs['email']=$email;$attrs['password']=Hash::make($temp!==''?$temp:bin2hex(random_bytes(16)));$attrs['must_change_password']=$temp!=='';$user=User::create($attrs);$created++;}}catch(\Throwable $e){$errors[]="Row {$rowNo}: {$e->getMessage()}";}}
        fclose($fh);$audit->log($request,'user.imported',null,'User CSV import completed.',['created'=>$created,'updated'=>$updated,'errors'=>count($errors)]);return response()->json(['message'=>"Import complete: {$created} created, {$updated} updated, ".count($errors).' error(s).','errors'=>array_slice($errors,0,25)]);
    }
}
