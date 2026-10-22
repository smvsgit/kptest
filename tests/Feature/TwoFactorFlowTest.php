<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * UAT C17 / UAT-011: end-to-end two-factor journey — enrolment, challenge and
 * recovery codes. Covers what manual testing exercised so it stays covered.
 */
class TwoFactorFlowTest extends TestCase
{
    use RefreshDatabase;

    private function totp(): TwoFactorService
    {
        return app(TwoFactorService::class);
    }

    private function currentCode(string $secret): string
    {
        return $this->totp()->code($secret, (int) floor(time() / 30));
    }

    /** The generator must be a standard TOTP so real authenticator apps work. */
    public function test_totp_matches_the_rfc_6238_reference_vector(): void
    {
        // RFC 6238 test secret '12345678901234567890', T=59 (counter 1) => 287082.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', $this->totp()->code($secret, 1));
    }

    public function test_generated_secret_is_valid_base32_of_expected_length(): void
    {
        $secret = $this->totp()->generateSecret();
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    // ------------------------------------------------------------- enrolment

    public function test_setup_issues_a_secret_and_otpauth_uri(): void
    {
        $user = User::factory()->create(['role' => 'viewer', 'status' => 'active']);

        $response = $this->actingAs($user)->postJson('/profile/two-factor/setup')->assertOk();

        $secret = $response->json('secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringContainsString('otpauth://totp/', $response->json('otpauth_uri'));
        $this->assertStringContainsString("secret={$secret}", $response->json('otpauth_uri'));
    }

    public function test_confirm_rejects_an_invalid_code_and_leaves_two_factor_off(): void
    {
        $user = User::factory()->create(['role' => 'viewer', 'status' => 'active']);

        $this->actingAs($user)->postJson('/profile/two-factor/setup')->assertOk();
        $this->actingAs($user)->postJson('/profile/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertNull($user->fresh()->two_factor_confirmed_at, 'A rejected code must not enable 2FA.');
    }

    public function test_confirm_with_a_valid_code_enables_two_factor_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create(['role' => 'viewer', 'status' => 'active']);

        $secret = $this->actingAs($user)->postJson('/profile/two-factor/setup')->json('secret');
        $response = $this->actingAs($user)
            ->withSession(['2fa_setup_secret' => $secret])
            ->postJson('/profile/two-factor/confirm', ['code' => $this->currentCode($secret)])
            ->assertOk();

        $codes = $response->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertSame($secret, Crypt::decryptString($user->fresh()->two_factor_secret));
    }

    // ------------------------------------------------------------- challenge

    /** @return array{0:User,1:string,2:array<int,string>} */
    private function enrolledUser(): array
    {
        $secret = $this->totp()->generateSecret();
        $codes = $this->totp()->recoveryCodes();
        $user = User::factory()->create([
            'role' => 'viewer', 'status' => 'active',
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_map(fn ($c) => Hash::make($c), $codes))),
            'two_factor_confirmed_at' => now(),
        ]);

        return [$user, $secret, $codes];
    }

    public function test_login_forces_a_fresh_challenge_even_with_a_stale_pass_marker(): void
    {
        [$user] = $this->enrolledUser();
        $user->forceFill(['password' => Hash::make('CorrectHorse123!')])->save();

        $this->withSession(['2fa_passed_at' => time()])
            ->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse123!'])
            ->assertRedirect(route('security.2fa.challenge-page'));

        $this->assertNull(session('2fa_passed_at'), 'Login must clear any earlier challenge marker.');
    }

    public function test_wrong_code_is_rejected_at_the_challenge(): void
    {
        [$user] = $this->enrolledUser();

        $this->actingAs($user)->post('/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertNull(session('2fa_passed_at'));
    }

    public function test_recovery_code_passes_the_challenge_and_is_consumed(): void
    {
        [$user, , $codes] = $this->enrolledUser();
        $used = $codes[0];

        $this->actingAs($user)->post('/two-factor-challenge', ['code' => $used])
            ->assertRedirect(route('dashboard'));

        $remaining = json_decode(Crypt::decryptString($user->fresh()->two_factor_recovery_codes), true);
        $this->assertCount(7, $remaining, 'A used recovery code must be removed.');

        // The same code must not work a second time.
        $this->app['session']->forget('2fa_passed_at');
        $this->actingAs($user)->post('/two-factor-challenge', ['code' => $used])
            ->assertSessionHasErrors('code');
    }

    public function test_recovery_code_is_accepted_case_insensitively(): void
    {
        [$user, , $codes] = $this->enrolledUser();

        $this->actingAs($user)->post('/two-factor-challenge', ['code' => strtolower($codes[1])])
            ->assertRedirect(route('dashboard'));
    }

    public function test_challenge_is_refused_when_two_factor_is_not_enabled(): void
    {
        $user = User::factory()->create(['role' => 'viewer', 'status' => 'active']);

        $this->actingAs($user)->post('/two-factor-challenge', ['code' => '123456'])
            ->assertSessionHasErrors('code');
    }

    // --------------------------------------------------------------- disable

    public function test_disable_requires_the_current_password(): void
    {
        [$user] = $this->enrolledUser();
        $user->forceFill(['password' => Hash::make('CorrectHorse123!')])->save();

        $this->actingAs($user)->withSession(['2fa_passed_at' => time()])
            ->deleteJson('/profile/two-factor', ['password' => 'WrongPassword123!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        $this->actingAs($user)->withSession(['2fa_passed_at' => time()])
            ->deleteJson('/profile/two-factor', ['password' => 'CorrectHorse123!'])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
    }

    // --------------------------------------------------------------- audit

    public function test_two_factor_events_are_audited(): void
    {
        [$user, $secret, $codes] = $this->enrolledUser();

        $this->actingAs($user)->post('/two-factor-challenge', ['code' => $this->currentCode($secret)]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'security.2fa.challenge-passed']);

        $this->app['session']->forget('2fa_passed_at');
        $this->actingAs($user)->post('/two-factor-challenge', ['code' => $codes[0]]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'security.2fa.recovery-used']);
    }

    // -------------------------------------------------------------- policy

    public function test_issuer_from_settings_appears_in_the_otpauth_uri(): void
    {
        SystemSetting::put('security.two_factor', ['enabled' => true, 'issuer' => 'SMVS Karyalay']);
        $user = User::factory()->create(['role' => 'viewer', 'status' => 'active']);

        $uri = $this->actingAs($user)->postJson('/profile/two-factor/setup')->json('otpauth_uri');

        $this->assertStringContainsString(rawurlencode('SMVS Karyalay'), $uri);
    }
}
