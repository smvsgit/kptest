<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C11 / UAT-030: language handling.
 *
 * The locale plumbing is complete and is pinned down here: a per-user
 * preference, a branding-level default, resolution to an application locale and
 * the locale being shared with the front end.
 *
 * What does NOT exist yet is the translation content itself — there are no
 * lang/ files and the React components carry hardcoded English strings — so
 * selecting ગુજરાતી currently changes the stored preference and the resolved
 * locale, not the words on screen. These tests therefore assert the contract
 * that is implemented, and deliberately do not claim the UI is translated.
 */
class LocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        $department = Department::firstOrCreate(['name' => 'Audio Video'], ['is_active' => true, 'is_system' => false]);

        return User::factory()->create(array_replace([
            'role' => 'viewer', 'department_id' => $department->id, 'status' => 'active',
        ], $overrides));
    }

    public function test_user_can_save_a_gujarati_interface_preference(): void
    {
        $user = $this->user(['preferred_language' => 'en']);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name, 'email' => $user->email,
            'preferred_language' => 'gu', 'ui_theme' => 'smvs-dark',
        ])->assertSessionHasNoErrors();

        $this->assertSame('gu', $user->fresh()->preferred_language);
    }

    public function test_an_unsupported_language_is_rejected(): void
    {
        $user = $this->user(['preferred_language' => 'en']);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name, 'email' => $user->email,
            'preferred_language' => 'fr', 'ui_theme' => 'smvs-dark',
        ])->assertSessionHasErrors('preferred_language');

        $this->assertSame('en', $user->fresh()->preferred_language);
    }

    public function test_user_preference_drives_the_application_locale(): void
    {
        $user = $this->user(['preferred_language' => 'gu']);

        $this->actingAs($user)->get('/')->assertOk();

        $this->assertSame('gu', app()->getLocale());
    }

    public function test_locale_is_shared_with_the_front_end(): void
    {
        $user = $this->user(['preferred_language' => 'gu']);

        $this->actingAs($user)->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('locale', 'gu'));
    }

    /** preferred_language is NOT NULL with an 'en' default, so the branding
     *  default is what guests (the login screen) get. */
    public function test_branding_default_language_applies_to_guests(): void
    {
        SystemSetting::put('branding.settings', ['default_language' => 'gu']);

        $this->get('/login')->assertOk()
            ->assertInertia(fn ($page) => $page->where('locale', 'gu'));
    }

    public function test_user_preference_overrides_the_branding_default(): void
    {
        SystemSetting::put('branding.settings', ['default_language' => 'gu']);
        $user = $this->user(['preferred_language' => 'en']);

        $this->actingAs($user)->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    }

    public function test_locale_falls_back_to_english_for_an_invalid_branding_value(): void
    {
        SystemSetting::put('branding.settings', ['default_language' => 'xx']);

        $this->get('/login')->assertOk()
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    }

    public function test_new_users_default_to_english(): void
    {
        $this->assertSame('en', $this->user()->fresh()->preferred_language);
    }

    /**
     * Guards the known gap so it cannot be mistaken for working: once Gujarati
     * translations are added, this test should be replaced by real assertions
     * on translated output.
     */
    public function test_no_translation_catalogue_exists_yet(): void
    {
        $this->assertDirectoryDoesNotExist(
            base_path('lang'),
            'A lang/ directory now exists — replace this test with assertions on translated strings.',
        );
    }
}
