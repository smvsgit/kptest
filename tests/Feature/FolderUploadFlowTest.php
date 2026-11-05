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
use Tests\TestCase;

/**
 * The folder workspace as the screen actually uses it: a Super Admin opening
 * it, and a large file arriving through the chunk endpoint while keeping its
 * folder and its deferred metadata.
 */
class FolderUploadFlowTest extends TestCase
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

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super-admin', 'department_id' => null, 'status' => 'active']);
    }

    private function operator(): User
    {
        return User::factory()->create([
            'role' => 'department-operator',
            'department_id' => $this->department()->id,
            'status' => 'active',
        ]);
    }

    private function category(): Category
    {
        return Category::firstOrCreate(['name' => 'Events']);
    }

    // ------------------------------------------- Super Admin without a department

    public function test_a_super_admin_with_no_department_can_still_open_the_workspace(): void
    {
        $this->department();

        $response = $this->actingAs($this->superAdmin())->getJson('/folders')->assertSuccessful();

        $this->assertNotNull($response->json('department_id'), 'The workspace must open on some department.');
        $this->assertNotEmpty($response->json('departments'), 'A Super Admin is offered the departments to switch between.');
    }

    public function test_a_super_admin_can_open_a_named_department(): void
    {
        $this->department();
        $other = Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);

        $this->actingAs($this->superAdmin())
            ->getJson('/folders?department_id='.$other->id)
            ->assertSuccessful()
            ->assertJsonPath('department_id', $other->id);
    }

    public function test_an_operator_is_not_offered_other_departments(): void
    {
        $this->actingAs($this->operator())
            ->getJson('/folders')
            ->assertSuccessful()
            ->assertJsonPath('departments', []);
    }

    // ------------------------------------------------------ chunked folder upload

    /** Send a whole small file as a single, final chunk. */
    private function sendSingleChunk(User $user, string $name, array $extra = [])
    {
        return $this->actingAs($user)->post('/files/chunk', array_replace([
            'upload_id' => (string) \Illuminate\Support\Str::uuid(),
            'chunk_index' => 0,
            'total_chunks' => 1,
            'total_size' => 11,
            'chunk' => UploadedFile::fake()->createWithContent('part', 'hello world'),
            'is_last_chunk' => 'true',
            'filename' => $name,
            'category_id' => $this->category()->id,
            'access_policy' => 'public',
        ], $extra));
    }

    public function test_a_chunked_upload_keeps_the_folder_it_came_from(): void
    {
        Storage::fake('media');
        $user = $this->operator();

        $this->sendSingleChunk($user, 'Aarti.mp4', [
            'folder_path' => 'Annakut 2024/Videos/Aarti.mp4',
            'defer_metadata' => '1',
        ])->assertSuccessful();

        $videos = MediaFolder::where('name', 'Videos')->firstOrFail();
        $annakut = MediaFolder::where('name', 'Annakut 2024')->firstOrFail();

        $this->assertSame($annakut->id, $videos->parent_id);
        $this->assertSame($videos->id, MediaFile::firstOrFail()->folder_id);
    }

    public function test_a_chunked_upload_may_defer_its_metadata_and_stays_a_draft(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => ['year', 'language_id']]);
        $user = $this->operator();

        $this->sendSingleChunk($user, 'Big.mp4', [
            'defer_metadata' => '1',
            'asset_status' => 'active',
        ])->assertSuccessful();

        $this->assertSame('draft', MediaFile::firstOrFail()->asset_status);
    }

    public function test_a_chunked_upload_without_the_defer_flag_still_demands_metadata(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => ['year', 'language_id']]);

        $this->sendSingleChunk($this->operator(), 'Big.mp4')->assertStatus(422);
        $this->assertSame(0, MediaFile::count());
    }

    /*
     * The two below send exactly what the folder workspace's tray sends, and
     * nothing more. The tests above all passed a category because their helper
     * supplies one, which is why a deferred upload could be refused for
     * "The category id field is required." without the suite noticing.
     */

    public function test_the_tray_payload_uploads_without_a_category(): void
    {
        Storage::fake('media');

        $this->actingAs($this->operator())->post('/files', [
            'file' => UploadedFile::fake()->image('TrayProbe.jpg'),
            'access_policy' => 'protected',
            'download_allowed' => '1',
            'defer_metadata' => '1',
        ])->assertSuccessful();

        $media = MediaFile::firstOrFail();
        $this->assertNull($media->category_id, 'A deferred upload has no category until it is described.');
        $this->assertSame('draft', $media->asset_status);
    }

    public function test_the_tray_payload_uploads_in_chunks_without_a_category(): void
    {
        Storage::fake('media');

        $this->actingAs($this->operator())->post('/files/chunk', [
            'upload_id' => (string) \Illuminate\Support\Str::uuid(),
            'chunk_index' => 0,
            'total_chunks' => 1,
            'total_size' => 11,
            'chunk' => UploadedFile::fake()->createWithContent('part', 'hello world'),
            'is_last_chunk' => 'true',
            'filename' => 'TrayProbe.mp4',
            'access_policy' => 'protected',
            'download_allowed' => '1',
            'defer_metadata' => '1',
        ])->assertSuccessful();

        $media = MediaFile::firstOrFail();
        $this->assertNull($media->category_id);
        $this->assertSame('draft', $media->asset_status);
    }

    /**
     * Without the defer flag a category is still demanded, as before.
     *
     * Posted as JSON because that is how the portal uploads: both paths go
     * through XHR, never an HTML form submit.
     */
    public function test_a_plain_upload_without_a_category_is_still_refused(): void
    {
        Storage::fake('media');

        $this->actingAs($this->operator())
            ->postJson('/files', [
                'file' => UploadedFile::fake()->image('NoCategory.jpg'),
                'access_policy' => 'protected',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        $this->assertSame(0, MediaFile::count());
    }
}
