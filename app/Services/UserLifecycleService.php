<?php
namespace App\Services;
use App\Models\User;
use App\Models\MediaAccessRequest;
use App\Models\UserStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class UserLifecycleService {
 public function changeStatus(User $user,string $status,?string $reason,User $actor,?int $successorId=null): void {
  if($user->id===$actor->id) throw ValidationException::withMessages(['status'=>'You cannot change your own lifecycle status.']);
  if(!in_array($status,['active','disabled','inactive'],true)) throw ValidationException::withMessages(['status'=>'Invalid lifecycle status.']);
  DB::transaction(function()use($user,$status,$reason,$actor,$successorId){
   $before=$user->status??'active';
   if($status!=='active'){
    $owned=$user->ownedFiles()->count();
    if($owned>0){
      $successor=$successorId?User::whereKey($successorId)->where('status','active')->first():null;
      if(!$successor || $successor->department_id!==$user->department_id) throw ValidationException::withMessages(['successor_user_id'=>'Choose an active successor in the same department before disabling/inactivating this user.']);
      $user->ownedFiles()->update(['owner_user_id'=>$successor->id]);
    }
    MediaAccessRequest::where('user_id',$user->id)->where('status','approved')->update(['status'=>'revoked','revoked_at'=>now(),'revoke_reason'=>'User '.$status.': '.($reason?:'administrative lifecycle change')]);
    MediaAccessRequest::where('user_id',$user->id)->whereIn('status',['pending','more-info'])->update(['status'=>'cancelled','revoke_reason'=>'User '.$status.': '.($reason?:'administrative lifecycle change')]);
    DB::table('sessions')->where('user_id',$user->id)->delete();
   }
   $user->forceFill(['status'=>$status,'status_reason'=>$reason,'status_changed_at'=>now(),'status_changed_by'=>$actor->id,'locked_until'=>null,'failed_login_count'=>0])->save();
   UserStatusHistory::create(['user_id'=>$user->id,'from_status'=>$before,'to_status'=>$status,'reason'=>$reason,'changed_by'=>$actor->id,'context'=>['successor_user_id'=>$successorId]]);
  });
 }
 public function transferDepartment(User $user,?int $departmentId,?int $orgUnitId,User $actor,?int $successorId=null): void {
  DB::transaction(function()use($user,$departmentId,$orgUnitId,$actor,$successorId){
   $old=$user->department_id;
   if($old!==$departmentId && $user->ownedFiles()->exists()){
    $successor=$successorId?User::whereKey($successorId)->where('status','active')->first():null;
    if(!$successor || $successor->department_id!==$old) throw ValidationException::withMessages(['successor_user_id'=>'Choose an active successor in the old department for owned assets before transfer.']);
    $user->ownedFiles()->update(['owner_user_id'=>$successor->id]);
   }
   if($old!==$departmentId){
    MediaAccessRequest::where('user_id',$user->id)->where('status','approved')->update(['status'=>'revoked','revoked_at'=>now(),'revoke_reason'=>'Department transfer']);
    MediaAccessRequest::where('user_id',$user->id)->whereIn('status',['pending','more-info'])->update(['status'=>'cancelled','revoke_reason'=>'Department transfer']);
   }
   $user->update(['department_id'=>$departmentId,'organization_unit_id'=>$orgUnitId]);
  });
 }
 public function temporaryPassword(User $user): string { $password=Str::password(16,true,true,true,false); $user->update(['password'=>Hash::make($password),'must_change_password'=>true]); DB::table('sessions')->where('user_id',$user->id)->delete(); return $password; }
}
