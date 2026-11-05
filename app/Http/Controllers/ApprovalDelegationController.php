<?php
namespace App\Http\Controllers;
use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
class ApprovalDelegationController extends Controller {
 public function store(Request $r,AuditService $audit){$user=$r->user();$d=$r->validate(['delegate_user_id'=>'required|exists:users,id','starts_at'=>'required|date','ends_at'=>'required|date|after:starts_at','reason'=>'nullable|string|max:1000']);$dept=$user->department_id;abort_unless($dept,422,__('messages.request.delegate_department'));$delegate=User::findOrFail($d['delegate_user_id']);abort_unless($delegate->department_id===$dept && ($delegate->status??'active')==='active',422,__('messages.request.delegate_active'));abort_if($delegate->id===$user->id,422,__('messages.request.delegate_other'));$row=ApprovalDelegation::create($d+['department_id'=>$dept,'delegator_user_id'=>$user->id,'is_active'=>true]);$audit->log($r,'approval-delegation.created',$row,__('messages.request.delegation_created'));return response()->json(['message'=>__('messages.request.delegation_created')]);}
 public function destroy(Request $r,ApprovalDelegation $approvalDelegation,AuditService $audit){abort_unless($r->user()->role==='super-admin'||$approvalDelegation->delegator_user_id===$r->user()->id,403);$approvalDelegation->update(['is_active'=>false]);$audit->log($r,'approval-delegation.disabled',$approvalDelegation,'Approval delegation disabled.');return response()->json(['message'=>__('messages.request.delegation_disabled')]);}
}
