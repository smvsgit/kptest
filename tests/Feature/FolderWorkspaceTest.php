<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MasterDataValue;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\FolderPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The folder workspace: uploading a folder, organising the tree afterwards,
 * and describing assets once they are already stored.
 */
class FolderWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
    }

    private function department(string $name = 'Audio Video'): Department
    {
        return Department::firstOrCreate(['name' => $name], ['is_active' => true, 'is_system' => false]);
    }

    private function user(string $role = 'department-admin', ?Department $department = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => ($department ?? $this->department())->id,
            'status' => 'active',
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'file' => UploadedFile::fake()->create('clip.mp4', 20, 'video/mp4'),
            'category_id' => Category::firstOrCreate(['name' => 'Events'])->id,
            'access_policy' => 'public',
        ], $extra);
    }

    private function folder(string $name, ?MediaFolder $parent = null, ?Department $department = null): MediaFolder
    {
        return MediaFolder::create([
            'department_id' => ($department ?? $this->department())->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'is_active' => true,
            'created_by' => User::query()->value('id') ?? $this->user()->id,
        ]);
    }

    // ------------------------------------------------------- folder upload

    public function test_a_folder_upload_recreates_its_folder_chain(): void
    {
        Storage::fake('media');
        $user = $this->user();

        $this->actingAs($user)->postJson('/files', $this->payload([
            'file' => UploadedFile::fake()->create('01.mp4', 20, 'video/mp4'),
            'folder_path' => 'Annakut 2024/Photos/01.mp4',
        ]))->assertSuccessful();

        $photos = MediaFolder::where('name', 'Photos')->firstOrFail();
        $annakut = MediaFolder::where('name', 'Annakut 2024')->firstOrFail();

        $this->assertSame($annakut->id, $photos->parent_id);
        $this->assertNull($annakut->parent_id);
        $this->assertSame($photos->id, MediaFile::firstOrFail()->folder_id);
    }

    public function test_a_second_file_reuses_the_folders_the_first_one_created(): void
    {
        Storage::fake('media');
        $user = $this->user();

        foreach (['01.mp4', '02.mp4'] as $name) {
            $this->actingAs($user)->postJson('/files', $this->payload([
                'file' => UploadedFile::fake()->create($name, 20, 'video/mp4'),
                'folder_path' => 'Annakut 2024/Photos/'.$name,
            ]))->assertSuccessful();
        }

        $this->assertSame(2, MediaFolder::count(), 'The second file must land in the folders the first one made.');
        $this->assertSame(1, MediaFile::distinct()->count('folder_id'));
    }

    public function test_a_folder_path_cannot_climb_out_of_the_library(): void
    {
        $segments = app(FolderPathService::class)->segments('../../etc/Photos/01.jpg');
        $this->assertSame(['etc', 'Photos', '01.jpg'], $segments);
    }

    // -------------------------------------------------- deferred metadata

    public function test_an_upload_may_defer_metadata_and_lands_as_a_draft(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => ['year', 'language_id']]);

        $this->actingAs($this->user())->postJson('/files', $this->payload([
            'defer_metadata' => true,
            'asset_status' => 'active',
        ]))->assertSuccessful();

        $media = MediaFile::firstOrFail();
        $this->assertSame('draft', $media->asset_status, 'A deferred upload must not claim to be in the library yet.');
    }

    public function test_an_ordinary_upload_still_demands_the_required_metadata(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => ['year', 'language_id']]);

        $this->actingAs($this->user())->postJson('/files', $this->payload())->assertStatus(422);
        $this->assertSame(0, MediaFile::count());
    }

    public function test_a_deferred_draft_cannot_be_published_until_it_is_described(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => ['year']]);
        $user = $this->user();

        $this->actingAs($user)->postJson('/files', $this->payload(['defer_metadata' => true]))->assertSuccessful();
        $media = MediaFile::firstOrFail();

        $this->actingAs($user)
            ->patchJson("/files/{$media->id}/lifecycle", ['status' => 'review'])
            ->assertSuccessful();
        $this->actingAs($user)
            ->patchJson("/files/{$media->id}/lifecycle", ['status' => 'approved'])
            ->assertStatus(422);
    }

    // ------------------------------------------------------ moving assets

    public function test_assets_can_be_moved_into_a_folder_and_back_to_the_root(): void
    {
        Storage::fake('media');
        $user = $this->user();
        $this->actingAs($user)->postJson('/files', $this->payload())->assertSuccessful();
        $media = MediaFile::firstOrFail();
        $target = $this->folder('Archive 2024');

        $this->actingAs($user)
            ->postJson('/folders/move-files', ['media_ids' => [$media->id], 'folder_id' => $target->id])
            ->assertSuccessful();
        $this->assertSame($target->id, $media->fresh()->folder_id);

        $this->actingAs($user)
            ->postJson('/folders/move-files', ['media_ids' => [$media->id], 'folder_id' => null])
            ->assertSuccessful();
        $this->assertNull($media->fresh()->folder_id);
    }

    public function test_an_asset_cannot_be_moved_into_another_departments_folder(): void
    {
        Storage::fake('media');
        $user = $this->user();
        $this->actingAs($user)->postJson('/files', $this->payload())->assertSuccessful();
        $media = MediaFile::firstOrFail();

        $other = Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);
        $foreign = $this->folder('Their Folder', null, $other);

        $this->actingAs($user)
            // The folder is refused before the move is even considered.
            ->postJson('/folders/move-files', ['media_ids' => [$media->id], 'folder_id' => $foreign->id])
            ->assertStatus(403);
        $this->assertNull($media->fresh()->folder_id);
    }

    // ------------------------------------------------- folder-wide actions

    public function test_metadata_applied_to_a_folder_reaches_its_subfolders(): void
    {
        Storage::fake('media');
        $user = $this->user();
        $parent = $this->folder('Annakut 2024');
        $child = $this->folder('Photos', $parent);

        foreach ([$parent, $child] as $folder) {
            $this->actingAs($user)->postJson('/files', $this->payload([
                'file' => UploadedFile::fake()->create('f'.$folder->id.'.mp4', 20, 'video/mp4'),
                'folder_id' => $folder->id,
                'defer_metadata' => true,
            ]))->assertSuccessful();
        }

        $language = MasterDataValue::firstOrCreate(['type' => 'language', 'name' => 'Gujarati'], ['is_active' => true]);

        $this->actingAs($user)->postJson("/folders/{$parent->id}/metadata", [
            'metadata' => ['year' => 2024, 'language_id' => $language->id],
            'include_subfolders' => true,
        ])->assertSuccessful()->assertJsonPath('updated_ids', fn ($ids) => count($ids) === 2);

        $this->assertSame([2024, 2024], MediaFile::orderBy('id')->pluck('year')->map(fn ($y) => (int) $y)->all());
    }

    public function test_only_empty_leaves_values_an_asset_already_carries(): void
    {
        Storage::fake('media');
        $user = $this->user();
        $folder = $this->folder('Mixed');

        $this->actingAs($user)->postJson('/files', $this->payload([
            'folder_id' => $folder->id, 'year' => 2019, 'defer_metadata' => true,
        ]))->assertSuccessful();
        $this->actingAs($user)->postJson('/files', $this->payload([
            'file' => UploadedFile::fake()->create('b.mp4', 20, 'video/mp4'),
            'folder_id' => $folder->id, 'defer_metadata' => true,
        ]))->assertSuccessful();

        $this->actingAs($user)->postJson("/folders/{$folder->id}/metadata", [
            'metadata' => ['year' => 2024],
            'only_empty' => true,
        ])->assertSuccessful();

        $years = MediaFile::orderBy('id')->pluck('year')->map(fn ($y) => (int) $y)->all();
        $this->assertSame([2019, 2024], $years, 'A year already recorded must survive the folder pass.');
    }

    public function test_publishing_a_folder_sets_the_policy_for_everything_inside(): void
    {
        Storage::fake('media');
        $user = $this->user();
        $folder = $this->folder('Ready');
        $this->actingAs($user)->postJson('/files', $this->payload([
            'folder_id' => $folder->id, 'access_policy' => 'private', 'defer_metadata' => true,
        ]))->assertSuccessful();

        $this->actingAs($user)->postJson("/folders/{$folder->id}/publish", [
            'access_policy' => 'public',
            'asset_status' => 'active',
        ])->assertSuccessful();

        $media = MediaFile::firstOrFail();
        $this->assertSame('public', $media->access_policy);
        $this->assertSame('active', $media->asset_status);
    }

    public function test_publishing_cannot_activate_an_asset_that_is_still_undescribed(): void
    {
        Storage::fake('media');
        $user = $this->user();
        $folder = $this->folder('Undescribed');
        $this->actingAs($user)->postJson('/files', $this->payload([
            'folder_id' => $folder->id, 'defer_metadata' => true,
        ]))->assertSuccessful();

        SystemSetting::put('metadata.settings', ['required_fields' => ['year', 'language_id']]);

        $response = $this->actingAs($user)->postJson("/folders/{$folder->id}/publish", [
            'access_policy' => 'public',
            'asset_status' => 'active',
        ])->assertSuccessful();

        $this->assertCount(1, $response->json('blocked'));
        $media = MediaFile::firstOrFail();
        $this->assertSame('draft', $media->asset_status, 'An undescribed asset must not be pushed into the library.');
        $this->assertSame('public', $media->access_policy, 'The policy part of the action still applies.');
    }

    public function test_an_operator_cannot_publish_a_folder(): void
    {
        $folder = $this->folder('Ready');
        $this->actingAs($this->user('department-operator'))
            ->postJson("/folders/{$folder->id}/publish", ['access_policy' => 'public'])
            ->assertStatus(403);
    }

    // ---------------------------------------------------------- reading it

    public function test_the_tree_reports_depth_and_file_counts(): void
    {
        Storage::fake('media');
        $user = $this->user();
        $parent = $this->folder('Annakut 2024');
        $child = $this->folder('Photos', $parent);
        $this->actingAs($user)->postJson('/files', $this->payload(['folder_id' => $child->id]))->assertSuccessful();

        $tree = $this->actingAs($user)->getJson('/folders')->assertSuccessful()->json('folders');

        $byName = collect($tree)->keyBy('name');
        $this->assertSame(0, $byName['Annakut 2024']['depth']);
        $this->assertSame(1, $byName['Photos']['depth']);
        $this->assertSame(1, $byName['Photos']['file_count']);
        $this->assertSame(0, $byName['Annakut 2024']['file_count']);
    }

    public function test_opening_a_folder_lists_the_files_directly_inside_it(): void
    {
        Storage::fake('media');
        $user = $this->user();
        $folder = $this->folder('Photos');
        $this->actingAs($user)->postJson('/files', $this->payload([
            'folder_id' => $folder->id, 'defer_metadata' => true,
        ]))->assertSuccessful();

        $response = $this->actingAs($user)->getJson('/folders?folder_id='.$folder->id)->assertSuccessful();

        $this->assertSame('Photos', $response->json('current.name'));
        $this->assertCount(1, $response->json('files'));
        $this->assertTrue($response->json('files.0.needs_metadata'));
    }

    public function test_a_folder_in_another_department_cannot_be_opened(): void
    {
        $other = Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);
        $foreign = $this->folder('Theirs', null, $other);

        $this->actingAs($this->user())
            ->getJson('/folders?folder_id='.$foreign->id)
            ->assertStatus(403);
    }
}
