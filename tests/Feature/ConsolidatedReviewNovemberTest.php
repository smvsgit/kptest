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
 * Consolidated review, 24 November 2026. One test per defect it reproduced.
 *
 * F03  an upload took its owner department from the uploader, so a Super Admin
 *      (who belongs to no department) filled the system "Unassigned" department
 *      while the folder it went into belonged to a real one. The folder pane
 *      filters by department, so the file was invisible there — "Files (0)" —
 *      although the tree counted it.
 * F12  that drift also made a folder undeletable: it still held a file and a
 *      sub-folder that the pane never showed, and the refusal said only
 *      "not empty".
 * F09a Bulk Edit opened on a blank page, because a `useT()` call sat at module
 *      scope in the lazily-loaded dialog.
 */
class ConsolidatedReviewNovemberTest extends TestCase
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

    private function folder(Department $department, string $name = 'QAReview'): MediaFolder
    {
        return MediaFolder::create([
            'department_id' => $department->id,
            'parent_id' => null,
            'name' => $name,
            'is_active' => true,
            'created_by' => User::query()->value('id'),
        ]);
    }


    /** The upload policy decodes images, so a fake file would be refused. */
    private function realPng(string $name, int $w = 24, int $h = 12): UploadedFile
    {
        $image = imagecreatetruecolor($w, $h);
        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function sendSingleChunk(User $user, string $name, array $extra = [])
    {
        return $this->actingAs($user)->post('/files/chunk', array_replace([
            'upload_id' => (string) Str::uuid(),
            'chunk_index' => 0,
            'total_chunks' => 1,
            'total_size' => 11,
            'chunk' => UploadedFile::fake()->createWithContent('part', 'hello world'),
            'is_last_chunk' => 'true',
            'filename' => $name,
            'defer_metadata' => '1',
            'access_policy' => 'public',
        ], $extra));
    }

    // ------------------------------------------------------------------ F03

    public function test_f03_an_upload_into_a_folder_belongs_to_that_folders_department(): void
    {
        Storage::fake('media');
        $multimedia = $this->department();
        $folder = $this->folder($multimedia);

        $this->sendSingleChunk($this->superAdmin(), 'Poster.png', ['folder_id' => $folder->id])
            ->assertSuccessful();

        $file = MediaFile::firstOrFail();
        $this->assertSame($folder->id, $file->folder_id);
        $this->assertSame(
            $multimedia->id,
            $file->department_id,
            'The folder owns the asset; the uploader having no department must not send it to Unassigned.'
        );
    }

    public function test_f03_the_plain_upload_endpoint_follows_the_same_rule(): void
    {
        Storage::fake('media');
        $multimedia = $this->department();
        $folder = $this->folder($multimedia);

        $this->actingAs($this->superAdmin())->postJson('/files', [
            'file' => $this->realPng('Poster.png'),
            'category_id' => Category::firstOrCreate(['name' => 'Events'])->id,
            'access_policy' => 'public',
            'folder_id' => $folder->id,
        ])->assertSuccessful();

        $this->assertSame($multimedia->id, MediaFile::firstOrFail()->department_id);
    }

    public function test_f03_the_file_pane_shows_what_was_just_uploaded_into_the_folder(): void
    {
        Storage::fake('media');
        $multimedia = $this->department();
        $folder = $this->folder($multimedia);
        $admin = $this->superAdmin();

        $this->sendSingleChunk($admin, 'Poster.png', ['folder_id' => $folder->id])->assertSuccessful();

        $listing = $this->actingAs($admin)
            ->getJson('/folders?department_id='.$multimedia->id.'&folder_id='.$folder->id)
            ->assertSuccessful();

        $this->assertCount(1, $listing->json('files'), 'The pane must list the file the tree counts.');
        $this->assertSame('Poster.png', $listing->json('files.0.name'));
    }

    public function test_f03_the_pane_and_the_tree_count_agree_even_for_drifted_data(): void
    {
        $multimedia = $this->department();
        $folder = $this->folder($multimedia);
        $unassigned = Department::firstOrCreate(
            ['name' => 'Unassigned'],
            ['is_active' => true, 'is_system' => true]
        );

        // Exactly the row the old upload path produced.
        MediaFile::create([
            'name' => 'Stray.png', 'type' => 'image', 'size' => 11,
            'department_id' => $unassigned->id, 'folder_id' => $folder->id,
            'asset_status' => 'draft', 'access_policy' => 'public',
            'file_path' => 'uploads/images/stray.png', 'tags' => [],
        ]);

        $listing = $this->actingAs($this->superAdmin())
            ->getJson('/folders?department_id='.$multimedia->id.'&folder_id='.$folder->id)
            ->assertSuccessful();

        $tree = collect($listing->json('folders'))->firstWhere('id', $folder->id);
        $this->assertSame(1, $tree['file_count']);
        $this->assertCount(1, $listing->json('files'), 'A misfiled asset must be visible, not merely counted.');
    }

    public function test_f03_an_operator_may_not_upload_into_another_departments_folder(): void
    {
        Storage::fake('media');
        $other = Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);
        $folder = $this->folder($other, 'TheirFolder');

        $this->sendSingleChunk($this->operator(), 'Poster.png', ['folder_id' => $folder->id])
            ->assertStatus(403);

        $this->assertSame(0, MediaFile::count());
    }

    // ------------------------------------------------------------------ F12

    public function test_f12_deleting_a_folder_that_still_holds_things_says_what_blocks_it(): void
    {
        $multimedia = $this->department();
        $folder = $this->folder($multimedia);
        MediaFolder::create([
            'department_id' => $multimedia->id, 'parent_id' => $folder->id,
            'name' => 'Photos', 'is_active' => true, 'created_by' => User::query()->value('id'),
        ]);
        MediaFile::create([
            'name' => 'Stray.png', 'type' => 'image', 'size' => 11,
            'department_id' => $multimedia->id, 'folder_id' => $folder->id,
            'asset_status' => 'draft', 'access_policy' => 'public',
            'file_path' => 'uploads/images/stray.png', 'tags' => [],
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->deleteJson('/folders/'.$folder->id)
            ->assertStatus(422);

        $message = (string) $response->json('message');
        $this->assertStringContainsString('1', $message, 'The refusal must count what is in the way.');
        $this->assertDatabaseHas('media_folders', ['id' => $folder->id]);
    }

    public function test_f12_an_empty_folder_still_deletes(): void
    {
        $folder = $this->folder($this->department());

        $this->actingAs($this->superAdmin())->delete('/folders/'.$folder->id);

        $this->assertDatabaseMissing('media_folders', ['id' => $folder->id]);
    }

    // ----------------------------------------------------------------- F09a

    /**
     * A hook called outside a component body throws the moment its module is
     * evaluated. Every dialog here is lazily loaded, so that throw arrives with
     * no component mounted and React unmounts the whole page — a blank screen
     * with no way back but a reload. Neither tsc nor the bundler says a word
     * about it, so it is checked here.
     */
    public function test_f09_no_translation_hook_is_called_outside_a_component(): void
    {
        $offenders = [];
        $base = base_path('resources/js');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($files as $file) {
            if (!$file->isFile() || !preg_match('/\.(tsx|ts)$/', $file->getFilename())) continue;
            $lines = explode("\n", (string) file_get_contents($file->getPathname()));
            foreach ($lines as $i => $line) {
                if (!preg_match('/=\s*use(?:T|Tx|Translation)\(\)/', $line, $m, PREG_OFFSET_CAPTURE)) continue;
                // The body may open on the same line, as a one-line component does,
                // so the text before the call on that line counts towards the depth.
                $before = implode("\n", array_slice($lines, 0, $i)).substr($line, 0, $m[0][1]);
                if (substr_count($before, '{') <= substr_count($before, '}')) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'A translation hook outside a component body renders a blank page.');
    }

    /**
     * And the dialogs are wrapped, so the next failure inside one is a dialog
     * that says so rather than an empty portal.
     */
    public function test_f09_every_lazy_dialog_sits_inside_an_error_boundary(): void
    {
        $dashboard = (string) file_get_contents(base_path('resources/js/Pages/Dashboard.tsx'));
        $suspense = substr_count($dashboard, '<Suspense fallback={null}>');
        $boundaries = substr_count($dashboard, '<ModalErrorBoundary');

        $this->assertGreaterThan(0, $suspense);
        $this->assertSame($suspense, $boundaries, 'Each lazily loaded dialog needs its own boundary.');
    }


}
