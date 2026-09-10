<?php

namespace App\Http\Controllers;

use App\Models\PortalRole;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserGroupController extends Controller
{
    public function store(Request $request, AuditService $audit)
    {
        $actor=$request->user();
        $data=$request->validate([
            'name'=>'required|string|max:120',
            'description'=>'nullable|string|max:1000',
            'department_id'=>'nullable|integer|exists:departments,id',
            'portal_role_id'=>'nullable|integer|exists:portal_roles,id',
        ]);
        if($actor->role==='department-admin'){
            $data['department_id']=$actor->department_id;
            if(!empty($data['portal_role_id'])) $this->assertDepartmentAssignableRole((int)$data['portal_role_id']);
        }
        $exists=UserGroup::where('department_id',$data['department_id']??null)->where('name',$data['name'])->exists();
        if($exists) throw ValidationException::withMessages(['name'=>'A group with this name already exists in the selected department.']);
        $group=UserGroup::create($data+['is_active'=>true,'created_by'=>$actor->id]);
        $audit->log($request,'user-group.created',$group,'User group created.',$group->toArray());
        return response()->json(['message'=>'Group created.']);
    }

    public function update(Request $request, UserGroup $userGroup, AuditService $audit)
    {
        $this->authorizeScope($request,$userGroup);
        $actor=$request->user();
        $data=$request->validate([
            'name'=>'required|string|max:120',
            'description'=>'nullable|string|max:1000',
            'portal_role_id'=>'nullable|integer|exists:portal_roles,id',
            'is_active'=>'required|boolean',
        ]);
        if($actor->role==='department-admin' && !empty($data['portal_role_id'])) $this->assertDepartmentAssignableRole((int)$data['portal_role_id']);
        $before=$userGroup->toArray();
        $userGroup->update($data);
        $audit->log($request,'user-group.updated',$userGroup,'User group updated.',['before'=>$before,'after'=>$data]);
        return response()->json(['message'=>'Group updated.']);
    }

    public function addMember(Request $request, UserGroup $userGroup, AuditService $audit)
    {
        $this->authorizeScope($request,$userGroup);
        $data=$request->validate(['user_id'=>'required|integer|exists:users,id','apply_group_role'=>'nullable|boolean']);
        $user=User::findOrFail($data['user_id']);
        if($userGroup->department_id && (int)$user->department_id !== (int)$userGroup->department_id) {
            throw ValidationException::withMessages(['user_id'=>'User must belong to the same department as this group.']);
        }
        if($request->user()->role==='department-admin') {
            abort_unless((int)$user->department_id === (int)$request->user()->department_id, 403);
            abort_if(in_array($user->role, ['super-admin','department-admin'], true), 403, 'Department Admin cannot manage administrator accounts through groups.');
        }
        $userGroup->members()->syncWithoutDetaching([$user->id=>['added_by'=>$request->user()->id]]);

        if(($data['apply_group_role']??true) && $userGroup->portal_role_id){
            $role=PortalRole::whereKey($userGroup->portal_role_id)->where('is_active',true)->first();
            if($role){
                if($request->user()->role==='department-admin') $this->assertDepartmentAssignableRole($role->id);
                $user->update(['role'=>$role->base_role,'portal_role_id'=>$role->id]);
            }
        }
        $audit->log($request,'user-group.member-added',$userGroup,'User added to group.',[
            'user_id'=>$user->id,'user_email'=>$user->email,'group_role_applied'=>(bool)($data['apply_group_role']??true),
        ]);
        return response()->json(['message'=>'User added to group.']);
    }

    public function removeMember(Request $request, UserGroup $userGroup, User $user, AuditService $audit)
    {
        $this->authorizeScope($request,$userGroup);
        if ($request->user()->role === 'department-admin') {
            abort_if(in_array($user->role, ['super-admin','department-admin'], true), 403, 'Department Admin cannot manage administrator accounts through groups.');
        }
        $userGroup->members()->detach($user->id);
        $audit->log($request,'user-group.member-removed',$userGroup,'User removed from group.',['user_id'=>$user->id]);
        return response()->json(['message'=>'User removed from group.']);
    }

    public function applyRole(Request $request, UserGroup $userGroup, AuditService $audit)
    {
        $this->authorizeScope($request,$userGroup);
        abort_unless($userGroup->portal_role_id, 422, 'Assign a role to the group first.');
        $role=PortalRole::whereKey($userGroup->portal_role_id)->where('is_active',true)->firstOrFail();
        if($request->user()->role==='department-admin') {
            $this->assertDepartmentAssignableRole($role->id);
            abort_if(
                $userGroup->members()->whereIn('users.role', ['super-admin','department-admin'])->exists(),
                403,
                'Remove administrator accounts from this group before applying a group role.'
            );
        }
        $count=0;
        $members = $userGroup->members()->where('users.status','active')->get();
        foreach ($members as $user) {
            $user->update(['role'=>$role->base_role,'portal_role_id'=>$role->id]);
            $count++;
        }
        $audit->log($request,'user-group.role-applied',$userGroup,'Group role applied to active members.',['portal_role_id'=>$role->id,'count'=>$count]);
        return response()->json(['message'=>"Role applied to {$count} active group member(s)."]);
    }

    public function destroy(Request $request, UserGroup $userGroup, AuditService $audit)
    {
        $this->authorizeScope($request,$userGroup);
        $audit->log($request,'user-group.deleted',$userGroup,'User group deleted.');
        $userGroup->delete();
        return response()->json(['message'=>'Group deleted.']);
    }

    private function authorizeScope(Request $request, UserGroup $group): void
    {
        $actor=$request->user();
        abort_unless(in_array($actor->role,['super-admin','department-admin'],true),403);
        if($actor->role==='department-admin') abort_unless((int)$group->department_id===(int)$actor->department_id,403);
    }

    private function assertDepartmentAssignableRole(int $roleId): void
    {
        $role=PortalRole::whereKey($roleId)->where('is_active',true)->firstOrFail();
        if(in_array($role->base_role,['super-admin','department-admin'],true)) {
            throw ValidationException::withMessages(['portal_role_id'=>'Department Admin can assign only Department Operator/Viewer based roles.']);
        }
    }
}
