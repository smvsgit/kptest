<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\NetworkPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * UAT-021 … UAT-026: Internal/VPN/External Internet network policy.
 *
 * These rows had never been executed. Each test below is named after the row
 * it covers so the UAT matrix can be signed off against real evidence.
 */
class NetworkAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICE = '10.20.0.0/16';
    private const VPN = '172.16.5.0/24';

    private function enablePolicy(array $overrides = []): void
    {
        SystemSetting::put('security.network', array_replace([
            'enabled' => true,
            'allowed_cidrs' => [self::OFFICE],
            'trusted_vpn_proxies' => [self::VPN],
            'emergency_super_admin_email' => '',
            'department_admin_can_manage_external_access' => false,
        ], $overrides));
    }

    private function requestFrom(string $ip): Request
    {
        return Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
    }

    private function policy(): NetworkPolicyService
    {
        return app(NetworkPolicyService::class);
    }

    private function user(array $overrides = []): User
    {
        $department = Department::firstOrCreate(['name' => 'Audio Video'], ['is_active' => true, 'is_system' => false]);

        return User::factory()->create(array_replace([
            'role' => 'viewer', 'status' => 'active', 'department_id' => $department->id,
            'password' => Hash::make('CorrectHorse123!'),
        ], $overrides));
    }

    // -------------------------------------------------- UAT-021 Internal/VPN

    public function test_uat_021_internal_and_vpn_ranges_are_allowed(): void
    {
        $this->enablePolicy();
        $user = $this->user();

        $this->assertSame(NetworkPolicyService::SOURCE_INTERNAL, $this->policy()->accessSource($this->requestFrom('10.20.4.8'), $user));
        $this->assertSame(NetworkPolicyService::SOURCE_VPN, $this->policy()->accessSource($this->requestFrom('172.16.5.33'), $user));
        $this->assertTrue($this->policy()->allows($this->requestFrom('10.20.4.8'), $user));
    }

    public function test_uat_021_internal_user_can_actually_log_in(): void
    {
        $this->enablePolicy();
        $user = $this->user();

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.4.8'])
            ->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse123!'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_uat_021_policy_disabled_means_every_source_is_unrestricted(): void
    {
        SystemSetting::put('security.network', ['enabled' => false]);

        $this->assertSame(
            NetworkPolicyService::SOURCE_UNRESTRICTED,
            $this->policy()->accessSource($this->requestFrom('203.0.113.9'), $this->user()),
        );
    }

    // ------------------------------------------------ UAT-022 External block

    public function test_uat_022_public_internet_is_blocked_without_an_exception(): void
    {
        $this->enablePolicy();
        $user = $this->user();

        $this->assertSame(NetworkPolicyService::SOURCE_BLOCKED, $this->policy()->accessSource($this->requestFrom('203.0.113.9'), $user));
        $this->assertFalse($this->policy()->allows($this->requestFrom('203.0.113.9'), $user));
    }

    public function test_uat_022_blocked_user_cannot_log_in_and_is_not_authenticated(): void
    {
        $this->enablePolicy();
        $user = $this->user();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse123!'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login-network-blocked']);
    }

    // ------------------------------------------ UAT-023 User-wise allowance

    public function test_uat_023_user_with_external_entitlement_is_allowed_from_the_internet(): void
    {
        $this->enablePolicy();
        $allowed = $this->user(['external_access_allowed' => true]);
        $other = $this->user(['external_access_allowed' => false]);

        $this->assertSame(NetworkPolicyService::SOURCE_EXTERNAL, $this->policy()->accessSource($this->requestFrom('203.0.113.9'), $allowed));
        $this->assertSame(NetworkPolicyService::SOURCE_BLOCKED, $this->policy()->accessSource($this->requestFrom('203.0.113.9'), $other));
    }

    public function test_uat_023_entitled_user_can_log_in_through_the_same_url(): void
    {
        $this->enablePolicy();
        $user = $this->user(['external_access_allowed' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse123!'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    // --------------------------------------------- UAT-024 Start and expiry

    public function test_uat_024_access_before_the_start_time_is_denied(): void
    {
        $this->enablePolicy();
        $user = $this->user([
            'external_access_allowed' => true,
            'external_access_starts_at' => now()->addHour(),
        ]);

        $this->assertSame(NetworkPolicyService::SOURCE_BLOCKED, $this->policy()->accessSource($this->requestFrom('203.0.113.9'), $user));
    }

    public function test_uat_024_access_after_the_expiry_is_denied(): void
    {
        $this->enablePolicy();
        $user = $this->user([
            'external_access_allowed' => true,
            'external_access_starts_at' => now()->subDay(),
            'external_access_expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(NetworkPolicyService::SOURCE_BLOCKED, $this->policy()->accessSource($this->requestFrom('203.0.113.9'), $user));
        $this->assertFalse($user->externalAccessIsActive());
    }

    public function test_uat_024_access_inside_the_window_is_granted(): void
    {
        $this->enablePolicy();
        $user = $this->user([
            'external_access_allowed' => true,
            'external_access_starts_at' => now()->subHour(),
            'external_access_expires_at' => now()->addHour(),
        ]);

        $this->assertSame(NetworkPolicyService::SOURCE_EXTERNAL, $this->policy()->accessSource($this->requestFrom('203.0.113.9'), $user));
    }

    // ------------------------------------------------- UAT-025 Decision audit

    public function test_uat_025_granting_and_revoking_external_access_is_audited_with_reason_and_approver(): void
    {
        $this->enablePolicy();
        $super = $this->user(['role' => 'super-admin']);
        $target = $this->user();

        $this->actingAs($super)->withServerVariables(['REMOTE_ADDR' => '10.20.4.8'])
            ->patchJson("/users/{$target->id}/external-access", [
                'allowed' => true,
                'reason' => 'Travelling for the Vadodara mahotsav.',
                'expires_at' => now()->addDays(3)->toDateTimeString(),
            ])->assertOk();

        $fresh = $target->fresh();
        $this->assertTrue((bool) $fresh->external_access_allowed);
        $this->assertSame('Travelling for the Vadodara mahotsav.', $fresh->external_access_reason);
        $this->assertSame($super->id, $fresh->external_access_approved_by, 'The approver must be recorded.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.external-access.allowed']);

        $this->actingAs($super)->withServerVariables(['REMOTE_ADDR' => '10.20.4.8'])
            ->patchJson("/users/{$target->id}/external-access", ['allowed' => false])->assertOk();
        $this->assertFalse((bool) $target->fresh()->external_access_allowed);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.external-access.blocked']);
    }

    public function test_uat_025_department_admin_cannot_grant_external_access_while_disabled(): void
    {
        $this->enablePolicy(['department_admin_can_manage_external_access' => false]);
        $admin = $this->user(['role' => 'department-admin']);
        $target = $this->user();

        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '10.20.4.8'])
            ->patchJson("/users/{$target->id}/external-access", [
                'allowed' => true, 'reason' => 'Should not be permitted.',
            ])->assertForbidden();

        $this->assertFalse((bool) $target->fresh()->external_access_allowed);
    }

    // --------------------------------------------------- UAT-026 Login source

    public function test_uat_026_login_audit_records_the_access_source(): void
    {
        $this->enablePolicy();

        $internal = $this->user();
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.4.8'])
            ->post('/login', ['email' => $internal->email, 'password' => 'CorrectHorse123!']);
        $this->post('/logout');

        $vpn = $this->user();
        $this->withServerVariables(['REMOTE_ADDR' => '172.16.5.33'])
            ->post('/login', ['email' => $vpn->email, 'password' => 'CorrectHorse123!']);
        $this->post('/logout');

        $external = $this->user(['external_access_allowed' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/login', ['email' => $external->email, 'password' => 'CorrectHorse123!']);

        $sources = \DB::table('audit_logs')->where('event', 'auth.login')->pluck('context')
            ->map(fn ($c) => json_decode((string) $c, true)['access_source'] ?? null)->filter()->all();

        $this->assertContains(NetworkPolicyService::SOURCE_INTERNAL, $sources);
        $this->assertContains(NetworkPolicyService::SOURCE_VPN, $sources);
        $this->assertContains(NetworkPolicyService::SOURCE_EXTERNAL, $sources);
    }

    // ------------------------------------------- Emergency break-glass access

    public function test_emergency_super_admin_is_never_locked_out(): void
    {
        $this->enablePolicy(['emergency_super_admin_email' => 'break.glass@example.org']);
        $super = $this->user(['role' => 'super-admin', 'email' => 'break.glass@example.org']);

        $this->assertSame(
            NetworkPolicyService::SOURCE_INTERNAL,
            $this->policy()->accessSource($this->requestFrom('203.0.113.9'), $super),
        );
    }

    /** The policy is not only a login gate: it is re-checked on every request. */
    public function test_an_authenticated_session_is_cut_off_when_the_source_becomes_disallowed(): void
    {
        $this->enablePolicy();
        $user = $this->user();

        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.20.4.8'])
            ->get('/')->assertOk();

        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get('/')->assertForbidden();
    }

    public function test_cidr_matching_does_not_leak_to_neighbouring_ranges(): void
    {
        $this->enablePolicy(['allowed_cidrs' => ['10.20.4.0/24']]);
        $user = $this->user();

        $this->assertSame(NetworkPolicyService::SOURCE_INTERNAL, $this->policy()->accessSource($this->requestFrom('10.20.4.255'), $user));
        $this->assertSame(NetworkPolicyService::SOURCE_BLOCKED, $this->policy()->accessSource($this->requestFrom('10.20.5.1'), $user));
    }
}
