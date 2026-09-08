<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\RestoreVerification;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupDisasterRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Storage::fake('local');
    }

    public function test_only_super_admin_can_change_backup_settings_and_create_backup(): void
    {
        $super=User::factory()->create(['role'=>'super-admin']);
        $viewer=User::factory()->create(['role'=>'viewer']);
        $settings=$this->payload();
        $this->actingAs($viewer)->patch('/settings/backups',$settings)->assertForbidden();
        $this->actingAs($super)->patch('/settings/backups',$settings)->assertSessionHasNoErrors();
        $this->actingAs($viewer)->post('/settings/backups/create')->assertForbidden();
        $this->actingAs($super)->post('/settings/backups/create')->assertSessionHasNoErrors();
        $run=BackupRun::latest('id')->firstOrFail();
        $this->assertSame('success',$run->status);
        $this->assertNotNull($run->verified_at);
        Storage::disk('local')->assertExists($run->storage_path);
        $this->assertDatabaseHas('restore_verifications',['backup_run_id'=>$run->id,'verification_type'=>'checksum','status'=>'passed']);
    }

    public function test_backup_is_encrypted_and_can_restore_a_fresh_migrated_database(): void
    {
        $super=User::factory()->create(['role'=>'super-admin','email'=>'dr-test@example.test']);
        SystemSetting::put('example.restore',['value'=>'preserved']);
        $run=app(BackupService::class)->createBackup($super,'manual','manual');
        $raw=Storage::disk('local')->get($run->storage_path);
        $this->assertStringNotContainsString('dr-test@example.test',$raw);
        $this->assertStringNotContainsString('example.restore',$raw);

        // Simulate a fresh migrated target: business/user/audit rows are empty;
        // migration defaults may remain and will be safely replaced by the restore.
        DB::table('audit_logs')->delete();
        DB::table('users')->delete();
        $result=app(BackupService::class)->restoreFileToFreshDatabase($run->storage_path);
        $this->assertSame('passed',$result['status']);
        $this->assertDatabaseHas('users',['email'=>'dr-test@example.test']);
        $this->assertSame(['value'=>'preserved'],SystemSetting::valueFor('example.restore'));
    }

    public function test_zero_rpo_rto_and_retention_are_policy_pending_not_invented_defaults(): void
    {
        $summary=app(BackupService::class)->statusSummary();
        $this->assertSame('policy-pending',$summary['readiness']);
        $this->assertTrue($summary['retention_policy_pending']);
        $this->assertSame(0,$summary['rpo_target_minutes']);
        $this->assertSame(0,$summary['rto_target_minutes']);
    }


    public function test_passed_restore_evidence_requires_a_verified_backup(): void
    {
        $super=User::factory()->create(['role'=>'super-admin']);
        $run=BackupRun::create([
            'trigger'=>'manual','retention_class'=>'manual','status'=>'success','destination_type'=>'local',
            'storage_path'=>'backups/unverified.kpbackup','filename'=>'unverified.kpbackup','sha256'=>str_repeat('a',64),
            'encrypted'=>true,'format_version'=>BackupService::FORMAT,'started_at'=>now(),'completed_at'=>now(),
        ]);

        $this->actingAs($super)->post('/settings/backups/'.$run->id.'/restore-verification',[
            'status'=>'passed','duration_minutes'=>30,'target_environment'=>'Staging DR','notes'=>'Should be refused because checksum verification is absent.',
        ])->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('restore_verifications',['backup_run_id'=>$run->id,'verification_type'=>'restore-test','status'=>'passed']);
    }

    public function test_restore_test_record_supports_rto_readiness_monitoring(): void
    {
        $super=User::factory()->create(['role'=>'super-admin']);
        $settings=$this->payload(); $settings['rpo_minutes']=1440; $settings['rto_minutes']=120;
        app(BackupService::class)->saveSettings($settings);
        $run=app(BackupService::class)->createBackup($super,'manual','manual');
        app(BackupService::class)->recordRestoreTest($run,$super,'passed',45,'Staging DR',$notes='Verified counts and login smoke test.');
        $summary=app(BackupService::class)->statusSummary();
        $this->assertSame('ready',$summary['readiness']);
        $this->assertTrue($summary['rpo_met']);
        $this->assertTrue($summary['rto_met']);
    }

    private function payload(): array
    {
        return [
            'destination_type'=>'local','filesystem_path'=>'','responsible_owner'=>'IT',
            'daily_enabled'=>false,'daily_hour'=>2,'weekly_enabled'=>false,'weekly_day'=>0,'weekly_hour'=>3,'monthly_enabled'=>false,'monthly_day'=>1,'monthly_hour'=>4,
            'daily_retention_days'=>0,'weekly_retention_days'=>0,'monthly_retention_days'=>0,'rpo_minutes'=>0,'rto_minutes'=>0,
            'backup_database'=>true,'backup_configuration'=>true,'backup_audit_security'=>true,
            'external_source_responsibility'=>'External source-system owners retain responsibility for NAS/Drive/YouTube content backup.',
        ];
    }
}
