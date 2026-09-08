<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\UatCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UatReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_change_uat_results(): void
    {
        $viewer=User::factory()->create(['role'=>'viewer']);
        $admin=User::factory()->create(['role'=>'super-admin']);
        $case=UatCase::firstOrFail();

        $this->actingAs($viewer)->patchJson('/settings/readiness/uat/'.$case->id,[
            'status'=>'wip','execution_notes'=>'Started in staging.','evidence_reference'=>'UAT-1',
        ])->assertForbidden();

        $this->actingAs($admin)->patchJson('/settings/readiness/uat/'.$case->id,[
            'status'=>'passed','execution_notes'=>'Executed successfully in staging.','evidence_reference'=>'UAT-1',
        ])->assertOk();
        $this->assertSame('passed',$case->fresh()->status);
        $this->assertSame(config('version.current'),$case->fresh()->executed_app_version);
        $this->assertNull($case->fresh()->approved_at);
    }

    public function test_passed_uat_requires_evidence_or_execution_notes(): void
    {
        $admin=User::factory()->create(['role'=>'super-admin']);
        $case=UatCase::firstOrFail();
        $this->actingAs($admin)->patchJson('/settings/readiness/uat/'.$case->id,[
            'status'=>'passed','execution_notes'=>'','evidence_reference'=>'',
        ])->assertStatus(422);
    }

    public function test_uat_signoff_requires_passed_case_and_resets_when_result_changes(): void
    {
        $admin=User::factory()->create(['role'=>'super-admin']);
        $case=UatCase::firstOrFail();
        $case->forceFill(['status'=>'passed','execution_notes'=>'Passed in staging','executed_by'=>$admin->id,'executed_at'=>now(),'executed_app_version'=>config('version.current')])->save();

        $this->actingAs($admin)->postJson('/settings/readiness/uat/'.$case->id.'/approve')->assertOk();
        $this->assertNotNull($case->fresh()->approved_at);
        $this->assertSame(config('version.current'),$case->fresh()->approved_app_version);

        $this->actingAs($admin)->patchJson('/settings/readiness/uat/'.$case->id,[
            'status'=>'wip','execution_notes'=>'Re-test required after change.','evidence_reference'=>'UAT-2',
        ])->assertOk();
        $this->assertNull($case->fresh()->approved_at);
    }


    public function test_uat_signoff_is_bound_to_current_application_release(): void
    {
        $admin=User::factory()->create(['role'=>'super-admin']);
        $case=UatCase::firstOrFail();
        $this->actingAs($admin)->patchJson('/settings/readiness/uat/'.$case->id,[
            'status'=>'passed','execution_notes'=>'Passed on stabilization build.','evidence_reference'=>'UAT-REL-1',
        ])->assertOk();
        $this->actingAs($admin)->postJson('/settings/readiness/uat/'.$case->id.'/approve')->assertOk();

        config(['version.current'=>'12.02']);
        $this->actingAs($admin)->postJson('/settings/readiness/uat/'.$case->id.'/approve')->assertStatus(422);
    }

    public function test_resolved_or_risk_accepted_management_dependency_requires_decision_note(): void
    {
        $admin=User::factory()->create(['role'=>'super-admin']);
        $deps=SystemSetting::valueFor('go_live.dependencies',[]);
        $deps[0]['status']='accepted-risk';
        $deps[0]['note']='';
        $this->actingAs($admin)->patchJson('/settings/readiness/dependencies',['dependencies'=>$deps])->assertStatus(422);
    }

    public function test_management_dependency_statuses_are_explicit_and_go_live_approval_refuses_blockers(): void
    {
        $admin=User::factory()->create(['role'=>'super-admin']);
        $deps=SystemSetting::valueFor('go_live.dependencies',[]);
        $deps=array_map(fn($d)=>array_merge($d,['status'=>'resolved','note'=>'Approved']),$deps);
        $this->actingAs($admin)->patchJson('/settings/readiness/dependencies',['dependencies'=>$deps])->assertOk();
        $this->assertSame(0,collect(SystemSetting::valueFor('go_live.dependencies',[]))->where('status','pending')->count());

        // UAT remains Pending, so the fresh server-side gate must refuse approval.
        $this->actingAs($admin)->postJson('/settings/readiness/review',['decision'=>'approved','notes'=>'Attempt before UAT complete'])->assertStatus(422);
    }
}
