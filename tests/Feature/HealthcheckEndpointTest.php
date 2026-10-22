<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UAT-002: the container healthcheck. Both the Dockerfile HEALTHCHECK and the
 * Compose healthcheck curl http://127.0.0.1/up, so that endpoint has to answer
 * successfully under every runtime condition the portal can be put into —
 * otherwise the orchestrator marks the container unhealthy and restarts it.
 */
class HealthcheckEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_uat_002_up_endpoint_succeeds_for_an_anonymous_request(): void
    {
        $this->get('/up')->assertSuccessful();
    }

    public function test_uat_002_up_endpoint_does_not_require_authentication(): void
    {
        $this->get('/up')->assertSuccessful()->assertDontSee('login', false);
        $this->assertGuest();
    }

    /** Maintenance mode must not take the container down with it. */
    public function test_uat_002_up_endpoint_still_succeeds_while_maintenance_mode_is_enabled(): void
    {
        SystemSetting::put('maintenance.settings', [
            'enabled' => true,
            'message' => 'Portal maintenance in progress.',
            'allow_super_admin_bypass' => true,
        ]);

        $this->get('/up')->assertSuccessful();
    }

    /** The healthcheck runs from inside the container, outside any allowed CIDR. */
    public function test_uat_002_up_endpoint_still_succeeds_while_network_policy_is_enabled(): void
    {
        SystemSetting::put('security.network', [
            'enabled' => true,
            'allowed_cidrs' => ['10.20.0.0/16'],
            'trusted_vpn_proxies' => [],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/up')->assertSuccessful();
    }

    public function test_uat_002_up_endpoint_is_unaffected_by_an_enrolled_two_factor_user(): void
    {
        $user = User::factory()->create(['role' => 'super-admin', 'status' => 'active', 'two_factor_confirmed_at' => now()]);

        $this->actingAs($user)->get('/up')->assertSuccessful();
    }

    public function test_dockerfile_and_compose_healthchecks_point_at_the_same_endpoint(): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('HEALTHCHECK', $dockerfile);
        $this->assertStringContainsString('http://127.0.0.1/up', $dockerfile);
        $this->assertStringContainsString('http://127.0.0.1/up', $compose);
    }
}
