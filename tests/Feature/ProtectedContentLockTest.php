<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MediaAccessRequest;
use App\Models\MediaFile;
use App\Models\User;
use App\Services\MediaAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-02: protected content metadata may be visible, but the content itself stays
 * locked until an access request is approved. UAT C23 reported that a plain
 * viewer inside the owning department could open protected content directly.
 */
class ProtectedContentLockTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = Department::create(['name' => 'Audio Video', 'is_active' => true, 'is_system' => false]);
        $other = Department::create(['name' => 'Website', 'is_active' => true, 'is_system' => false]);
        $deptAdmin = User::factory()->create(['role' => 'department-admin', 'department_id' => $owner->id, 'status' => 'active']);
        $uploader = User::factory()->create(['role' => 'department-operator', 'department_id' => $owner->id, 'status' => 'active']);
        $insideViewer = User::factory()->create(['role' => 'viewer', 'department_id' => $owner->id, 'status' => 'active']);
        $outsideViewer = User::factory()->create(['role' => 'viewer', 'department_id' => $other->id, 'status' => 'active']);
        $superAdmin = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);

        $media = MediaFile::create([
            'name' => 'protected.mp4', 'type' => 'video', 'size' => 100,
            'department_id' => $owner->id, 'access_policy' => 'protected',
            'download_allowed' => true, 'tags' => [],
            'uploaded_by' => $uploader->id, 'owner_user_id' => $uploader->id,
        ]);

        return compact('owner', 'other', 'deptAdmin', 'uploader', 'insideViewer', 'outsideViewer', 'superAdmin', 'media');
    }

    public function test_viewer_in_owning_department_sees_metadata_but_not_content(): void
    {
        $f = $this->fixture();
        $access = app(MediaAccessService::class);

        $this->assertTrue($access->canSeeMetadata($f['media'], $f['insideViewer']), 'Metadata stays visible.');
        $this->assertFalse($access->canPreview($f['media'], $f['insideViewer']), 'Protected content must stay locked.');
        $this->assertFalse($access->canDownload($f['media'], $f['insideViewer']));
    }

    public function test_viewer_in_owning_department_can_request_access(): void
    {
        $f = $this->fixture();
        $this->assertTrue(app(MediaAccessService::class)->canRequest($f['media'], $f['insideViewer']));
    }

    public function test_approved_request_unlocks_content_for_department_viewer(): void
    {
        $f = $this->fixture();
        MediaAccessRequest::create([
            'media_file_id' => $f['media']->id, 'user_id' => $f['insideViewer']->id,
            'reason' => 'Needed for the weekly sabha slideshow.',
            'access_level' => 'view', 'status' => 'approved',
        ]);

        $access = app(MediaAccessService::class);
        $this->assertTrue($access->canPreview($f['media']->fresh(), $f['insideViewer']));
        $this->assertFalse($access->canDownload($f['media']->fresh(), $f['insideViewer']), 'View-only approval grants no download.');
    }

    public function test_custodians_keep_direct_access(): void
    {
        $f = $this->fixture();
        $access = app(MediaAccessService::class);

        foreach (['superAdmin' => 'Super Admin', 'deptAdmin' => 'Owning department admin', 'uploader' => 'Uploader'] as $key => $label) {
            $this->assertTrue($access->canPreview($f['media'], $f[$key]), "{$label} should keep direct access.");
            $this->assertFalse($access->canRequest($f['media'], $f[$key]), "{$label} has nothing to request.");
        }
    }

    public function test_outside_viewer_is_still_locked_out(): void
    {
        $f = $this->fixture();
        $access = app(MediaAccessService::class);

        $this->assertTrue($access->canSeeMetadata($f['media'], $f['outsideViewer']));
        $this->assertFalse($access->canPreview($f['media'], $f['outsideViewer']));
        $this->assertTrue($access->canRequest($f['media'], $f['outsideViewer']));
    }

    public function test_public_and_private_policies_are_unchanged(): void
    {
        $f = $this->fixture();
        $access = app(MediaAccessService::class);

        $f['media']->update(['access_policy' => 'public']);
        $this->assertTrue($access->canPreview($f['media']->fresh(), $f['insideViewer']));
        $this->assertTrue($access->canPreview($f['media']->fresh(), $f['outsideViewer']));

        $f['media']->update(['access_policy' => 'private']);
        $this->assertTrue($access->canPreview($f['media']->fresh(), $f['insideViewer']), 'Private stays open inside the owning department.');
        $this->assertFalse($access->canSeeMetadata($f['media']->fresh(), $f['outsideViewer']), 'Private is hidden outside it.');
    }
}
