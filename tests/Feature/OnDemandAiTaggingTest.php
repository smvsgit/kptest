<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MediaFile;
use App\Models\PortalRole;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AiTaggingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F08: "Add an explicit AI tagging button for supported images; process only on
 * request, show editable suggestions, let user accept/reject, preserve existing
 * tags and handle errors/cost permissions. Do not automatically call AI on
 * every upload."
 *
 * The acceptance is mostly about what must *not* happen, so that is what most
 * of these tests assert: no call on upload, no call without the permission, no
 * call for a file that is not a supported image, and no tag written by the
 * suggestion endpoint under any circumstances.
 */
class OnDemandAiTaggingTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://vision.example.test/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
    }

    private function department(string $name = 'Multimedia'): Department
    {
        return Department::firstOrCreate(['name' => $name], ['is_active' => true, 'is_system' => false]);
    }

    private function operator(?Department $department = null): User
    {
        return User::factory()->create([
            'role' => 'department-operator',
            'department_id' => ($department ?: $this->department())->id,
            'status' => 'active',
        ]);
    }

    private function configure(array $overrides = []): void
    {
        SystemSetting::put(AiTaggingService::SETTING, array_replace([
            'enabled' => true,
            'endpoint' => self::ENDPOINT,
            'model' => 'vision-model',
            'api_key' => 'secret-key',
            'timeout_seconds' => 20,
            'max_tags' => 8,
            'instructions' => 'Suggest tags.',
        ], $overrides));
    }

    private function fakeProvider(string $content = 'annakut, thal, mandir'): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['choices' => [['message' => ['content' => $content]]]], 200),
            '*' => Http::response([], 200),
        ]);
    }

    /** An image with real bytes on the fake disk, so the service can read it. */
    private function image(array $overrides = []): MediaFile
    {
        Storage::fake('media');
        $gd = imagecreatetruecolor(8, 8);
        $tmp = tempnam(sys_get_temp_dir(), 'png').'.png';
        imagepng($gd, $tmp);
        imagedestroy($gd);
        Storage::disk('media')->put('uploads/images/one.png', (string) file_get_contents($tmp));
        @unlink($tmp);

        return MediaFile::create(array_replace([
            'name' => 'Annakut.png', 'type' => 'image', 'size' => 64,
            'department_id' => $this->department()->id,
            'access_policy' => 'public', 'download_allowed' => true, 'tags' => [],
            'file_path' => 'uploads/images/one.png', 'asset_status' => 'active',
        ], $overrides));
    }

    // -------------------------------------------------------- off by default

    public function test_tagging_is_off_until_an_administrator_configures_it(): void
    {
        $this->assertFalse(app(AiTaggingService::class)->available());
        Http::fake();

        $media = $this->image();
        $this->actingAs($this->operator())
            ->postJson('/files/'.$media->id.'/ai-tags')
            ->assertStatus(422)
            ->assertJsonPath('used', false);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'vision.example.test'));
    }

    public function test_uploading_an_image_never_asks_the_provider_for_tags(): void
    {
        Storage::fake('media');
        $this->configure();
        $this->fakeProvider();

        $this->actingAs($this->operator())->post('/files/chunk', [
            'upload_id' => (string) Str::uuid(), 'chunk_index' => 0, 'total_chunks' => 1, 'total_size' => 11,
            'chunk' => UploadedFile::fake()->createWithContent('part', 'hello world'),
            'is_last_chunk' => 'true', 'filename' => 'Annakut.png',
            'defer_metadata' => '1', 'access_policy' => 'public',
        ])->assertSuccessful();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'vision.example.test'));
    }

    // ------------------------------------------------------ only on request

    public function test_a_request_returns_suggestions_and_writes_nothing(): void
    {
        $this->configure();
        $this->fakeProvider();
        $media = $this->image(['tags' => ['existing']]);

        $this->actingAs($this->operator())
            ->postJson('/files/'.$media->id.'/ai-tags')
            ->assertSuccessful()
            ->assertJsonPath('used', true)
            ->assertJsonPath('tags', ['annakut', 'thal', 'mandir']);

        $this->assertSame(['existing'], $media->fresh()->tags,
            'Suggestions are words on a screen until the person adds them.');
        Http::assertSentCount(1);
    }

    public function test_a_tag_the_asset_already_carries_is_not_suggested_again(): void
    {
        $this->configure();
        $this->fakeProvider('annakut, Thal, mandir');
        $media = $this->image(['tags' => ['thal']]);

        $this->actingAs($this->operator())
            ->postJson('/files/'.$media->id.'/ai-tags')
            ->assertSuccessful()
            ->assertJsonPath('tags', ['annakut', 'mandir']);
    }

    public function test_the_number_of_suggestions_is_capped(): void
    {
        $this->configure(['max_tags' => 2]);
        $this->fakeProvider('one, two, three, four');

        $this->actingAs($this->operator())
            ->postJson('/files/'.$this->image()->id.'/ai-tags')
            ->assertJsonPath('tags', ['one', 'two']);
    }

    // ------------------------------------------- what is refused before cost

    public function test_a_document_is_refused_without_calling_the_provider(): void
    {
        $this->configure();
        $this->fakeProvider();
        $media = $this->image(['name' => 'Notes.pdf', 'type' => 'document']);

        $this->actingAs($this->operator())
            ->postJson('/files/'.$media->id.'/ai-tags')
            ->assertStatus(422)
            ->assertJsonPath('used', false);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'vision.example.test'));
    }

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $this->configure();
        $this->fakeProvider();
        $media = $this->image();

        $role = PortalRole::create([
            'name' => 'No AI', 'slug' => 'no-ai', 'base_role' => 'department-operator',
            'is_builtin' => false, 'is_active' => true,
            'permissions' => ['upload' => true, 'manage_lifecycle' => true, 'ai_tagging' => false],
            'page_access' => ['browse' => true],
        ]);
        $user = User::factory()->create([
            'role' => 'department-operator', 'portal_role_id' => $role->id,
            'department_id' => $this->department()->id, 'status' => 'active',
        ]);

        $this->actingAs($user)->postJson('/files/'.$media->id.'/ai-tags')->assertStatus(403);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'vision.example.test'));
    }

    public function test_a_viewer_is_refused(): void
    {
        $this->configure();
        $this->fakeProvider();
        $viewer = User::factory()->create([
            'role' => 'viewer', 'department_id' => $this->department()->id, 'status' => 'active',
        ]);

        $this->actingAs($viewer)->postJson('/files/'.$this->image()->id.'/ai-tags')->assertStatus(403);
    }

    public function test_someone_from_another_department_is_refused(): void
    {
        $this->configure();
        $this->fakeProvider();
        $media = $this->image();
        $other = Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);

        $this->actingAs($this->operator($other))
            ->postJson('/files/'.$media->id.'/ai-tags')
            ->assertStatus(403);
    }

    // ---------------------------------------------------------- errors

    public function test_a_refusal_from_the_provider_is_reported_and_nothing_is_written(): void
    {
        $this->configure();
        Http::fake([self::ENDPOINT => Http::response(['error' => 'nope'], 429), '*' => Http::response([], 200)]);
        $media = $this->image(['tags' => ['existing']]);

        $response = $this->actingAs($this->operator())
            ->postJson('/files/'.$media->id.'/ai-tags')
            ->assertStatus(422)
            ->assertJsonPath('used', false);

        $this->assertNotEmpty($response->json('message'));
        $this->assertSame(['existing'], $media->fresh()->tags);
    }

    public function test_an_unreachable_provider_does_not_break_the_screen(): void
    {
        $this->configure();
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->actingAs($this->operator())
            ->postJson('/files/'.$this->image()->id.'/ai-tags')
            ->assertStatus(422)
            ->assertJsonPath('used', false);
    }

    public function test_every_request_is_recorded_whether_it_worked_or_not(): void
    {
        $this->configure();
        $this->fakeProvider();

        $this->actingAs($this->operator())->postJson('/files/'.$this->image()->id.'/ai-tags')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['event' => 'media.ai-tags.suggested']);
    }

    // --------------------------------------------------------- settings

    public function test_it_cannot_be_switched_on_without_an_endpoint_and_a_model(): void
    {
        $admin = User::factory()->create(['role' => 'super-admin', 'department_id' => null, 'status' => 'active']);

        $this->actingAs($admin)->patch('/settings/tagging/ai', [
            'enabled' => true, 'endpoint' => '', 'model' => '', 'timeout_seconds' => 20, 'max_tags' => 8,
        ])->assertSessionHasErrors('endpoint');

        $this->assertFalse(app(AiTaggingService::class)->available());
    }

    public function test_the_key_is_kept_when_left_blank_and_never_sent_to_the_screen(): void
    {
        $this->configure();
        $admin = User::factory()->create(['role' => 'super-admin', 'department_id' => null, 'status' => 'active']);

        $this->actingAs($admin)->patch('/settings/tagging/ai', [
            'enabled' => true, 'endpoint' => self::ENDPOINT, 'model' => 'other-model',
            'api_key' => '', 'timeout_seconds' => 30, 'max_tags' => 5,
        ])->assertRedirect();

        $this->assertSame('secret-key', app(AiTaggingService::class)->settings()['api_key']);

        $this->actingAs($admin)->get('/')
            ->assertInertia(fn ($page) => $page->missing('aiTaggingSettings.api_key'));
    }

    public function test_tags_remain_available_for_every_file_type(): void
    {
        $inspector = (string) file_get_contents(base_path('resources/js/Components/media/FolderInspector.tsx'));

        // The field itself is not conditional; only the AI button is.
        $this->assertStringContainsString('id="fi-tags"', $inspector);
        $this->assertStringContainsString('p.aiTagging.available&&p.aiTagging.permitted&&', $inspector);

        // And the server lets a document be tagged like anything else.
        $document = $this->image(['name' => 'Notes.pdf', 'type' => 'document']);
        $admin = User::factory()->create([
            'role' => 'department-admin', 'department_id' => $this->department()->id, 'status' => 'active',
        ]);
        $this->actingAs($admin)->patchJson('/files/bulk-edit', [
            'ids' => [$document->id], 'tags_mode' => 'add', 'tags' => ['minutes'],
        ])->assertSuccessful();

        $this->assertContains('minutes', $document->fresh()->tags);
    }
}
