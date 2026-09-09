<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LifecycleGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_bulk_metadata_edit_cannot_bypass_lifecycle_workflow(): void
    {
        $user = User::factory()->create(['role' => 'super-admin']);
        $media = MediaFile::create([
            'name' => 'governed.txt', 'type' => 'document', 'size' => 10,
            'asset_status' => 'draft', 'access_policy' => 'public', 'download_allowed' => true,
            'uploaded_by' => $user->id,
        ]);

        $this->actingAs($user)->patchJson('/files/bulk-edit', [
            'ids' => [$media->id],
            'metadata' => ['asset_status' => 'approved'],
        ])->assertUnprocessable()->assertJsonValidationErrors('metadata.asset_status');

        $this->assertSame('draft', $media->fresh()->asset_status);
    }

    public function test_controlled_transition_changes_status_and_records_history(): void
    {
        $user = User::factory()->create(['role' => 'super-admin']);
        SystemSetting::put('lifecycle.settings', ['transitions' => ['active' => ['review']]]);
        $media = MediaFile::create([
            'name' => 'legacy-active.txt', 'type' => 'document', 'size' => 10,
            'asset_status' => 'active', 'access_policy' => 'public', 'download_allowed' => true,
            'uploaded_by' => $user->id,
        ]);

        $this->actingAs($user)->patchJson("/files/{$media->id}/lifecycle", [
            'status' => 'review', 'note' => 'Ready for governed review.',
        ])->assertOk()->assertJsonPath('asset_status', 'review');

        $this->assertSame('review', $media->fresh()->asset_status);
        $this->assertDatabaseHas('media_status_histories', [
            'media_file_id' => $media->id,
            'from_status' => 'active',
            'to_status' => 'review',
            'changed_by' => $user->id,
        ]);
    }
}
