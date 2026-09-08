<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotificationChannel;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\MediaAccessRequest;
use App\Models\MediaFile;
use App\Models\NotificationDelivery;
use App\Models\PortalNotification;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AccessEscalationService;
use App\Services\AuditIntegrityService;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class NotificationAuditReportsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $deptA=Department::create(['name'=>'Audio Video','is_active'=>true,'is_system'=>false]);
        $deptB=Department::create(['name'=>'Website','is_active'=>true,'is_system'=>false]);
        $admin=User::factory()->create(['role'=>'department-admin','department_id'=>$deptA->id,'email'=>'admin-a@example.test','phone'=>'+910000000001']);
        $user=User::factory()->create(['role'=>'viewer','department_id'=>$deptB->id,'email'=>'viewer@example.test','phone'=>'+910000000002']);
        $operator=User::factory()->create(['role'=>'department-operator','department_id'=>$deptA->id,'email'=>'operator@example.test']);
        $super=User::factory()->create(['role'=>'super-admin','email'=>'super@example.test']);
        $media=MediaFile::create(['name'=>'Protected.mp4','type'=>'video','size'=>100,'department_id'=>$deptA->id,'access_policy'=>'protected','download_allowed'=>true,'tags'=>[],'uploaded_by'=>$operator->id]);
        return compact('deptA','deptB','admin','user','operator','super','media');
    }

    private function notificationSettings(): array
    {
        return [
            'portal'=>['enabled'=>true],
            'email'=>['enabled'=>true,'provider'=>'smtp','host'=>'smtp.example.test','port'=>587,'encryption'=>'tls','username'=>'','password_set'=>false,'from_address'=>'portal@example.test','from_name'=>'Portal'],
            'whatsapp'=>['enabled'=>false,'provider'=>'generic-http','endpoint'=>'','token_set'=>false],
            'sms'=>['enabled'=>false,'provider'=>'generic-http','endpoint'=>'','api_key_set'=>false],
            'events'=>[
                'access_request_created'=>['portal'=>true,'email'=>true,'whatsapp'=>false,'sms'=>false],
                'access_request_decided'=>['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
                'access_request_escalated'=>['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
            ],
        ];
    }

    public function test_access_request_delivers_portal_and_queues_enabled_email(): void
    {
        $f=$this->fixture();Queue::fake();SystemSetting::put('notification.channels',$this->notificationSettings());
        $this->actingAs($f['user'])->postJson('/files/'.$f['media']->id.'/access-request',['reason'=>'Need media for approved work','access_level'=>'view'])->assertCreated();
        $this->assertDatabaseHas('portal_notifications',['user_id'=>$f['admin']->id,'type'=>'access_request_created']);
        $this->assertDatabaseHas('notification_deliveries',['user_id'=>$f['admin']->id,'channel'=>'portal','status'=>'sent']);
        $this->assertDatabaseHas('notification_deliveries',['user_id'=>$f['admin']->id,'channel'=>'email','status'=>'queued']);
        Queue::assertPushed(DeliverNotificationChannel::class);
    }

    public function test_user_can_save_notification_preferences(): void
    {
        $f=$this->fixture();
        $payload=['portal_enabled'=>true,'email_enabled'=>false,'whatsapp_enabled'=>false,'sms_enabled'=>false,'access_enabled'=>true,'file_enabled'=>false,'storage_enabled'=>true,'security_enabled'=>true];
        $this->actingAs($f['user'])->patchJson('/notifications/preferences',$payload)->assertOk();
        $this->assertDatabaseHas('notification_preferences',['user_id'=>$f['user']->id,'email_enabled'=>0,'file_enabled'=>0]);
    }

    public function test_audit_chain_verifies_and_model_is_append_only(): void
    {
        $f=$this->fixture();$service=app(AuditService::class);
        $request=Request::create('/test','POST');$request->setUserResolver(fn()=>$f['admin']);
        $one=$service->log($request,'test.one',null,'One',['value'=>1]);
        $service->log($request,'test.two',null,'Two',['value'=>2]);
        $this->assertTrue(app(AuditIntegrityService::class)->verify()['valid']);
        $this->expectException(LogicException::class);$one->update(['description'=>'tampered']);
    }

    public function test_overdue_access_request_escalates_using_configured_event(): void
    {
        $f=$this->fixture();SystemSetting::put('notification.channels',$this->notificationSettings());SystemSetting::put('notification.escalation',['enabled'=>true,'first_after_hours'=>1,'repeat_every_hours'=>1,'max_escalations'=>2]);
        $req=MediaAccessRequest::create(['media_file_id'=>$f['media']->id,'user_id'=>$f['user']->id,'access_level'=>'view','reason'=>'Need access','status'=>'pending']);
        $req->forceFill(['created_at'=>now()->subHours(3)])->saveQuietly();
        $result=app(AccessEscalationService::class)->run();
        $this->assertSame(1,$result['escalated']);
        $this->assertDatabaseHas('portal_notifications',['user_id'=>$f['admin']->id,'type'=>'access_request_escalated']);
        $this->assertSame(1,$req->fresh()->escalation_count);
    }

    public function test_department_admin_activity_report_is_department_scoped(): void
    {
        $f=$this->fixture();$audit=app(AuditService::class);
        foreach([[$f['admin'],'dept.event'],[$f['operator'],'operator.event'],[$f['user'],'other.event']] as [$actor,$event]){$r=Request::create('/x','POST');$r->setUserResolver(fn()=>$actor);$audit->log($r,$event,null,'Event');}
        $response=$this->actingAs($f['admin'])->getJson('/reports/activity')->assertOk();
        $events=collect($response->json('rows'))->pluck('event');
        $this->assertTrue($events->contains('dept.event'));$this->assertTrue($events->contains('operator.event'));$this->assertFalse($events->contains('other.event'));
    }

    public function test_super_admin_can_verify_audit_integrity(): void
    {
        $f=$this->fixture();$audit=app(AuditService::class);$r=Request::create('/x','POST');$r->setUserResolver(fn()=>$f['super']);$audit->log($r,'test.integrity',null,'Integrity seed');
        $this->actingAs($f['super'])->postJson('/reports/audit/verify')->assertOk()->assertJson(['valid'=>true]);
        $this->assertDatabaseHas('audit_logs',['event'=>'audit.integrity-verified']);
    }
}
