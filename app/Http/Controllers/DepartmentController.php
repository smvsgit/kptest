<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\MediaFile;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function store(Request $request, AuditService $audit)
    {
        $data=$request->validate(['name'=>['required','string','max:120','unique:departments,name']]);
        $department=Department::create(['name'=>trim($data['name']),'is_active'=>true,'is_system'=>false]);
        $audit->log($request,'department.created',$department,'Department created.',['name'=>$department->name]);
        return back()->with('success',__('messages.department.created'));
    }
    public function update(Request $request, Department $department, AuditService $audit)
    {
        if($department->is_system)return back()->withErrors(['department'=>__('messages.department.system_no_rename')]);
        $data=$request->validate(['name'=>['required','string','max:120',Rule::unique('departments','name')->ignore($department->id)],'is_active'=>'sometimes|boolean']);
        $before=$department->only(['name','is_active']);$department->update(['name'=>trim($data['name']),'is_active'=>array_key_exists('is_active',$data)?(bool)$data['is_active']:$department->is_active]);
        $audit->log($request,'department.updated',$department,'Department updated.',['before'=>$before,'after'=>$department->only(['name','is_active'])]);
        return back()->with('success',__('messages.department.updated'));
    }
    public function destroy(Request $request, Department $department, AuditService $audit)
    {
        if($department->is_system)return back()->withErrors(['department'=>__('messages.department.system_no_delete')]);
        if($department->users()->exists())return back()->withErrors(['department'=>__('messages.department.has_users')]);
        if(MediaFile::withTrashed()->where('department_id',$department->id)->exists())return back()->withErrors(['department'=>__('messages.department.has_media')]);
        if($department->organizationUnits()->exists())return back()->withErrors(['department'=>__('messages.department.has_units')]);
        $audit->log($request,'department.deleted',$department,'Department deleted.',['name'=>$department->name]);
        $department->delete();
        return back()->with('success',__('messages.department.deleted'));
    }
}
