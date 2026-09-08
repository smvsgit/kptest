<?php
namespace Tests\Feature;
use App\Models\ApprovalDelegation;
use App\Models\Department;
use App\Models\MediaAccessRequest;
use App\Models\MediaFile;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\AccessExpiryService;
use App\Services\MediaAccessService;
use App\Services\UserLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class OrganizationAccessMaturityTest extends TestCase { use RefreshDatabase;
 private function fixture():array{$a=Department::create(['name'=>'AV','is_active'=>true,'is_system'=>false]);$b=Department::create(['name'=>'Web','is_active'=>true,'is_system'=>false]);$super=User::factory()->create(['role'=>'super-admin','status'=>'active']);$admin=User::factory()->create(['role'=>'department-admin','department_id'=>$a->id,'status'=>'active']);$user=User::factory()->create(['role'=>'viewer','department_id'=>$a->id,'status'=>'active']);$successor=User::factory()->create(['role'=>'viewer','department_id'=>$a->id,'status'=>'active']);$file=MediaFile::create(['name'=>'protected.pdf','type'=>'document','size'=>10,'department_id'=>$a->id,'access_policy'=>'protected','download_allowed'=>true,'tags'=>[],'uploaded_by'=>$user->id,'owner_user_id'=>$user->id]);return compact('a','b','super','admin','user','successor','file');}
 public function test_org_hierarchy_supports_sub_department_and_team():void{$f=$this->fixture();$sub=OrganizationUnit::create(['department_id'=>$f['a']->id,'type'=>'sub-department','name'=>'Editing','is_active'=>true]);$team=OrganizationUnit::create(['department_id'=>$f['a']->id,'parent_id'=>$sub->id,'type'=>'team','name'=>'Video','is_active'=>true]);$this->assertSame($sub->id,$team->parent_id);}
 public function test_disabling_user_requires_successor_for_owned_assets_and_revokes_access():void{$f=$this->fixture();$req=MediaAccessRequest::create(['media_file_id'=>$f['file']->id,'user_id'=>$f['user']->id,'reason'=>'work','access_level'=>'view','status'=>'approved']);app(UserLifecycleService::class)->changeStatus($f['user'],'disabled','left team',$f['super'],$f['successor']->id);$this->assertSame('disabled',$f['user']->fresh()->status);$this->assertSame($f['successor']->id,$f['file']->fresh()->owner_user_id);$this->assertSame('revoked',$req->fresh()->status);}
 public function test_department_transfer_revokes_old_entitlements():void{$f=$this->fixture();$req=MediaAccessRequest::create(['media_file_id'=>$f['file']->id,'user_id'=>$f['user']->id,'reason'=>'work','access_level'=>'view','status'=>'approved']);app(UserLifecycleService::class)->transferDepartment($f['user'],$f['b']->id,null,$f['super'],$f['successor']->id);$this->assertSame($f['b']->id,$f['user']->fresh()->department_id);$this->assertSame('revoked',$req->fresh()->status);}
 public function test_temporary_approval_expires_and_access_service_stops_preview():void{$f=$this->fixture();$req=MediaAccessRequest::create(['media_file_id'=>$f['file']->id,'user_id'=>$f['user']->id,'reason'=>'work','access_level'=>'view','status'=>'approved','expires_at'=>now()->subMinute()]);$this->assertFalse(app(MediaAccessService::class)->canPreview($f['file'],$f['user']));app(AccessExpiryService::class)->run();$this->assertSame('expired',$req->fresh()->status);}
 public function test_active_delegation_allows_department_request_review():void{$f=$this->fixture();$delegate=User::factory()->create(['role'=>'viewer','department_id'=>$f['a']->id,'status'=>'active']);$req=MediaAccessRequest::create(['media_file_id'=>$f['file']->id,'user_id'=>$f['user']->id,'reason'=>'work','access_level'=>'view','status'=>'pending']);ApprovalDelegation::create(['department_id'=>$f['a']->id,'delegator_user_id'=>$f['admin']->id,'delegate_user_id'=>$delegate->id,'starts_at'=>now()->subMinute(),'ends_at'=>now()->addHour(),'is_active'=>true]);$req->load('mediaFile');$this->assertTrue(app(MediaAccessService::class)->canReviewRequest($req,$delegate));}
}
