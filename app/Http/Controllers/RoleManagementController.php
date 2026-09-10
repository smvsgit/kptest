<?php

namespace App\Http\Controllers;

use App\Models\PortalRole;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleManagementController extends Controller
{
    private const BASE_ROLES = ['super-admin','department-admin','department-operator','viewer'];
    private const PERMISSION_KEYS = ['upload','delete','manage_policy','manage_lifecycle','review_access','manage_categories'];
    private const PAGE_KEYS = ['browse','upload','access','reports','integrations','guide','settings'];

    public function store(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'name'=>'required|string|max:120|unique:portal_roles,name',
            'base_role'=>['required', Rule::in(self::BASE_ROLES)],
            'permissions'=>'nullable|array',
            'page_access'=>'required|array',
        ]);
        $slugBase = Str::slug($data['name']) ?: 'custom-role';
        $slug = $slugBase;
        $i = 2;
        while (PortalRole::where('slug',$slug)->exists()) $slug = $slugBase.'-'.$i++;

        $role = PortalRole::create([
            'name'=>$data['name'],
            'slug'=>$slug,
            'base_role'=>$data['base_role'],
            'is_builtin'=>false,
            'is_active'=>true,
            'permissions'=>$this->normalizePermissions($data['permissions'] ?? []),
            'page_access'=>$this->normalizePages($data['page_access'], $data['base_role']),
            'created_by'=>$request->user()->id,
        ]);
        $audit->log($request,'portal-role.created',$role,'Custom portal role created.',[
            'base_role'=>$role->base_role,'page_access'=>$role->page_access,'permissions'=>$role->permissions,
        ]);
        return response()->json(['message'=>'Role created successfully.','role'=>$role]);
    }

    public function update(Request $request, PortalRole $portalRole, AuditService $audit)
    {
        $data = $request->validate([
            'name'=>'required|string|max:120|unique:portal_roles,name,'.$portalRole->id,
            'base_role'=>['required', Rule::in(self::BASE_ROLES)],
            'permissions'=>'nullable|array',
            'page_access'=>'required|array',
            'is_active'=>'required|boolean',
        ]);
        if ($portalRole->is_builtin && $data['base_role'] !== $portalRole->base_role) {
            throw ValidationException::withMessages(['base_role'=>'Built-in role base type cannot be changed.']);
        }
        if ($portalRole->is_builtin && !$data['is_active']) {
            throw ValidationException::withMessages(['is_active'=>'Built-in roles cannot be disabled.']);
        }
        $before = $portalRole->toArray();
        $portalRole->update([
            'name'=>$data['name'],
            'base_role'=>$data['base_role'],
            'permissions'=>$this->normalizePermissions($data['permissions'] ?? []),
            'page_access'=>$this->normalizePages($data['page_access'], $data['base_role']),
            'is_active'=>$portalRole->is_builtin ? true : (bool)$data['is_active'],
        ]);
        $audit->log($request,'portal-role.updated',$portalRole,'Portal role updated.',[
            'before'=>$before,'after'=>$portalRole->fresh()->toArray(),
        ]);
        return response()->json(['message'=>'Role settings saved.','role'=>$portalRole->fresh()]);
    }

    public function destroy(Request $request, PortalRole $portalRole, AuditService $audit)
    {
        abort_if($portalRole->is_builtin, 422, 'Built-in roles cannot be deleted.');
        abort_if($portalRole->users()->exists(), 422, 'Reassign users before deleting this role.');
        abort_if($portalRole->groups()->exists(), 422, 'Reassign or delete groups before deleting this role.');
        $audit->log($request,'portal-role.deleted',$portalRole,'Custom portal role deleted.');
        $portalRole->delete();
        return response()->json(['message'=>'Role deleted.']);
    }

    public function assign(Request $request, User $user, AuditService $audit)
    {
        abort_if($request->user()->is($user), 422, 'You cannot change your own role assignment here.');
        $data = $request->validate(['portal_role_id'=>'required|integer|exists:portal_roles,id']);
        $role = PortalRole::whereKey($data['portal_role_id'])->where('is_active',true)->firstOrFail();
        $actor = $request->user();
        if ($actor->role === 'department-admin') {
            abort_if(!$actor->department_id || $user->department_id !== $actor->department_id, 403, 'You may manage users only in your own department.');
            abort_if(in_array($user->role, ['super-admin','department-admin'], true), 403, 'Department Admin cannot change administrator role assignments.');
            abort_if(!in_array($role->base_role, ['department-operator','viewer'], true), 403, 'Department Admin may assign only Operator/Viewer based roles.');
        }
        if ($role->base_role !== 'super-admin' && !$user->department_id) {
            throw ValidationException::withMessages(['portal_role_id'=>'Assign a department before using a department-scoped role.']);
        }
        $before = ['role'=>$user->role,'portal_role_id'=>$user->portal_role_id];
        $user->update(['role'=>$role->base_role,'portal_role_id'=>$role->id]);
        $audit->log($request,'user.portal-role.assigned',$user,'Portal role assigned to user.',[
            'before'=>$before,'after'=>['role'=>$role->base_role,'portal_role_id'=>$role->id,'portal_role'=>$role->name],
        ]);
        return response()->json(['message'=>'Role assigned successfully.']);
    }

    private function normalizePermissions(array $input): array
    {
        $out=[];
        foreach(self::PERMISSION_KEYS as $key) $out[$key]=(bool)($input[$key]??false);
        return $out;
    }

    private function normalizePages(array $input, string $baseRole): array
    {
        $out=[];
        foreach(self::PAGE_KEYS as $key) $out[$key]=(bool)($input[$key]??false);
        $out['browse']=true;
        if ($baseRole==='super-admin') $out['settings']=true;
        return $out;
    }
}
