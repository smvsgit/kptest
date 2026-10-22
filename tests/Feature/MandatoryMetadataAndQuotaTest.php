<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MasterDataValue;
use App\Models\MediaFile;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * UAT-037 (mandatory metadata) and UAT-040 (department quota / warning).
 * Neither row had been executed before.
 */
class MandatoryMetadataAndQuotaTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private function department(string $name = 'Audio Video'): Department
    {
        return Department::firstOrCreate(['name' => $name], ['is_active' => true, 'is_system' => false]);
    }

    private function category(): \App\Models\Category
    {
        return \App\Models\Category::firstOrCreate(['name' => 'Events']);
    }

    /** The upload endpoint always needs a category and an access policy. */
    private function payload(array $extra = []): array
    {
        return array_replace([
            'file' => UploadedFile::fake()->create('clip.mp4', 20, 'video/mp4'),
            'category_id' => $this->category()->id,
            'access_policy' => 'public',
        ], $extra);
    }

    private function operator(?Department $department = null): User
    {
        return User::factory()->create([
            'role' => 'department-operator',
            'department_id' => ($department ?? $this->department())->id,
            'status' => 'active',
        ]);
    }

    // ------------------------------------------- UAT-037 Mandatory metadata

    public function test_uat_037_upload_is_rejected_when_required_metadata_is_missing(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => ['year', 'event_id', 'language_id']]);
        $user = $this->operator();

        $response = $this->actingAs($user)->postJson('/files', $this->payload())->assertStatus(422);
        $this->assertStringContainsString('Required metadata missing', (string) $response->json('message'));
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_uat_037_year_outside_the_configured_range_is_rejected(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => ['year'], 'years_min' => 2000, 'years_max' => 2030]);
        $user = $this->operator();

        $this->actingAs($user)->postJson('/files', $this->payload(['year' => 1990]))->assertStatus(422);
    }

    public function test_uat_037_location_hierarchy_mismatch_is_rejected(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
        $india = MasterDataValue::create(['type' => 'country', 'name' => 'India', 'is_active' => true]);
        $other = MasterDataValue::create(['type' => 'country', 'name' => 'Kenya', 'is_active' => true]);
        $gujarat = MasterDataValue::create(['type' => 'state', 'name' => 'Gujarat', 'parent_id' => $india->id, 'is_active' => true]);

        $this->actingAs($this->operator())->postJson('/files', $this->payload([
            'country_id' => $other->id, 'state_id' => $gujarat->id,
        ]))->assertStatus(422);
    }

    /** The governed gate: common required metadata blocks approval/publishing. */
    public function test_uat_037_common_required_metadata_blocks_the_governed_transition(): void
    {
        SystemSetting::put('metadata.settings', ['required_fields' => ['year', 'language_id']]);
        SystemSetting::put('lifecycle.settings', ['transitions' => ['draft' => ['approved']]]);
        $admin = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);

        $media = MediaFile::create([
            'name' => 'clip.mp4', 'type' => 'video', 'size' => 10, 'asset_status' => 'draft',
            'department_id' => $this->department()->id, 'access_policy' => 'public',
            'download_allowed' => true, 'tags' => [], 'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($admin)->patchJson("/files/{$media->id}/lifecycle", ['status' => 'approved', 'note' => 'Ready.'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('draft', $media->fresh()->asset_status);

        $language = MasterDataValue::create(['type' => 'language', 'name' => 'Gujarati 2', 'is_active' => true]);
        $media->update(['year' => 2026, 'language_id' => $language->id]);

        $this->actingAs($admin)->patchJson("/files/{$media->id}/lifecycle", ['status' => 'approved', 'note' => 'Ready.'])
            ->assertOk();
        $this->assertSame('approved', $media->fresh()->asset_status);
    }

    /** Media-type-specific requirements apply on top of the common ones. */
    public function test_uat_037_media_type_specific_metadata_blocks_the_governed_transition(): void
    {
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
        SystemSetting::put('metadata.type_required', ['video' => ['event_id'], 'document' => []]);
        SystemSetting::put('lifecycle.settings', ['transitions' => ['draft' => ['approved']]]);
        $admin = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);

        $video = MediaFile::create([
            'name' => 'clip.mp4', 'type' => 'video', 'size' => 10, 'asset_status' => 'draft',
            'department_id' => $this->department()->id, 'access_policy' => 'public',
            'download_allowed' => true, 'tags' => [], 'uploaded_by' => $admin->id,
        ]);
        $document = MediaFile::create([
            'name' => 'note.pdf', 'type' => 'document', 'size' => 10, 'asset_status' => 'draft',
            'department_id' => $this->department()->id, 'access_policy' => 'public',
            'download_allowed' => true, 'tags' => [], 'uploaded_by' => $admin->id,
        ]);

        // Video needs an event; the document does not.
        $this->actingAs($admin)->patchJson("/files/{$video->id}/lifecycle", ['status' => 'approved', 'note' => 'Ready.'])
            ->assertStatus(422);
        $this->actingAs($admin)->patchJson("/files/{$document->id}/lifecycle", ['status' => 'approved', 'note' => 'Ready.'])
            ->assertOk();

        $event = MasterDataValue::create(['type' => 'event', 'name' => 'Annakut', 'is_active' => true]);
        $video->update(['event_id' => $event->id]);
        $this->actingAs($admin)->patchJson("/files/{$video->id}/lifecycle", ['status' => 'approved', 'note' => 'Ready.'])
            ->assertOk();
    }

    // ------------------------------------------------- UAT-040 Quota / warning

    public function test_uat_040_upload_is_refused_once_the_department_quota_is_reached(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
        $department = $this->department();
        SystemSetting::put('storage.quotas', [
            'default_quota_gb' => 0,
            'departments' => [(string) $department->id => 0.00001],   // ~10 KB
        ]);

        // Fill the department right up to its quota.
        MediaFile::create([
            'name' => 'existing.bin', 'type' => 'document', 'size' => 10500,
            'file_path' => 'uat/existing.bin', 'department_id' => $department->id,
            'access_policy' => 'public', 'download_allowed' => true, 'tags' => [],
        ]);

        $this->actingAs($this->operator($department))->postJson('/files', $this->payload([
            'file' => UploadedFile::fake()->create('more.pdf', 50, 'application/pdf'),
        ]))->assertStatus(422);

        $this->assertSame(1, MediaFile::count(), 'The over-quota upload must not be stored.');
    }

    public function test_uat_040_upload_succeeds_while_the_department_is_under_quota(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
        $department = $this->department();
        SystemSetting::put('storage.quotas', ['default_quota_gb' => 0, 'departments' => [(string) $department->id => 1]]);

        $this->actingAs($this->operator($department))->postJson('/files', $this->payload([
            'file' => UploadedFile::fake()->create('small.pdf', 20, 'application/pdf'),
        ]))->assertSuccessful();

        $this->assertSame(1, MediaFile::count());
    }

    public function test_uat_040_warning_threshold_is_reported_before_the_quota_is_reached(): void
    {
        $department = $this->department();
        SystemSetting::put('storage.quotas', [
            'default_quota_gb' => 0, 'warning_percent' => 80,
            'departments' => [(string) $department->id => 1],
        ]);
        MediaFile::create([
            'name' => 'big.bin', 'type' => 'document', 'size' => (int) (0.85 * self::GB),
            'file_path' => 'uat/big.bin', 'department_id' => $department->id,
            'access_policy' => 'public', 'download_allowed' => true, 'tags' => [],
        ]);

        $row = collect(app(\App\Services\StorageQuotaService::class)->overview())->firstWhere('name', $department->name);

        $this->assertSame('warning', $row['state'], 'Crossing the threshold must surface a warning, not a block.');
        $this->assertSame(85.0, $row['percent_used']);

        // A warning is not a block: an upload that still fits must go through.
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
        $this->actingAs($this->operator($department))->postJson('/files', $this->payload([
            'file' => UploadedFile::fake()->create('small.pdf', 20, 'application/pdf'),
        ]))->assertSuccessful();
    }

    public function test_uat_040_quota_is_scoped_to_the_uploading_department(): void
    {
        Storage::fake('media');
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
        $full = $this->department('Full Department');
        $free = $this->department('Free Department');
        SystemSetting::put('storage.quotas', [
            'default_quota_gb' => 0,
            'departments' => [(string) $full->id => 0.00001],
        ]);
        MediaFile::create([
            'name' => 'existing.bin', 'type' => 'document', 'size' => 10500,
            'file_path' => 'uat/existing.bin', 'department_id' => $full->id,
            'access_policy' => 'public', 'download_allowed' => true, 'tags' => [],
        ]);

        $this->actingAs($this->operator($full))->postJson('/files', $this->payload([
            'file' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'),
        ]))->assertStatus(422);

        $this->actingAs($this->operator($free))->postJson('/files', $this->payload([
            'file' => UploadedFile::fake()->create('b.pdf', 50, 'application/pdf'),
        ]))->assertSuccessful();
    }
}
