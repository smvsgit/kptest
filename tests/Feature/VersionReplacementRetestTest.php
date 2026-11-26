<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F11, retested after the v19.13 folder fixes and the v19.14 permission split.
 *
 * The reviewers verified version replacement on 18 November and then declined
 * to count that as current evidence: "That remains historical evidence, not a
 * new November 23/24 pass. Retest current-version preview/download and retained
 * history after the folder and editing fixes."
 *
 * So this is the whole acceptance sentence, one assertion at a time:
 * one logical asset, one path, unchanged permissions, a version carrying actor,
 * date and change note, the current version served by preview and download, and
 * the history still there and still downloadable. The parts that touch the
 * folder and the owner department are the ones v19.13 moved, so they are pinned
 * down here too.
 */
class VersionReplacementRetestTest extends TestCase
{
    use RefreshDatabase;

    private const V1 = 'version-one-bytes';
    private const V2 = 'version-two-bytes-which-are-longer';

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
    }

    /**
     * An asset sitting in a folder, owned by a department, with a first
     * version on record — the state a real library is in.
     */
    private function asset(array $overrides = []): array
    {
        Storage::fake('media');
        $department = Department::firstOrCreate(['name' => 'Multimedia'], ['is_active' => true, 'is_system' => false]);
        $operator = User::factory()->create([
            'role' => 'department-operator', 'department_id' => $department->id, 'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'role' => 'department-admin', 'department_id' => $department->id, 'status' => 'active',
        ]);
        $folder = MediaFolder::create([
            'department_id' => $department->id, 'parent_id' => null, 'name' => 'Annakut 2026',
            'is_active' => true, 'created_by' => $admin->id,
        ]);

        Storage::disk('media')->put('uploads/documents/original.pdf', self::V1);
        $media = MediaFile::create(array_replace([
            'name' => 'Poster-v1.pdf', 'type' => 'document', 'size' => strlen(self::V1),
            'department_id' => $department->id, 'folder_id' => $folder->id,
            'category_id' => Category::firstOrCreate(['name' => 'Events'])->id,
            'access_policy' => 'public', 'download_allowed' => true, 'tags' => ['annakut'],
            'file_path' => 'uploads/documents/original.pdf',
            'uploaded_by' => $operator->id, 'owner_user_id' => $operator->id,
            'asset_status' => 'active',
        ], $overrides));

        return compact('department', 'operator', 'admin', 'folder', 'media');
    }

    private function replace(User $actor, MediaFile $media, string $note = 'Corrected final poster text')
    {
        return $this->actingAs($actor)->post('/files/'.$media->id.'/versions', [
            'file' => UploadedFile::fake()->createWithContent('Poster-v2.pdf', self::V2),
            'change_note' => $note,
        ]);
    }

    // ------------------------------------------------- one asset, one identity

    public function test_replacing_a_version_keeps_one_logical_asset(): void
    {
        $f = $this->asset();
        $before = $f['media']->only(['id', 'folder_id', 'department_id', 'category_id', 'access_policy', 'download_allowed', 'owner_user_id', 'uploaded_by']);

        $this->replace($f['operator'], $f['media'])->assertSuccessful();

        $after = $f['media']->fresh();
        foreach ($before as $key => $value) {
            $this->assertSame($value, $after->$key, "Replacing a version must not change {$key}.");
        }
        $this->assertSame(1, MediaFile::count(), 'A replacement adds a version, not a second asset.');
    }

    public function test_the_asset_stays_in_its_folder_and_the_pane_still_lists_it(): void
    {
        $f = $this->asset();
        $this->replace($f['operator'], $f['media'])->assertSuccessful();

        $listing = $this->actingAs($f['admin'])
            ->getJson('/folders?department_id='.$f['department']->id.'&folder_id='.$f['folder']->id)
            ->assertSuccessful();

        $this->assertCount(1, $listing->json('files'));
        $this->assertSame('Poster-v2.pdf', $listing->json('files.0.name'), 'The pane shows the current version.');
        $tree = collect($listing->json('folders'))->firstWhere('id', $f['folder']->id);
        $this->assertSame(1, $tree['file_count'], 'One asset, counted once.');
    }

    public function test_a_protected_asset_is_still_protected_after_replacement(): void
    {
        $f = $this->asset(['access_policy' => 'protected', 'download_allowed' => false]);

        $this->replace($f['operator'], $f['media'])->assertSuccessful();

        $after = $f['media']->fresh();
        $this->assertSame('protected', $after->access_policy);
        $this->assertFalse((bool) $after->download_allowed);
    }

    // ------------------------------------- the version carries actor, date, note

    public function test_the_new_version_records_who_when_and_why(): void
    {
        $f = $this->asset();
        $this->replace($f['operator'], $f['media'], 'Corrected final poster text')->assertSuccessful();

        $version = $f['media']->versions()->where('version_number', 2)->firstOrFail();
        $this->assertSame($f['operator']->id, $version->changed_by);
        $this->assertSame('Corrected final poster text', $version->change_note);
        $this->assertNotNull($version->created_at);
        $this->assertSame('Poster-v2.pdf', $version->original_name);
        $this->assertSame(strlen(self::V2), (int) $version->size);
    }

    public function test_a_replacement_without_a_change_note_is_refused(): void
    {
        $f = $this->asset();

        $this->actingAs($f['operator'])->postJson('/files/'.$f['media']->id.'/versions', [
            'file' => UploadedFile::fake()->createWithContent('Poster-v2.pdf', self::V2),
        ])->assertStatus(422);

        $this->assertSame(1, (int) $f['media']->fresh()->current_version);
    }

    // ------------------------------- the current version is what is served

    public function test_download_serves_the_new_version_and_the_history_serves_the_old(): void
    {
        $f = $this->asset();
        $this->replace($f['operator'], $f['media'])->assertSuccessful();
        $media = $f['media']->fresh();

        $current = $this->actingAs($f['admin'])->get('/files/'.$media->id.'/download')->assertSuccessful();
        $this->assertSame(self::V2, $current->streamedContent(), 'A download must produce the current version.');

        $v1 = $media->versions()->where('version_number', 1)->firstOrFail();
        $historic = $this->actingAs($f['admin'])
            ->get('/files/'.$media->id.'/versions/'.$v1->id.'/download')
            ->assertSuccessful();
        $this->assertSame(self::V1, $historic->streamedContent(), 'Version one is still downloadable.');
    }

    public function test_preview_serves_the_new_version(): void
    {
        $f = $this->asset();
        $this->replace($f['operator'], $f['media'])->assertSuccessful();

        $preview = $this->actingAs($f['admin'])->get('/files/'.$f['media']->id.'/preview')->assertSuccessful();
        $this->assertSame(self::V2, $preview->streamedContent());
    }

    public function test_the_history_lists_both_versions_with_the_current_one_marked(): void
    {
        $f = $this->asset();
        $this->replace($f['operator'], $f['media'])->assertSuccessful();

        $history = $this->actingAs($f['admin'])
            ->getJson('/files/'.$f['media']->id.'/versions')
            ->assertSuccessful();

        $this->assertSame(2, $history->json('current_version'));
        $rows = collect($history->json('versions'));
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->firstWhere('version_number', 2)['is_current']);
        $this->assertFalse($rows->firstWhere('version_number', 1)['is_current']);
        $this->assertSame($f['operator']->name, $rows->firstWhere('version_number', 2)['changed_by']);
    }

    public function test_the_superseded_bytes_are_kept_not_overwritten(): void
    {
        $f = $this->asset();
        $this->replace($f['operator'], $f['media'])->assertSuccessful();

        $media = $f['media']->fresh();
        $this->assertNotSame('uploads/documents/original.pdf', $media->file_path, 'The new version is a new object.');
        Storage::disk('media')->assertExists('uploads/documents/original.pdf');
        $this->assertSame(self::V1, Storage::disk('media')->get('uploads/documents/original.pdf'));
    }

    // ------------------------------------------------------------- restoring

    public function test_restoring_version_one_adds_a_third_version_and_keeps_the_history(): void
    {
        $f = $this->asset();
        $this->replace($f['operator'], $f['media'])->assertSuccessful();
        $v1 = $f['media']->versions()->where('version_number', 1)->firstOrFail();

        $this->actingAs($f['admin'])
            ->postJson('/files/'.$f['media']->id.'/versions/'.$v1->id.'/restore', ['change_note' => 'Back to the approved artwork'])
            ->assertSuccessful();

        $media = $f['media']->fresh();
        $this->assertSame(3, (int) $media->current_version);
        $this->assertSame(3, $media->versions()->count(), 'Restoring adds a version; it never removes one.');

        $download = $this->actingAs($f['admin'])->get('/files/'.$media->id.'/download')->assertSuccessful();
        $this->assertSame(self::V1, $download->streamedContent(), 'The restored asset serves the old bytes again.');

        $v3 = $media->versions()->where('version_number', 3)->firstOrFail();
        $this->assertSame($v1->id, $v3->restored_from_version_id);
        $this->assertSame($f['admin']->id, $v3->changed_by);
    }

    // ------------------------------------------------------------ permissions

    public function test_someone_outside_the_department_cannot_replace_the_version(): void
    {
        $f = $this->asset();
        $other = Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);
        $stranger = User::factory()->create([
            'role' => 'department-operator', 'department_id' => $other->id, 'status' => 'active',
        ]);

        $this->replace($stranger, $f['media'])->assertStatus(403);
        $this->assertSame(1, (int) $f['media']->fresh()->current_version);
    }

    public function test_a_viewer_cannot_replace_the_version(): void
    {
        $f = $this->asset();
        $viewer = User::factory()->create([
            'role' => 'viewer', 'department_id' => $f['department']->id, 'status' => 'active',
        ]);

        $this->replace($viewer, $f['media'])->assertStatus(403);
    }

    public function test_a_replacement_of_another_type_is_refused(): void
    {
        $f = $this->asset();

        $this->actingAs($f['operator'])->postJson('/files/'.$f['media']->id.'/versions', [
            'file' => UploadedFile::fake()->createWithContent('Poster-v2.mp4', self::V2),
            'change_note' => 'Wrong kind of file',
        ])->assertStatus(422);

        $this->assertSame(1, (int) $f['media']->fresh()->current_version);
    }

    // ------------------------------------------- the library agrees with itself

    /**
     * The asset takes the new file's name, so the search index has to hear
     * about it in the request. Leaving it to ProcessMediaFile meant the
     * library answered to the superseded name until a queue worker ran, and
     * whether one runs on their host is still unconfirmed.
     */
    public function test_the_search_index_is_updated_without_waiting_for_the_queue(): void
    {
        Http::fake();
        Queue::fake();
        $f = $this->asset();

        $this->replace($f['operator'], $f['media'])->assertSuccessful();

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());
            return str_contains($request->url(), '/documents')
                && str_contains((string) $body, 'Poster-v2.pdf');
        });
    }

    public function test_restoring_a_version_reindexes_too(): void
    {
        $f = $this->asset();
        $this->replace($f['operator'], $f['media'])->assertSuccessful();
        $v1 = $f['media']->versions()->where('version_number', 1)->firstOrFail();

        Http::fake();
        Queue::fake();
        $this->actingAs($f['admin'])
            ->postJson('/files/'.$f['media']->id.'/versions/'.$v1->id.'/restore', ['change_note' => 'Back to the approved artwork'])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/documents')
            && str_contains((string) json_encode($request->data()), 'Poster-v1.pdf'));
    }

    // -------------------------------------- alongside the editing fixes (F09)

    /**
     * The acceptance asks for this to be retested "after the folder and editing
     * fixes", so the two are exercised together: a bulk edit of a replaced
     * asset must not disturb its version history or which version is current.
     */
    public function test_a_bulk_edit_after_a_replacement_leaves_the_history_alone(): void
    {
        $f = $this->asset();
        $this->replace($f['operator'], $f['media'])->assertSuccessful();

        $this->actingAs($f['admin'])->patchJson('/files/bulk-edit', [
            'ids' => [$f['media']->id],
            'tags_mode' => 'add',
            'tags' => ['reviewed'],
        ])->assertSuccessful();

        $media = $f['media']->fresh();
        $this->assertSame(2, (int) $media->current_version);
        $this->assertSame(2, $media->versions()->count());
        $this->assertContains('reviewed', $media->tags ?? []);
        $this->assertSame($f['folder']->id, $media->folder_id);

        $download = $this->actingAs($f['admin'])->get('/files/'.$media->id.'/download')->assertSuccessful();
        $this->assertSame(self::V2, $download->streamedContent());
    }

    // ------------------------------ an asset that predates the version history

    /**
     * Found by running the retest against the real library: a file seeded
     * before `MediaFile::created` recorded version 1 had no history at all, so
     * replacing it produced a history of exactly one row - the new one - and
     * the original bytes were left on disk with nothing pointing at them.
     */
    public function test_an_asset_with_no_history_keeps_its_original_when_replaced(): void
    {
        $f = $this->asset();
        // Exactly the state of a row inserted before the hook existed.
        $f['media']->versions()->delete();
        $this->assertSame(0, $f['media']->versions()->count());

        $this->replace($f['operator'], $f['media'])->assertSuccessful();

        $media = $f['media']->fresh();
        $this->assertSame(2, $media->versions()->count(), 'The superseded file must be recorded, not dropped.');
        $v1 = $media->versions()->where('version_number', 1)->firstOrFail();
        $this->assertSame('uploads/documents/original.pdf', $v1->file_path);
        $this->assertSame('Poster-v1.pdf', $v1->original_name);

        $historic = $this->actingAs($f['admin'])
            ->get('/files/'.$media->id.'/versions/'.$v1->id.'/download')
            ->assertSuccessful();
        $this->assertSame(self::V1, $historic->streamedContent(), 'And it is reachable again.');
    }

    public function test_such_an_asset_can_be_restored_to_its_original(): void
    {
        $f = $this->asset();
        $f['media']->versions()->delete();
        $this->replace($f['operator'], $f['media'])->assertSuccessful();

        $v1 = $f['media']->fresh()->versions()->where('version_number', 1)->firstOrFail();
        $this->actingAs($f['admin'])
            ->postJson('/files/'.$f['media']->id.'/versions/'.$v1->id.'/restore', ['change_note' => 'Back to the original'])
            ->assertSuccessful();

        $download = $this->actingAs($f['admin'])->get('/files/'.$f['media']->id.'/download')->assertSuccessful();
        $this->assertSame(self::V1, $download->streamedContent());
    }
}
