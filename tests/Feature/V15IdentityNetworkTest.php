<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\PortalRole;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\NetworkPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class V15IdentityNetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_user_exception_is_time_bounded_and_internal_network_still_wins(): void
    {
        SystemSetting::put('security.network', [
            'enabled'=>true,
            'allowed_cidrs'=>['10.20.0.0/16'],
            'trusted_vpn_proxies'=>['10.99.0.0/16'],
            'emergency_super_admin_email'=>'',
            'department_admin_can_manage_external_access'=>false,
        ]);
        $user=User::factory()->create([
            'role'=>'viewer','status'=>'active','external_access_allowed'=>true,
            'external_access_starts_at'=>now()->subMinute(),'external_access_expires_at'=>now()->addHour(),
        ]);
        $service=app(NetworkPolicyService::class);

        $external=Request::create('/','GET',[],[],[],['REMOTE_ADDR'=>'203.0.113.55']);
        $this->assertSame(NetworkPolicyService::SOURCE_EXTERNAL,$service->accessSource($external,$user));

        $internal=Request::create('/','GET',[],[],[],['REMOTE_ADDR'=>'10.20.4.7']);
        $this->assertSame(NetworkPolicyService::SOURCE_INTERNAL,$service->accessSource($internal,$user));

        $user->forceFill(['external_access_expires_at'=>now()->subMinute()])->save();
        $this->assertSame(NetworkPolicyService::SOURCE_BLOCKED,$service->accessSource($external,$user->fresh()));
    }

    public function test_portal_role_page_access_is_server_enforced_for_reports(): void
    {
        $viewerRole=PortalRole::where('slug','viewer')->firstOrFail();
        $viewerRole->update(['page_access'=>array_replace($viewerRole->page_access ?? [],['reports'=>false])]);
        $user=User::factory()->create(['role'=>'viewer','status'=>'active','portal_role_id'=>$viewerRole->id]);

        $this->assertFalse($user->fresh()->canAccessPage('reports'));
        $this->actingAs($user)->getJson('/reports/summary')->assertForbidden();
    }

    public function test_department_admin_cannot_assign_admin_based_custom_role(): void
    {
        $department=Department::create(['name'=>'Dept A','is_active'=>true,'is_system'=>false]);
        $adminRole=PortalRole::where('slug','department-admin')->firstOrFail();
        $viewerRole=PortalRole::where('slug','viewer')->firstOrFail();
        $actor=User::factory()->create(['role'=>'department-admin','status'=>'active','department_id'=>$department->id,'portal_role_id'=>$adminRole->id]);
        $target=User::factory()->create(['role'=>'viewer','status'=>'active','department_id'=>$department->id,'portal_role_id'=>$viewerRole->id]);
        $custom=PortalRole::create([
            'name'=>'Restricted Department Admin','slug'=>'restricted-department-admin','base_role'=>'department-admin',
            'is_builtin'=>false,'is_active'=>true,'permissions'=>[],'page_access'=>['browse'=>true,'settings'=>true],
            'created_by'=>$actor->id,
        ]);

        $this->actingAs($actor)->patchJson('/users/'.$target->id.'/portal-role',['portal_role_id'=>$custom->id])->assertForbidden();
    }
    public function test_department_admin_cannot_put_administrator_accounts_into_role_applying_groups(): void
    {
        $department=Department::create(['name'=>'Dept B','is_active'=>true,'is_system'=>false]);
        $adminRole=PortalRole::where('slug','department-admin')->firstOrFail();
        $viewerRole=PortalRole::where('slug','viewer')->firstOrFail();
        $actor=User::factory()->create(['role'=>'department-admin','status'=>'active','department_id'=>$department->id,'portal_role_id'=>$adminRole->id]);
        $peer=User::factory()->create(['role'=>'department-admin','status'=>'active','department_id'=>$department->id,'portal_role_id'=>$adminRole->id]);
        $group=\App\Models\UserGroup::create(['department_id'=>$department->id,'name'=>'Operators','portal_role_id'=>$viewerRole->id,'is_active'=>true,'created_by'=>$actor->id]);

        $this->actingAs($actor)->postJson('/user-groups/'.$group->id.'/members',['user_id'=>$peer->id,'apply_group_role'=>true])->assertForbidden();
    }

    public function test_dashboard_does_not_preload_hidden_page_administration_data(): void
    {
        $department=Department::create(['name'=>'Dept C','is_active'=>true,'is_system'=>false]);
        $base=PortalRole::where('slug','department-admin')->firstOrFail();
        $pages=$base->page_access ?? [];
        $pages['access']=false;
        $pages['integrations']=false;
        $pages['guide']=false;
        $pages['settings']=false;
        $base->update(['page_access'=>$pages]);
        $actor=User::factory()->create(['role'=>'department-admin','status'=>'active','department_id'=>$department->id,'portal_role_id'=>$base->id]);
        User::factory()->create(['role'=>'viewer','status'=>'active','department_id'=>$department->id]);

        $response=$this->actingAs($actor)->get('/');
        $response->assertOk();
        $response->assertInertia(fn($page)=>$page
            ->where('pageAccess.access',false)
            ->where('pageAccess.integrations',false)
            ->where('pageAccess.guide',false)
            ->where('pageAccess.settings',false)
            ->where('users',[])
            ->where('organizationUnits',[])
            ->where('approvalDelegations',[])
            ->where('portalRoles',[])
            ->where('userGroups',[])
            ->where('integrationHealthSummary.healthy',0)
            ->where('userGuideAccess.can_view',false)
            ->where('userGuideAccess.allowed_pages',0)
        );
    }

}
