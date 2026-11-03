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
 * Phase 1 of the translation itself has now landed: lang/en/ui.php is the
 * source catalogue, lang/gu/ui.php mirrors it, and the whole thing is shared
 * with the front end so the sign-in screens, the navigation, the header and
 * Browse render in the chosen language. Later phases add upload, access and
 * the admin panels; the tests below cover the mechanism and the catalogue,
 * not how much of the interface each phase reaches.
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
    public function test_the_catalogue_exists_for_both_languages(): void
    {
        $this->assertFileExists(base_path('lang/en/ui.php'));
        $this->assertFileExists(base_path('lang/gu/ui.php'));
    }

    /**
     * The English file is the source. A string added there without its
     * Gujarati counterpart would reach a Gujarati screen in English, and this
     * is what stops that happening quietly.
     */
    public function test_every_english_key_has_a_gujarati_translation(): void
    {
        $english = $this->flatten(require base_path('lang/en/ui.php'));
        $gujarati = $this->flatten(require base_path('lang/gu/ui.php'));

        $missing = array_diff(array_keys($english), array_keys($gujarati));
        $this->assertSame([], array_values($missing), 'Untranslated keys: '.implode(', ', $missing));
    }

    public function test_the_gujarati_file_has_no_keys_of_its_own(): void
    {
        $english = $this->flatten(require base_path('lang/en/ui.php'));
        $gujarati = $this->flatten(require base_path('lang/gu/ui.php'));

        $extra = array_diff(array_keys($gujarati), array_keys($english));
        $this->assertSame([], array_values($extra), 'Keys with no English source: '.implode(', ', $extra));
    }

    /** A :placeholder dropped in translation would render as a literal gap. */
    public function test_placeholders_survive_translation(): void
    {
        $english = $this->flatten(require base_path('lang/en/ui.php'));
        $gujarati = $this->flatten(require base_path('lang/gu/ui.php'));

        foreach ($english as $key => $line) {
            preg_match_all('/:([a-z_]+)/', $line, $matches);
            foreach ($matches[1] as $placeholder) {
                $this->assertStringContainsString(
                    ':'.$placeholder,
                    $gujarati[$key] ?? '',
                    "The Gujarati line for {$key} has lost the :{$placeholder} placeholder.",
                );
            }
        }
    }

    public function test_the_catalogue_is_shared_with_the_front_end(): void
    {
        $this->actingAs($this->user(['preferred_language' => 'gu']))
            ->get('/')
            // The catalogue ships flattened, so its keys contain dots and are
            // read from the array rather than as a nested Inertia path.
            ->assertInertia(fn ($page) => $page
                ->where('locale', 'gu')
                ->where('translations', fn ($lines) => $lines['nav.browse'] === trans('ui.nav.browse', [], 'gu')
                    && $lines['auth.sign_in'] === trans('ui.auth.sign_in', [], 'gu')));
    }

    /** The sign-in screen is the first thing anyone sees, guests included. */
    public function test_a_guest_sign_in_screen_follows_the_branding_language(): void
    {
        SystemSetting::put('branding.settings', ['default_language' => 'gu']);

        $this->get('/login')->assertInertia(fn ($page) => $page
            ->where('locale', 'gu')
            ->where('translations', fn ($lines) => $lines['auth.sign_in'] === trans('ui.auth.sign_in', [], 'gu')
                && $lines['auth.welcome_back'] === trans('ui.auth.welcome_back', [], 'gu')));
    }

    public function test_an_english_reader_gets_the_english_catalogue(): void
    {
        $this->actingAs($this->user(['preferred_language' => 'en']))
            ->get('/')
            ->assertInertia(fn ($page) => $page
                ->where('translations', fn ($lines) => $lines['nav.browse'] === 'Browse Files'));
    }

    public function test_language_can_be_switched_from_the_header(): void
    {
        $user = $this->user(['preferred_language' => 'en']);

        $this->actingAs($user)
            ->patch('/profile/language', ['preferred_language' => 'gu'])
            ->assertRedirect();

        $this->assertSame('gu', $user->fresh()->preferred_language);
    }

    public function test_the_header_switch_refuses_an_unsupported_language(): void
    {
        $user = $this->user(['preferred_language' => 'en']);

        $this->actingAs($user)
            ->patch('/profile/language', ['preferred_language' => 'fr'])
            ->assertSessionHasErrors('preferred_language');

        $this->assertSame('en', $user->fresh()->preferred_language);
    }

    /** lang files are nested; the front end reads them as dot keys. */
    private function flatten(array $items, string $prefix = ''): array
    {
        $out = [];
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $out += $this->flatten($value, $path);
                continue;
            }
            $out[$path] = (string) $value;
        }
        return $out;
    }
}
