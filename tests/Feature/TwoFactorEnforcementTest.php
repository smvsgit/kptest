<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_user_is_redirected_to_challenge_when_session_is_not_fresh(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($user)->get('/profile')
            ->assertRedirect(route('security.2fa.challenge-page'));
    }

    public function test_confirmed_user_with_fresh_challenge_can_reach_profile(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($user)->withSession(['2fa_passed_at' => time()])->get('/profile')
            ->assertOk();
    }

    public function test_required_super_admin_can_reach_profile_and_enrollment_endpoint(): void
    {
        SystemSetting::put('security.two_factor', [
            'enabled' => true,
            'required_super_admin' => true,
            'issuer' => 'Karyalay Portal',
        ]);
        $user = User::factory()->create(['role' => 'super-admin', 'two_factor_confirmed_at' => null]);

        $this->actingAs($user)->get('/')
            ->assertRedirect(route('profile'));
        $this->actingAs($user)->get('/profile')->assertOk();
        $this->actingAs($user)->postJson('/profile/two-factor/setup')->assertOk()->assertJsonStructure(['secret', 'otpauth_uri']);
    }

    public function test_valid_totp_challenge_redirects_to_dashboard_and_marks_session_fresh(): void
    {
        $totp = app(TwoFactorService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);
        $code = $totp->code($secret, (int) floor(time() / 30));

        $this->actingAs($user)->post('/two-factor-challenge', ['code' => $code])
            ->assertRedirect(route('dashboard'));
        $this->assertGreaterThan(0, (int) session('2fa_passed_at'));
    }

    public function test_disabled_two_factor_policy_does_not_challenge_confirmed_users(): void
    {
        SystemSetting::put('security.two_factor', ['enabled' => false, 'required_super_admin' => false]);
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($user)->get('/profile')->assertOk();
    }
}
