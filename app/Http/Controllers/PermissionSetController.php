<?php
namespace App\Http\Controllers;
use App\Models\PermissionSet;
use App\Services\AuditService;
use Illuminate\Http\Request;
class PermissionSetController extends Controller {
 const KEYS=['upload','delete','manage_policy','manage_lifecycle','review_access','manage_categories'];
 public function store(Request $r,AuditService $audit){$d=$r->validate(['name'=>'required|string|max:120|unique:permission_sets,name','description'=>'nullable|string|max:500','permissions'=>'required|array']);$d['permissions']=array_intersect_key($d['permissions'],array_flip(self::KEYS));$p=PermissionSet::create($d+['is_active'=>true]);$audit->log($r,'permission-set.created',$p,'Permission set created.');return back()->with('success','Permission set created.');}
 public function update(Request $r,PermissionSet $permissionSet,AuditService $audit){$d=$r->validate(['name'=>'required|string|max:120|unique:permission_sets,name,'.$permissionSet->id,'description'=>'nullable|string|max:500','permissions'=>'required|array','is_active'=>'required|boolean']);$d['permissions']=array_intersect_key($d['permissions'],array_flip(self::KEYS));$before=$permissionSet->toArray();$permissionSet->update($d);$audit->log($r,'permission-set.updated',$permissionSet,'Permission set updated.',['before'=>$before,'after'=>$d]);return back()->with('success','Permission set updated.');}
 public function destroy(Request $r,PermissionSet $permissionSet,AuditService $audit){if($permissionSet->users()->exists())return back()->withErrors(['permission_set'=>'Unassign users before deleting.']);$audit->log($r,'permission-set.deleted',$permissionSet,'Permission set deleted.');$permissionSet->delete();return back()->with('success','Permission set deleted.');}
}
