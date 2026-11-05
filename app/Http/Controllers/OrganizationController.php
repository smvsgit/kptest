<?php
namespace App\Http\Controllers;
use App\Models\OrganizationUnit;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class OrganizationController extends Controller {
 public function store(Request $r,AuditService $audit){$d=$r->validate(['department_id'=>'required|exists:departments,id','parent_id'=>'nullable|exists:organization_units,id','type'=>['required',Rule::in(['sub-department','team'])],'name'=>'required|string|max:120']); if($d['type']==='team'&&!$d['parent_id'])return back()->withErrors(['parent_id'=>__('messages.unit.team_parent')]); if($d['parent_id']){ $p=OrganizationUnit::findOrFail($d['parent_id']); abort_if($p->department_id!=(int)$d['department_id'],422,'Parent belongs to another department.'); abort_if($d['type']==='sub-department',422,'Sub-department cannot have a parent.'); abort_if($p->type!=='sub-department',422,'Team parent must be a Sub-department.'); } $u=OrganizationUnit::create($d+['is_active'=>true]);$audit->log($r,'organization-unit.created',$u,__('messages.unit.created'));return back()->with('success',__('messages.unit.created'));}
 public function update(Request $r,OrganizationUnit $organizationUnit,AuditService $audit){$d=$r->validate(['name'=>'required|string|max:120','is_active'=>'required|boolean']);$before=$organizationUnit->only(['name','is_active']);$organizationUnit->update($d);$audit->log($r,'organization-unit.updated',$organizationUnit,__('messages.unit.updated'),['before'=>$before,'after'=>$d]);return back()->with('success',__('messages.unit.updated'));}
 public function destroy(Request $r,OrganizationUnit $organizationUnit,AuditService $audit){if($organizationUnit->users()->exists()||$organizationUnit->children()->exists())return back()->withErrors(['organization_unit'=>__('messages.unit.has_children')]);$audit->log($r,'organization-unit.deleted',$organizationUnit,__('messages.unit.deleted'));$organizationUnit->delete();return back()->with('success',__('messages.unit.deleted'));}
}
