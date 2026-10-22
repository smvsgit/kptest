<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * UAT C21/C22: an uploaded image downloaded fine but showed a broken preview.
 * Thumbnails are produced by a queued job, so whenever that job has not run the
 * picture must still be viewable from the original file.
 */
class MediaPreviewUrlTest extends TestCase
{
    use RefreshDatabase;

    private function image(array $overrides = []): MediaFile
    {
        $department = Department::firstOrCreate(
            ['name' => 'Audio Video'],
            ['is_active' => true, 'is_system' => false],
        );

        return MediaFile::create(array_merge([
            'name' => 'sample.png', 'type' => 'image', 'size' => 120,
            'file_path' => 'uat/sample.png', 'thumbnail_path' => null,
            'department_id' => $department->id, 'access_policy' => 'public',
            'download_allowed' => true, 'tags' => [],
        ], $overrides));
    }

    public function test_image_without_thumbnail_still_exposes_a_preview_url(): void
    {
        $media = $this->image();

        $this->assertNull($media->thumbnail_url, 'No thumbnail has been generated yet.');
        $this->assertNotNull($media->image_url, 'The original image must still be viewable.');
        $this->assertStringContainsString("/files/{$media->id}/preview", $media->image_url);
    }

    public function test_image_url_is_null_for_non_images_and_for_missing_files(): void
    {
        $this->assertNull($this->image(['type' => 'document', 'name' => 'note.pdf'])->image_url);
        $this->assertNull($this->image(['file_path' => null])->image_url);
    }

    public function test_image_url_is_serialized_for_the_frontend(): void
    {
        $media = $this->image();
        $this->assertArrayHasKey('image_url', $media->toArray());
    }

    public function test_preview_endpoint_serves_the_original_image(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('uat/sample.png', 'fake-png-bytes');

        $media = $this->image();
        $viewer = User::factory()->create(['role' => 'viewer', 'department_id' => $media->department_id, 'status' => 'active']);

        $this->actingAs($viewer)->get("/files/{$media->id}/preview")->assertOk();
    }
}
