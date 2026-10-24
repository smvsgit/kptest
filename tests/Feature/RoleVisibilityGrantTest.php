<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MediaFile;
use App\Models\PortalRole;
use App\Models\User;
use App\Services\MediaAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Role-based visibility.
 *
 * Granting a portal role sight of an asset is explicit authorization: holders
 * of that role may open it wherever they sit in the organisation, without an
 * access request. Download still requires download_allowed, and only Super
 * Admin and Department Admin may widen visibility.
 */
class RoleVisibilityGrantTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $slug): PortalRole
    {
        return PortalRole::where('slug', $slug)->where('is_builtin', true)->firstOrFail();
    }

    private function fixture(string $policy = 'private'): array
    {
        $owner = Department::create(['name' => 'Audio Video', 'is_active' => true, 'is_system' => false]);
        $other = Department::create(['name' => 'Website', 'is_active' => true, 'is_system' => false]);

        $uploader = User::factory()->create(['role' => 'department-operator', 'department_id' => $owner->id, 'status' => 'active']);
        $outsider = User::factory()->create(['role' => 'viewer', 'department_id' => $other->id, 'status' => 'active']);
        $insider = User::factory()->create(['role' => 'viewer', 'department_id' => $owner->id, 'status' => 'active']);
        $otherOperator = User::factory()->create(['role' => 'department-operator', 'department_id' => $other->id, 'status' => 'active']);

        $media = MediaFile::create([
            'name' => 'granted.mp4', 'type' => 'video', 'size' => 100,
            'department_id' => $owner->id, 'access_policy' => $policy,
            'download_allowed' => true, 'tags' => [],
            'uploaded_by' => $uploader->id, 'owner_user_id' => $uploader->id,
        ]);

        return compact('owner', 'other', 'uploader', 'outsider', 'insider', 'otherOperator', 'media');
    }

    private function access(): MediaAccessService
    {
        return app(MediaAccessService::class);
    }

    // ------------------------------------------------------------- the grant

    public function test_granted_role_opens_a_private_asset_from_another_department(): void
    {
        $f = $this->fixture('private');
        $access = $this->access();

        $this->assertFalse($access->canSeeMetadata($f['media'], $f['outsider']), 'Private stays hidden before the grant.');

        $f['media']->visibleToRoles()->sync([$this->role('viewer')->id]);
        $media = $f['media']->fresh();

        $this->assertTrue($access->canSeeMetadata($media, $f['outsider']));
        $this->assertTrue($access->canPreview($media, $f['outsider']));
    }

    public function test_grant_unlocks_protected_content_without_a_request(): void
    {
        $f = $this->fixture('protected');
        $f['media']->visibleToRoles()->sync([$this->role('viewer')->id]);
        $media = $f['media']->fresh();

        $this->assertTrue($this->access()->canPreview($media, $f['outsider']));
        $this->assertFalse($this->access()->canRequest($media, $f['outsider']), 'Nothing to request once granted.');
    }

    public function test_a_different_role_is_not_granted(): void
    {
        $f = $this->fixture('private');
        $f['media']->visibleToRoles()->sync([$this->role('viewer')->id]);
        $media = $f['media']->fresh();

        // The operator in the other department holds a different role.
        $this->assertFalse($this->access()->canSeeMetadata($media, $f['otherOperator']));
        $this->assertFalse($this->access()->canPreview($media, $f['otherOperator']));
    }

    public function test_grant_respects_the_download_flag(): void
    {
        $f = $this->fixture('private');
        $f['media']->visibleToRoles()->sync([$this->role('viewer')->id]);

        $this->assertTrue($this->access()->canDownload($f['media']->fresh(), $f['outsider']));

        $f['media']->update(['download_allowed' => false]);
        $this->assertFalse($this->access()->canDownload($f['media']->fresh(), $f['outsider']), 'A grant must not override download_allowed.');
    }

    public function test_an_inactive_role_grants_nothing(): void
    {
        $f = $this->fixture('private');
        $custom = PortalRole::create([
            'name' => 'Archive Viewer', 'slug' => 'archive-viewer', 'base_role' => 'viewer',
            'is_builtin' => false, 'is_active' => true, 'permissions' => [], 'page_access' => ['browse' => true],
        ]);
        $f['outsider']->update(['portal_role_id' => $custom->id]);
        $f['media']->visibleToRoles()->sync([$custom->id]);

        $this->assertTrue($this->access()->canPreview($f['media']->fresh(), $f['outsider']->fresh()));

        $custom->update(['is_active' => false]);
        $this->assertFalse($this->access()->canPreview($f['media']->fresh(), $f['outsider']->fresh()), 'Deactivating the role withdraws the grant.');
    }

    public function test_a_custom_role_assignment_is_matched_over_the_base_role(): void
    {
        $f = $this->fixture('private');
        $custom = PortalRole::create([
            'name' => 'Media Reviewer', 'slug' => 'media-reviewer', 'base_role' => 'viewer',
            'is_builtin' => false, 'is_active' => true, 'permissions' => [], 'page_access' => ['browse' => true],
        ]);
        $f['outsider']->update(['portal_role_id' => $custom->id]);

        // Granting the built-in viewer role must not reach a user carrying the custom role.
        $f['media']->visibleToRoles()->sync([$this->role('viewer')->id]);
        $this->assertFalse($this->access()->canPreview($f['media']->fresh(), $f['outsider']->fresh()));

        $f['media']->visibleToRoles()->sync([$custom->id]);
        $this->assertTrue($this->access()->canPreview($f['media']->fresh(), $f['outsider']->fresh()));
    }

    public function test_revoking_the_grant_restores_the_policy(): void
    {
        $f = $this->fixture('private');
        $f['media']->visibleToRoles()->sync([$this->role('viewer')->id]);
        $this->assertTrue($this->access()->canPreview($f['media']->fresh(), $f['outsider']));

        $f['media']->visibleToRoles()->sync([]);
        $this->assertFalse($this->access()->canSeeMetadata($f['media']->fresh(), $f['outsider']));
    }

    // ------------------------------------------------------- browse listings

    /** @return array<int,int> ids the user would see in Browse */
    private function browseIds(User $user): array
    {
        return $this->access()->applyVisibility(MediaFile::query(), $user)->pluck('media_files.id')->all();
    }

    public function test_a_granted_private_asset_appears_in_browse_for_that_role(): void
    {
        $f = $this->fixture('private');

        $this->assertNotContains($f['media']->id, $this->browseIds($f['outsider']), 'Private is out of reach before the grant.');

        $f['media']->visibleToRoles()->sync([$this->role('viewer')->id]);

        $this->assertContains($f['media']->id, $this->browseIds($f['outsider']));
    }

    public function test_browse_still_hides_the_asset_from_other_roles(): void
    {
        $f = $this->fixture('private');
        $f['media']->visibleToRoles()->sync([$this->role('viewer')->id]);

        $this->assertNotContains($f['media']->id, $this->browseIds($f['otherOperator']));
        $this->assertContains($f['media']->id, $this->browseIds($f['insider']), 'The owning department still sees it.');
    }

    // -------------------------------------------------------------- upload

    public function test_department_admin_can_grant_roles_at_upload(): void
    {
        Storage::fake('media');
        \App\Models\SystemSetting::put('metadata.settings', ['required_fields' => []]);
        $department = Department::create(['name' => 'Audio Video', 'is_active' => true, 'is_system' => false]);
        $category = Category::create(['name' => 'Events']);
        $admin = User::factory()->create(['role' => 'department-admin', 'department_id' => $department->id, 'status' => 'active']);
        $viewerRole = $this->role('viewer');

        $this->actingAs($admin)->postJson('/files', [
            'file' => UploadedFile::fake()->create('clip.mp4', 20, 'video/mp4'),
            'category_id' => $category->id, 'access_policy' => 'private',
            'visible_role_ids' => [$viewerRole->id],
        ])->assertSuccessful();

        $media = MediaFile::firstOrFail();
        $this->assertTrue($media->visibleToRoles()->whereKey($viewerRole->id)->exists());
        $this->assertDatabaseHas('media_file_role', ['media_file_id' => $media->id, 'portal_role_id' => $viewerRole->id, 'granted_by' => $admin->id]);
    }

    public function test_an_operator_upload_carries_no_grants(): void
    {
        Storage::fake('media');
        \App\Models\SystemSetting::put('metadata.settings', ['required_fields' => []]);
        $department = Department::create(['name' => 'Audio Video', 'is_active' => true, 'is_system' => false]);
        $category = Category::create(['name' => 'Events']);
        $operator = User::factory()->create(['role' => 'department-operator', 'department_id' => $department->id, 'status' => 'active']);

        $this->actingAs($operator)->postJson('/files', [
            'file' => UploadedFile::fake()->create('clip.mp4', 20, 'video/mp4'),
            'category_id' => $category->id, 'access_policy' => 'private',
            'visible_role_ids' => [$this->role('viewer')->id],
        ])->assertSuccessful();

        $this->assertSame(0, \DB::table('media_file_role')->count(), 'Only Super Admin and Department Admin may widen visibility.');
    }

    public function test_an_unknown_or_inactive_role_is_rejected_at_upload(): void
    {
        Storage::fake('media');
        \App\Models\SystemSetting::put('metadata.settings', ['required_fields' => []]);
        $department = Department::create(['name' => 'Audio Video', 'is_active' => true, 'is_system' => false]);
        $category = Category::create(['name' => 'Events']);
        $admin = User::factory()->create(['role' => 'super-admin', 'department_id' => $department->id, 'status' => 'active']);

        $this->actingAs($admin)->postJson('/files', [
            'file' => UploadedFile::fake()->create('clip.mp4', 20, 'video/mp4'),
            'category_id' => $category->id, 'access_policy' => 'private',
            'visible_role_ids' => [99999],
        ])->assertStatus(422)->assertJsonValidationErrors('visible_role_ids.0');
    }

    // ------------------------------------------------------------ bulk edit

    public function test_bulk_edit_can_grant_and_revoke(): void
    {
        $f = $this->fixture('private');
        $admin = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);
        $viewerRole = $this->role('viewer');

        $this->actingAs($admin)->patchJson('/files/bulk-edit', [
            'ids' => [$f['media']->id], 'visible_role_ids' => [$viewerRole->id],
        ])->assertOk()
            // A grant-only change is still a change, so it must be reported.
            ->assertJsonPath('updated_ids', [$f['media']->id]);
        $this->assertTrue($f['media']->fresh()->visibleToRoles()->whereKey($viewerRole->id)->exists());
        $this->assertDatabaseHas('audit_logs', ['event' => 'media.role-visibility.updated']);

        $this->actingAs($admin)->patchJson('/files/bulk-edit', [
            'ids' => [$f['media']->id], 'visible_role_ids' => [],
        ])->assertOk();
        $this->assertSame(0, $f['media']->fresh()->visibleToRoles()->count());
    }

    public function test_operator_cannot_change_role_visibility_in_bulk(): void
    {
        $f = $this->fixture('private');

        $this->actingAs($f['uploader'])->patchJson('/files/bulk-edit', [
            'ids' => [$f['media']->id], 'visible_role_ids' => [$this->role('viewer')->id],
        ])->assertForbidden();

        $this->assertDatabaseCount('media_file_role', 0);
    }

    // ---------------------------------------------------------- housekeeping

    public function test_grants_disappear_with_the_asset(): void
    {
        $f = $this->fixture('private');
        $f['media']->visibleToRoles()->sync([$this->role('viewer')->id]);
        $this->assertDatabaseCount('media_file_role', 1);

        $f['media']->forceDelete();
        $this->assertDatabaseCount('media_file_role', 0);
    }

    public function test_assignable_roles_are_offered_only_to_admins(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['uploader'])->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('visibilityRoles', []));

        $admin = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);
        $this->actingAs($admin)->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('visibilityRoles', fn ($roles) => collect($roles)->isNotEmpty()));
    }
}
