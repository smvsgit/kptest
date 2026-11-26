<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\PortalRole;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AiSearchService;
use App\Support\SettingsSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F07: "Keep normal exact/fuzzy/tag search as default and AI off. Add AI search
 * as a separately enabled, explicit optional mode without slowing or silently
 * replacing native queries."
 *
 * Two switches have to be on before a single byte reaches a provider: an
 * administrator must configure and enable it, and the person searching must ask
 * for it on that query. Neither implies the other, and a failure falls back to
 * the native result and says so rather than passing itself off as an AI answer.
 */
class OptionalAiSearchTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://ai.example.test/v1/chat/completions';

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super-admin', 'department_id' => null, 'status' => 'active']);
    }

    private function configureAi(array $overrides = []): void
    {
        SystemSetting::put(AiSearchService::SETTING, array_replace([
            'enabled' => true,
            'endpoint' => self::ENDPOINT,
            'model' => 'some-model',
            'api_key' => 'secret-key',
            'timeout_seconds' => 6,
            'max_terms' => 6,
            'instructions' => 'Expand archive queries.',
        ], $overrides));
    }

    private function fakeProvider(string $content = 'annakut, utsav, prasadam'): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['choices' => [['message' => ['content' => $content]]]], 200),
            '*' => Http::response([], 200),
        ]);
    }

    // ----------------------------------------------------------- off by default

    public function test_ai_search_is_off_until_an_administrator_configures_it(): void
    {
        $this->assertFalse(app(AiSearchService::class)->available());

        $this->actingAs($this->superAdmin())->get('/?search=annakut')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->where('aiSearch.available', false)->where('aiSearch.used', false));
    }

    public function test_asking_for_ai_while_it_is_off_changes_nothing_and_calls_nobody(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin())->get('/?search=annakut&ai=1')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->where('aiSearch.used', false)->where('aiSearch.terms', []));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ai.example.test'));
    }

    // -------------------------------------- enabled, but still asked for per query

    public function test_an_ordinary_search_never_reaches_the_provider_even_when_enabled(): void
    {
        $this->configureAi();
        $this->fakeProvider();

        $this->actingAs($this->superAdmin())->get('/?search=annakut')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->where('aiSearch.available', true)
                ->where('aiSearch.requested', false)
                ->where('aiSearch.used', false));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ai.example.test'));
    }

    public function test_the_provider_is_called_only_when_the_query_asks_for_it(): void
    {
        $this->configureAi();
        $this->fakeProvider();

        $this->actingAs($this->superAdmin())->get('/?search=annakut&ai=1')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->where('aiSearch.requested', true)
                ->where('aiSearch.used', true)
                // "annakut" is the query itself, so it is not repeated back.
                ->where('aiSearch.terms', ['utsav', 'prasadam']));

        Http::assertSent(fn ($request) => $request->url() === self::ENDPOINT
            && $request['model'] === 'some-model'
            && $request->hasHeader('Authorization', 'Bearer secret-key'));
    }

    public function test_the_query_itself_is_not_repeated_back_as_an_extra_term(): void
    {
        $this->configureAi();
        $this->fakeProvider('Annakut, utsav');

        $this->actingAs($this->superAdmin())->get('/?search=annakut&ai=1')
            ->assertInertia(fn ($page) => $page->where('aiSearch.terms', ['utsav']));
    }

    public function test_the_number_of_extra_terms_is_capped_by_the_setting(): void
    {
        $this->configureAi(['max_terms' => 2]);
        $this->fakeProvider('one, two, three, four');

        $this->actingAs($this->superAdmin())->get('/?search=annakut&ai=1')
            ->assertInertia(fn ($page) => $page->where('aiSearch.terms', ['one', 'two']));
    }

    // ------------------------------------------------- a failure is never silent

    public function test_a_refusal_leaves_the_native_result_and_says_the_ai_did_not_run(): void
    {
        $this->configureAi();
        Http::fake([self::ENDPOINT => Http::response(['error' => 'nope'], 503), '*' => Http::response([], 200)]);

        $this->actingAs($this->superAdmin())->get('/?search=annakut&ai=1')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->where('aiSearch.requested', true)
                ->where('aiSearch.used', false)
                ->where('aiSearch.terms', [])
                ->whereNot('aiSearch.note', null));
    }

    public function test_an_unreachable_provider_does_not_break_the_search(): void
    {
        $this->configureAi();
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->actingAs($this->superAdmin())->get('/?search=annakut&ai=1')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->where('aiSearch.used', false));
    }

    public function test_nonsense_from_the_provider_is_discarded_rather_than_searched_for(): void
    {
        $this->configureAi();
        $this->fakeProvider(str_repeat('x', 200));

        $this->actingAs($this->superAdmin())->get('/?search=annakut&ai=1')
            ->assertInertia(fn ($page) => $page->where('aiSearch.used', false)->where('aiSearch.terms', []));
    }

    // ---------------------------------------------------------------- settings

    public function test_it_cannot_be_switched_on_without_an_endpoint_and_a_model(): void
    {
        $this->actingAs($this->superAdmin())->patch('/settings/search/ai', [
            'enabled' => true, 'endpoint' => '', 'model' => '',
            'timeout_seconds' => 6, 'max_terms' => 6,
        ])->assertSessionHasErrors('endpoint');

        $this->assertFalse(app(AiSearchService::class)->available());
    }

    public function test_the_key_is_kept_when_the_field_is_left_blank(): void
    {
        $this->configureAi();

        $this->actingAs($this->superAdmin())->patch('/settings/search/ai', [
            'enabled' => true, 'endpoint' => self::ENDPOINT, 'model' => 'another-model',
            'api_key' => '', 'timeout_seconds' => 8, 'max_terms' => 4,
        ])->assertRedirect();

        $settings = app(AiSearchService::class)->settings();
        $this->assertSame('secret-key', $settings['api_key'], 'A blank box means keep the stored key.');
        $this->assertSame('another-model', $settings['model']);
    }

    public function test_the_key_is_never_sent_to_the_screen(): void
    {
        $this->configureAi();

        $this->actingAs($this->superAdmin())->get('/')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->missing('aiSearchSettings.api_key'));
    }

    public function test_saving_it_needs_the_search_section(): void
    {
        $department = Department::firstOrCreate(['name' => 'Multimedia'], ['is_active' => true, 'is_system' => false]);
        $role = PortalRole::create([
            'name' => 'Metadata Only', 'slug' => 'metadata-only', 'base_role' => 'department-operator',
            'is_builtin' => false, 'is_active' => true, 'permissions' => [],
            'page_access' => ['browse' => true, 'settings' => true],
            'settings_access' => SettingsSections::normaliseMap(['metadata' => 'edit'], 'department-operator'),
        ]);
        $user = User::factory()->create([
            'role' => 'department-operator', 'portal_role_id' => $role->id,
            'department_id' => $department->id, 'status' => 'active',
        ]);

        $this->actingAs($user)->patchJson('/settings/search/ai', [
            'enabled' => false, 'timeout_seconds' => 6, 'max_terms' => 6,
        ])->assertStatus(403);
    }
}
