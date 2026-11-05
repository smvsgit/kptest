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
    /**
     * The sign-in screens have no account to hang a preference on, so the
     * switch there writes a cookie instead. These cover the visitor's path:
     * pick a language, see the next screen in it, and keep it on return.
     */
    public function test_a_guest_can_choose_a_language_on_the_sign_in_screen(): void
    {
        $this->from('/login')
            ->post('/locale', ['language' => 'gu'])
            ->assertRedirect('/login')
            ->assertCookie(\App\Services\LocaleResolver::COOKIE, 'gu');
    }

    public function test_the_chosen_language_drives_the_next_sign_in_screen(): void
    {
        $this->withCookie(\App\Services\LocaleResolver::COOKIE, 'gu')
            ->get('/login')
            ->assertInertia(fn ($page) => $page
                ->where('locale', 'gu')
                ->where('translations', fn ($lines) => $lines['auth.sign_in'] === trans('ui.auth.sign_in', [], 'gu')));
    }

    public function test_an_unsupported_language_is_refused_from_the_sign_in_screen(): void
    {
        $this->from('/login')
            ->post('/locale', ['language' => 'fr'])
            ->assertSessionHasErrors('language')
            ->assertCookieMissing(\App\Services\LocaleResolver::COOKIE);
    }

    /**
     * A signed-in account's own preference outranks the cookie, so a shared
     * machine where someone once picked Gujarati does not override the next
     * person's profile setting.
     */
    public function test_a_signed_in_preference_beats_the_guest_cookie(): void
    {
        $this->actingAs($this->user(['preferred_language' => 'en']))
            ->withCookie(\App\Services\LocaleResolver::COOKIE, 'gu')
            ->get('/')
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    }

    /** The one-time reset that puts the portal's opening language back to English. */
    private function runLanguageResetMigration(): void
    {
        $migration = require database_path('migrations/2026_11_05_000100_default_portal_language_to_english.php');
        $migration->up();
    }

    public function test_the_reset_migration_puts_the_opening_language_back_to_english(): void
    {
        SystemSetting::put('branding.settings', [
            'portal_name' => 'Karyalay Portal',
            'login_text' => 'Secure office media and document portal',
            'default_language' => 'gu',
            'logo_path' => 'branding/logo.png',
        ]);

        $this->runLanguageResetMigration();

        $branding = SystemSetting::valueFor('branding.settings', []);
        $this->assertSame('en', $branding['default_language']);
        // Only that one key moves; the rest of the branding row is untouched.
        $this->assertSame('Karyalay Portal', $branding['portal_name']);
        $this->assertSame('branding/logo.png', $branding['logo_path']);
    }

    public function test_the_reset_migration_copes_with_no_branding_row(): void
    {
        SystemSetting::query()->where('key', 'branding.settings')->delete();

        $this->runLanguageResetMigration();

        $this->assertSame('en', app(\App\Services\LocaleResolver::class)->defaultLanguage());
    }

    /**
     * It is a reset, not a lock. An office that wants the portal to open in
     * Gujarati must still be able to say so in Settings afterwards.
     */
    public function test_an_administrator_can_still_choose_gujarati_afterwards(): void
    {
        $this->runLanguageResetMigration();

        SystemSetting::put('branding.settings', ['default_language' => 'gu']);

        $this->get('/login')->assertInertia(fn ($page) => $page->where('locale', 'gu'));
    }

    public function test_the_message_catalogue_exists_for_both_languages(): void
    {
        $this->assertFileExists(base_path('lang/en/messages.php'));
        $this->assertFileExists(base_path('lang/gu/messages.php'));
    }

    public function test_every_english_message_has_a_gujarati_translation(): void
    {
        $english = $this->flatten(require base_path('lang/en/messages.php'));
        $gujarati = $this->flatten(require base_path('lang/gu/messages.php'));

        $missing = array_diff(array_keys($english), array_keys($gujarati));
        $this->assertSame([], array_values($missing), 'Untranslated messages: '.implode(', ', $missing));

        $extra = array_diff(array_keys($gujarati), array_keys($english));
        $this->assertSame([], array_values($extra), 'Messages with no English source: '.implode(', ', $extra));
    }

    public function test_message_placeholders_survive_translation(): void
    {
        $english = $this->flatten(require base_path('lang/en/messages.php'));
        $gujarati = $this->flatten(require base_path('lang/gu/messages.php'));

        foreach ($english as $key => $line) {
            preg_match_all('/:([a-z_]+)/', $line, $matches);
            foreach ($matches[1] as $placeholder) {
                $this->assertStringContainsString(
                    ':'.$placeholder,
                    $gujarati[$key] ?? '',
                    "The Gujarati message for {$key} has lost the :{$placeholder} placeholder.",
                );
            }
        }
    }

    /**
     * Every messages.* key a controller, middleware or service asks for has to
     * exist, because __() returns the key itself when it does not — and unlike
     * an interface label, that string is what a user is told went wrong.
     */
    public function test_every_message_key_the_backend_asks_for_exists(): void
    {
        // The backend draws on both files: messages.* for what it says back,
        // and a few ui.* lines where a refusal names a field the forms also
        // label, so the two read the same way.
        $catalogue = [
            'messages' => $this->flatten(require base_path('lang/en/messages.php')),
            'ui' => $this->flatten(require base_path('lang/en/ui.php')),
        ];
        $unknown = [];

        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($tree as $entry) {
            if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/'((?:messages|ui)\.[a-z0-9_.]+)'/", (string) file_get_contents($entry->getPathname()), $matches);
            foreach ($matches[1] as $reference) {
                [$file, $key] = explode('.', $reference, 2);
                if (! array_key_exists($key, $catalogue[$file])) {
                    $unknown[] = $entry->getBasename().': '.$reference;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unknown)),
            'Keys asked for by the backend but not in the catalogue: '.implode(', ', array_unique($unknown)));
    }

    /**
     * A JSON endpoint renders no Inertia response, so the locale cannot be a
     * by-product of building shared props — it has to be set by its own
     * middleware, early. This asserts the behaviour that depends on it rather
     * than the middleware order itself.
     */
    public function test_a_json_endpoint_answers_in_the_users_language(): void
    {
        $gujarati = trans('messages.notify.all_marked_read', [], 'gu');
        $english = trans('messages.notify.all_marked_read', [], 'en');
        $this->assertNotSame($gujarati, $english, 'This test needs a line that actually differs.');

        $this->actingAs($this->user(['preferred_language' => 'gu']))
            ->postJson('/notifications/read-all')
            ->assertOk()
            ->assertJson(['message' => $gujarati]);
    }

    public function test_an_english_reader_still_gets_english_from_that_endpoint(): void
    {
        $this->actingAs($this->user(['preferred_language' => 'en']))
            ->postJson('/notifications/read-all')
            ->assertOk()
            ->assertJson(['message' => trans('messages.notify.all_marked_read', [], 'en')]);
    }

    /**
     * A refusal is raised by middleware, before any controller and without an
     * Inertia response ever being built. It is the case the old arrangement
     * could not have served, so it is the one worth pinning down.
     */
    public function test_a_refusal_from_middleware_follows_the_users_language(): void
    {
        $viewer = $this->user(['preferred_language' => 'gu', 'role' => 'viewer']);

        $this->actingAs($viewer)
            ->postJson('/departments', ['name' => 'Attempted'])
            ->assertForbidden()
            ->assertJson(['message' => trans('messages.access.denied', [], 'gu')]);
    }

    /**
     * A key the front end asks for but the catalogue does not carry renders as
     * the key itself — "settings.tab_backup" in the middle of a tab strip. That
     * is deliberate (it is visible in review rather than silently English), but
     * it should never reach a release, so every literal key in the React tree
     * is checked against the English catalogue here.
     *
     * Only literal t('...') / tc('...') calls can be checked. Keys built at
     * runtime, such as t('settings.channel_' + channel), are skipped: the
     * prefix alone says nothing about which keys exist.
     */
    public function test_every_literal_key_the_front_end_asks_for_exists(): void
    {
        $catalogue = $this->flatten(require base_path('lang/en/ui.php'));
        $unknown = [];

        foreach ($this->frontEndFiles() as $file) {
            $source = (string) file_get_contents($file);
            // The trailing [,)] is what keeps a built key out: in
            // t('quota.state_' + d.state) the quote is followed by a +, so the
            // fragment before it is never mistaken for a whole key.
            preg_match_all("/\\bt[c]?\\(\\s*'([a-z0-9_]+\\.[a-z0-9_]+)'\\s*[,)]/i", $source, $matches);

            foreach ($matches[1] as $key) {
                if (! array_key_exists($key, $catalogue)) {
                    $unknown[] = basename($file).': '.$key;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unknown)),
            'Keys asked for but not in lang/en/ui.php: '.implode(', ', array_unique($unknown)));
    }

    /** @return list<string> */
    private function frontEndFiles(): array
    {
        $root = base_path('resources/js');
        $files = [];

        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($tree as $entry) {
            if ($entry->isFile() && in_array($entry->getExtension(), ['ts', 'tsx'], true)) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

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
