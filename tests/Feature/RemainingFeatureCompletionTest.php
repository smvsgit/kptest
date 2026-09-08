<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\NetworkPolicyService;
use App\Services\TwoFactorService;
use App\Services\XlsxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RemainingFeatureCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_service_generates_and_verifies_current_code(): void
    {
        $service=app(TwoFactorService::class); $secret=$service->generateSecret();
        $this->assertTrue($service->verify($secret,$service->code($secret,(int)floor(time()/30))));
    }

    public function test_network_policy_accepts_allowed_cidr(): void
    {
        SystemSetting::put('security.network',['enabled'=>true,'allowed_cidrs'=>['10.20.0.0/16'],'trusted_vpn_proxies'=>[],'emergency_super_admin_email'=>'']);
        $request=Request::create('/login','POST',server:['REMOTE_ADDR'=>'10.20.4.8']);
        $this->assertTrue(app(NetworkPolicyService::class)->allows($request));
    }

    public function test_xlsx_round_trip_obeys_simple_sheet_contract(): void
    {
        $file=tempnam(sys_get_temp_dir(),'kp-xlsx-').'.xlsx';
        app(XlsxService::class)->write(['Name','Email'],[['Test User','test@example.com']],$file);
        $rows=app(XlsxService::class)->read($file,100,10);
        @unlink($file);
        $this->assertSame(['Name','Email'],$rows[0]);
        $this->assertSame(['Test User','test@example.com'],$rows[1]);
    }

    public function test_policy_pending_defaults_do_not_auto_delete(): void
    {
        $this->assertSame(0,(int)(SystemSetting::valueFor('audit.retention',['retention_days'=>0])['retention_days']??0));
        $this->assertSame(0,(int)(SystemSetting::valueFor('recycle.retention',['retention_days'=>0])['retention_days']??0));
    }
}
