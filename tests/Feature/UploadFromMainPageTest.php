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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F04: "Add role-authorised Upload files and Upload folder actions on the main
 * page. Require or show the target folder; do not silently upload into an
 * unrelated root."
 *
 * The dialog that asks for the destination is front-end, and the browser pass
 * is the evidence for it. What is pinned down here is everything the server
 * must guarantee behind it: the list the dialog picks from, who may use it,
 * and that a destination once chosen is honoured rather than quietly swapped
 * for a root.
 */
class UploadFromMainPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
    }

    private function department(string $name = 'Multimedia'): Department
    {
        return Department::firstOrCreate(['name' => $name], ['is_active' => true, 'is_system' => false]);
    }

    private function folder(Department $department, string $name, ?int $parent = null): MediaFolder
    {
        return MediaFolder::create([
            'department_id' => $department->id, 'parent_id' => $parent, 'name' => $name,
            'is_active' => true, 'created_by' => User::query()->value('id'),
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super-admin', 'department_id' => null, 'status' => 'active']);
    }

    private function operator(?Department $department = null): User
    {
        return User::factory()->create([
            'role' => 'department-operator',
            'department_id' => ($department ?: $this->department())->id,
            'status' => 'active',
        ]);
    }

    private function upload(User $actor, ?int $folderId, string $name = 'Poster.png')
    {
        return $this->actingAs($actor)->post('/files/chunk', array_filter([
            'upload_id' => (string) Str::uuid(),
            'chunk_index' => 0,
            'total_chunks' => 1,
            'total_size' => 11,
            'chunk' => UploadedFile::fake()->createWithContent('part', 'hello world'),
            'is_last_chunk' => 'true',
            'filename' => $name,
            'defer_metadata' => '1',
            'access_policy' => 'public',
            'folder_id' => $folderId,
        ], fn ($v) => $v !== null));
    }

    // ------------------------------------------- the list the dialog offers

    public function test_the_dialog_can_read_the_folders_of_a_department(): void
    {
        $multimedia = $this->department();
        $annakut = $this->folder($multimedia, 'Annakut 2026');
        $this->folder($multimedia, 'Photos', $annakut->id);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/folders?department_id='.$multimedia->id)
            ->assertSuccessful();

        $names = collect($response->json('folders'))->pluck('name')->all();
        $this->assertContains('Annakut 2026', $names);
        $this->assertContains('Photos', $names);
        $this->assertSame(1, collect($response->json('folders'))->firstWhere('name', 'Photos')['depth'],
            'The dialog indents by depth, so depth has to be reported.');
    }

    public function test_an_operator_is_offered_only_their_own_department(): void
    {
        $this->department();
        Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);

        $response = $this->actingAs($this->operator())->getJson('/folders')->assertSuccessful();

        $this->assertSame([], $response->json('departments'),
            'With nothing to switch between, the dialog shows no department selector.');
        $this->assertSame($this->department()->id, $response->json('department_id'));
    }

    public function test_a_viewer_cannot_reach_the_upload_endpoint_at_all(): void
    {
        $viewer = User::factory()->create([
            'role' => 'viewer', 'department_id' => $this->department()->id, 'status' => 'active',
        ]);

        $this->upload($viewer, null)->assertStatus(403);
    }

    // --------------------------------- a chosen destination is the one used

    public function test_the_chosen_folder_is_where_the_file_lands(): void
    {
        Storage::fake('media');
        $multimedia = $this->department();
        $annakut = $this->folder($multimedia, 'Annakut 2026');
        $photos = $this->folder($multimedia, 'Photos', $annakut->id);

        $this->upload($this->superAdmin(), $photos->id)->assertSuccessful();

        $file = MediaFile::firstOrFail();
        $this->assertSame($photos->id, $file->folder_id, 'Not the root, not the parent: the folder that was picked.');
        $this->assertSame($multimedia->id, $file->department_id);
    }

    public function test_the_department_root_is_a_destination_in_its_own_right(): void
    {
        Storage::fake('media');
        $operator = $this->operator();

        $this->upload($operator, null)->assertSuccessful();

        $file = MediaFile::firstOrFail();
        $this->assertNull($file->folder_id);
        $this->assertSame($operator->department_id, $file->department_id);

        $listing = $this->actingAs($operator)->getJson('/folders')->assertSuccessful();
        $this->assertCount(1, $listing->json('files'), 'It shows at the root, where it was sent.');
    }

    public function test_a_folder_in_another_department_is_refused_not_redirected(): void
    {
        Storage::fake('media');
        $other = Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);
        $theirs = $this->folder($other, 'Their Folder');

        $this->upload($this->operator(), $theirs->id)->assertStatus(403);

        $this->assertSame(0, MediaFile::count(), 'Refused outright - never quietly moved to a root the user can reach.');
    }

    public function test_a_folder_upload_keeps_its_structure_under_the_chosen_folder(): void
    {
        Storage::fake('media');
        $multimedia = $this->department();
        $target = $this->folder($multimedia, 'Annakut 2026');

        $this->actingAs($this->superAdmin())->post('/files/chunk', [
            'upload_id' => (string) Str::uuid(),
            'chunk_index' => 0, 'total_chunks' => 1, 'total_size' => 11,
            'chunk' => UploadedFile::fake()->createWithContent('part', 'hello world'),
            'is_last_chunk' => 'true', 'filename' => 'Aarti.jpg',
            'folder_path' => 'Photos/Day 1/Aarti.jpg',
            'folder_id' => $target->id,
            'defer_metadata' => '1', 'access_policy' => 'public',
        ])->assertSuccessful();

        $day1 = MediaFolder::where('name', 'Day 1')->firstOrFail();
        $photos = MediaFolder::where('name', 'Photos')->firstOrFail();

        $this->assertSame($target->id, $photos->parent_id, 'The picked folder is the root of what was dropped in.');
        $this->assertSame($photos->id, $day1->parent_id);
        $this->assertSame($day1->id, MediaFile::firstOrFail()->folder_id);
        $this->assertSame($multimedia->id, MediaFile::firstOrFail()->department_id);
    }

    // ------------------------------------------------- the screen's own wiring

    /**
     * The actions are drawn only behind `canUpload`, and the destination
     * dialog is the only way into them from Browse. A regression here would
     * put an upload button in front of someone who cannot upload, or let a
     * batch start without a destination.
     */
    public function test_browse_draws_its_upload_actions_behind_the_permission(): void
    {
        $browse = (string) file_get_contents(base_path('resources/js/Components/panels/BrowsePanel.tsx'));
        $dashboard = (string) file_get_contents(base_path('resources/js/Pages/Dashboard.tsx'));
        $dialog = (string) file_get_contents(base_path('resources/js/Components/modals/UploadDestinationModal.tsx'));

        $this->assertStringContainsString("p.canUpload&&", $browse);
        $this->assertStringContainsString("onUploadFrom('files')", $browse);
        $this->assertStringContainsString("onUploadFrom('folder')", $browse);
        $this->assertStringContainsString('canUpload={canUpload}', $dashboard);
        // One queue for the whole portal, owned above both panels.
        $this->assertStringContainsString('<FolderUploadTray ref={trayRef}', $dashboard);
        $this->assertStringNotContainsString('<FolderUploadTray', (string) file_get_contents(base_path('resources/js/Components/panels/FoldersPanel.tsx')));
        // The picker cannot be used before a destination is chosen.
        $this->assertStringContainsString('disabled={!picked}', $dialog);
        $this->assertStringContainsString("t('upload.will_go_to')", $dialog);
    }
}
