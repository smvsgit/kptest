<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MediaAccessRequest;
use App\Models\MediaFile;
use App\Models\User;
use App\Services\MediaAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $ownerDept = Department::create(['name' => 'Audio Video', 'is_active' => true, 'is_system' => false]);
        $otherDept = Department::create(['name' => 'Website', 'is_active' => true, 'is_system' => false]);
        $category = Category::create(['name' => 'Events']);
        $ownerAdmin = User::factory()->create(['role' => 'department-admin', 'department_id' => $ownerDept->id]);
        $requester = User::factory()->create(['role' => 'viewer', 'department_id' => $otherDept->id]);
        $media = MediaFile::create([
            'name' => 'protected-video.mp4', 'type' => 'video', 'size' => 1000,
            'category_id' => $category->id, 'department_id' => $ownerDept->id,
            'access_policy' => 'protected', 'download_allowed' => true,
            'tags' => [], 'uploaded_by' => $ownerAdmin->id,
        ]);
        return compact('ownerDept', 'otherDept', 'ownerAdmin', 'requester', 'media');
    }

    public function test_protected_file_is_visible_but_locked_until_approved(): void
    {
        $f = $this->fixture();
        $access = app(MediaAccessService::class);
        $this->assertTrue($access->canSeeMetadata($f['media'], $f['requester']));
        $this->assertFalse($access->canPreview($f['media'], $f['requester']));
        $this->assertFalse($access->canDownload($f['media'], $f['requester']));
    }

    public function test_private_file_is_hidden_from_other_department(): void
    {
        $f = $this->fixture();
        $f['media']->update(['access_policy' => 'private']);
        $this->assertFalse(app(MediaAccessService::class)->canSeeMetadata($f['media']->fresh(), $f['requester']));
    }

    public function test_request_and_view_only_approval_do_not_grant_download(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['requester'])->postJson('/files/'.$f['media']->id.'/access-request', [
            'reason' => 'Need to review this media for website publishing.',
            'access_level' => 'view',
        ])->assertCreated();

        $request = MediaAccessRequest::firstOrFail();
        $this->actingAs($f['ownerAdmin'])->patchJson('/access-requests/'.$request->id, [
            'status' => 'approved', 'access_level' => 'view', 'decision_note' => 'Approved for review.',
        ])->assertOk();

        $media = $f['media']->fresh();
        $access = app(MediaAccessService::class);
        $this->assertTrue($access->canPreview($media, $f['requester']));
        $this->assertFalse($access->canDownload($media, $f['requester']));
    }

    public function test_owner_department_admin_can_review_but_other_admin_cannot(): void
    {
        $f = $this->fixture();
        $request = MediaAccessRequest::create([
            'media_file_id' => $f['media']->id, 'user_id' => $f['requester']->id,
            'access_level' => 'view', 'reason' => 'Need access for work.', 'status' => 'pending',
        ]);
        $otherAdmin = User::factory()->create(['role' => 'department-admin', 'department_id' => $f['otherDept']->id]);

        $this->actingAs($otherAdmin)->patchJson('/access-requests/'.$request->id, [
            'status' => 'approved', 'access_level' => 'view',
        ])->assertForbidden();
    }
}
