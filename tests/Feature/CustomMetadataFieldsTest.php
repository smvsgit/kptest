<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\MetadataFieldDefinition;
use App\Models\PortalRole;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CustomMetadataService;
use App\Support\SettingsSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F09b: "Admin defines per-file-type fields, input type, required/default
 * values and editable versus locked status. Support adding/retiring fields
 * with validation and migration rules. Preserve system timestamps, IDs,
 * checksums and version audit data as immutable. A business date/year may be
 * configurable; do not confuse it with an immutable upload timestamp."
 *
 * The immutability half is the important half, so most of these tests are
 * about what a schema change cannot reach.
 */
class CustomMetadataFieldsTest extends TestCase
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

    private function operator(): User
    {
        return User::factory()->create([
            'role' => 'department-operator', 'department_id' => $this->department()->id, 'status' => 'active',
        ]);
    }

    private function field(array $overrides = []): MetadataFieldDefinition
    {
        return MetadataFieldDefinition::create(array_replace([
            'key' => 'reel_number', 'label' => 'Reel number', 'input_type' => 'text',
            'applies_to' => [], 'options' => [], 'is_required' => false, 'is_locked' => false,
            'is_active' => true, 'sort_order' => 0,
        ], $overrides));
    }

    private function fileInFolder(array $overrides = []): array
    {
        $department = $this->department();
        $folder = MediaFolder::create([
            'department_id' => $department->id, 'parent_id' => null, 'name' => 'Annakut 2026',
            'is_active' => true, 'created_by' => User::query()->value('id'),
        ]);
        $file = MediaFile::create(array_replace([
            'name' => 'Poster.png', 'type' => 'image', 'size' => 11,
            'department_id' => $department->id, 'folder_id' => $folder->id,
            'access_policy' => 'public', 'download_allowed' => true, 'tags' => [],
            'file_path' => 'uploads/images/one.png', 'asset_status' => 'active',
        ], $overrides));

        return [$department, $folder, $file];
    }

    private function describe(User $actor, MediaFolder $folder, array $custom, array $extra = [])
    {
        return $this->actingAs($actor)->postJson('/folders/'.$folder->id.'/metadata', array_replace([
            'metadata' => [],
            'custom_metadata' => $custom,
        ], $extra));
    }

    // ------------------------------------------------- defining the fields

    public function test_an_administrator_can_define_a_field(): void
    {
        $this->actingAs($this->superAdmin())->postJson('/settings/metadata/fields', [
            'label' => 'Reel number', 'input_type' => 'text', 'applies_to' => ['video'],
            'is_required' => true,
        ])->assertSuccessful();

        $field = MetadataFieldDefinition::firstOrFail();
        $this->assertSame('reel_number', $field->key, 'The key is derived from the label.');
        $this->assertSame(['video'], $field->applies_to);
        $this->assertTrue($field->is_required);
    }

    public function test_a_field_cannot_be_named_after_a_system_column(): void
    {
        foreach (['created_at', 'checksum_sha256', 'id', 'current_version'] as $reserved) {
            $this->actingAs($this->superAdmin())->postJson('/settings/metadata/fields', [
                'label' => $reserved, 'input_type' => 'text',
            ])->assertStatus(422);
        }

        $this->assertSame(0, MetadataFieldDefinition::count());
    }

    public function test_a_business_date_is_a_field_of_its_own_and_leaves_the_upload_timestamp_alone(): void
    {
        [, $folder, $file] = $this->fileInFolder();
        $this->field(['key' => 'broadcast_date', 'label' => 'Broadcast date', 'input_type' => 'date']);
        $uploadedAt = $file->created_at;

        $this->describe($this->operator(), $folder, ['broadcast_date' => '2026-01-09'])->assertSuccessful();

        $file->refresh();
        $this->assertSame('2026-01-09', $file->custom_metadata['broadcast_date']);
        $this->assertEquals($uploadedAt, $file->created_at, 'The upload timestamp is the system\'s and never moves.');
    }

    public function test_a_list_field_needs_choices(): void
    {
        $this->actingAs($this->superAdmin())->postJson('/settings/metadata/fields', [
            'label' => 'Condition', 'input_type' => 'select', 'options' => [],
        ])->assertStatus(422);
    }

    public function test_defining_a_field_needs_the_metadata_section(): void
    {
        $role = PortalRole::create([
            'name' => 'Uploader', 'slug' => 'uploader-only', 'base_role' => 'department-operator',
            'is_builtin' => false, 'is_active' => true, 'permissions' => [],
            'page_access' => ['browse' => true, 'settings' => true],
            'settings_access' => SettingsSections::normaliseMap(['search' => 'edit'], 'department-operator'),
        ]);
        $user = User::factory()->create([
            'role' => 'department-operator', 'portal_role_id' => $role->id,
            'department_id' => $this->department()->id, 'status' => 'active',
        ]);

        $this->actingAs($user)->postJson('/settings/metadata/fields', [
            'label' => 'Reel number', 'input_type' => 'text',
        ])->assertStatus(403);
    }

    // ------------------------------------------------------ using the fields

    public function test_a_value_is_written_through_describe(): void
    {
        [, $folder, $file] = $this->fileInFolder();
        $this->field();

        $this->describe($this->operator(), $folder, ['reel_number' => 'R-114'])->assertSuccessful();

        $this->assertSame('R-114', $file->fresh()->custom_metadata['reel_number']);
    }

    public function test_a_field_that_does_not_apply_to_this_type_is_ignored(): void
    {
        [, $folder, $file] = $this->fileInFolder(['type' => 'document', 'name' => 'Notes.pdf']);
        $this->field(['applies_to' => ['video']]);

        // A field that is not drawn for this type is dropped rather than
        // refused: the form never offered it, so sending it is a stale form,
        // not a decision the user made.
        $this->describe($this->operator(), $folder, ['reel_number' => 'R-114'])->assertSuccessful();

        $this->assertNull($file->fresh()->custom_metadata['reel_number'] ?? null);
    }

    public function test_a_number_field_refuses_text(): void
    {
        [, $folder] = $this->fileInFolder();
        $this->field(['key' => 'reel_count', 'label' => 'Reels', 'input_type' => 'number']);

        $this->describe($this->operator(), $folder, ['reel_count' => 'not a number'])->assertStatus(422);
    }

    public function test_a_list_field_refuses_a_value_that_is_not_on_the_list(): void
    {
        [, $folder] = $this->fileInFolder();
        $this->field(['key' => 'condition', 'label' => 'Condition', 'input_type' => 'select', 'options' => ['good', 'fair']]);

        $this->describe($this->operator(), $folder, ['condition' => 'excellent'])->assertStatus(422);
        $this->describe($this->operator(), $folder, ['condition' => 'fair'])->assertSuccessful();
    }

    public function test_a_locked_field_is_set_once_and_not_changed(): void
    {
        [, $folder, $file] = $this->fileInFolder();
        $this->field(['key' => 'accession_number', 'label' => 'Accession number', 'is_locked' => true]);

        $this->describe($this->operator(), $folder, ['accession_number' => 'A-1'])->assertSuccessful();
        $this->assertSame('A-1', $file->fresh()->custom_metadata['accession_number']);

        $this->describe($this->operator(), $folder, ['accession_number' => 'A-2'])->assertStatus(422);
        $this->assertSame('A-1', $file->fresh()->custom_metadata['accession_number'], 'A matter of record stays put.');
    }

    // ------------------------------------------------------ retiring a field

    public function test_retiring_a_field_keeps_every_value_already_recorded(): void
    {
        [, $folder, $file] = $this->fileInFolder();
        $field = $this->field();
        $this->describe($this->operator(), $folder, ['reel_number' => 'R-114'])->assertSuccessful();

        $this->actingAs($this->superAdmin())->patchJson('/settings/metadata/fields/'.$field->id, [
            'label' => $field->label, 'input_type' => $field->input_type, 'is_active' => false,
        ])->assertSuccessful();

        $this->assertSame('R-114', $file->fresh()->custom_metadata['reel_number'],
            'Retiring takes the field off the forms; it does not erase the record.');
        $this->assertCount(0, app(CustomMetadataService::class)->active('image'),
            'And it stops being asked for.');
    }

    public function test_a_retired_field_cannot_be_written_to_again(): void
    {
        [, $folder, $file] = $this->fileInFolder();
        $this->field(['is_active' => false]);

        // Same reasoning as a field of the wrong type: the retired field is no
        // longer offered, so a form that still sends it is simply stale.
        $this->describe($this->operator(), $folder, ['reel_number' => 'R-115'])->assertSuccessful();
        $this->assertNull($file->fresh()->custom_metadata['reel_number'] ?? null);
    }

    public function test_a_field_in_use_can_be_retired_but_not_deleted(): void
    {
        [, $folder] = $this->fileInFolder();
        $field = $this->field();
        $this->describe($this->operator(), $folder, ['reel_number' => 'R-114'])->assertSuccessful();

        $this->actingAs($this->superAdmin())
            ->deleteJson('/settings/metadata/fields/'.$field->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('metadata_field_definitions', ['id' => $field->id]);
    }

    public function test_an_unused_field_can_be_deleted(): void
    {
        $field = $this->field();

        $this->actingAs($this->superAdmin())
            ->deleteJson('/settings/metadata/fields/'.$field->id)
            ->assertSuccessful();

        $this->assertDatabaseMissing('metadata_field_definitions', ['id' => $field->id]);
    }

    // --------------------------------------------- the system stays the system

    public function test_a_field_cannot_reach_a_system_column_even_if_one_is_sent(): void
    {
        [, $folder, $file] = $this->fileInFolder();
        $this->field();
        // Read it back first, so both sides of the comparison come from the
        // database rather than from what create() happened to hold.
        $before = $file->fresh()->only(['id', 'created_at', 'checksum_sha256', 'current_version', 'file_path']);

        $this->describe($this->operator(), $folder, [
            'reel_number' => 'R-114',
            // All of these are ignored: they are not defined fields.
            'created_at' => '1999-01-01', 'checksum_sha256' => 'forged',
            'current_version' => 99, 'id' => 4242, 'file_path' => 'somewhere/else.png',
        ])->assertSuccessful();

        $after = $file->fresh();
        foreach ($before as $key => $value) {
            $this->assertEquals($value, $after->$key, "A custom field must not be able to write {$key}.");
        }
        $this->assertSame(['reel_number' => 'R-114'], $after->custom_metadata);
    }

    public function test_the_version_history_is_untouched_by_a_describe(): void
    {
        [, $folder, $file] = $this->fileInFolder();
        $this->field();
        $versionsBefore = $file->versions()->count();

        $this->describe($this->operator(), $folder, ['reel_number' => 'R-114'])->assertSuccessful();

        $this->assertSame($versionsBefore, $file->fresh()->versions()->count());
    }

    // ------------------------------ the other way in: a selection, not a folder

    /**
     * Describe on a selection goes through bulk edit rather than the folder
     * endpoint. Found in the browser: the fields rendered, the value was
     * typed, and nothing was written, because only the folder path knew about
     * them.
     */
    public function test_a_selection_writes_custom_fields_through_bulk_edit(): void
    {
        [, , $file] = $this->fileInFolder();
        $this->field(['key' => 'broadcast_date', 'label' => 'Broadcast date', 'input_type' => 'date']);

        $this->actingAs($this->operator())->patchJson('/files/bulk-edit', [
            'ids' => [$file->id],
            'custom_metadata' => ['broadcast_date' => '2026-01-09'],
        ])->assertSuccessful();

        $this->assertSame('2026-01-09', $file->fresh()->custom_metadata['broadcast_date']);
    }

    public function test_bulk_edit_honours_the_locked_rule_too(): void
    {
        [, , $file] = $this->fileInFolder();
        $this->field(['key' => 'accession_number', 'label' => 'Accession number', 'is_locked' => true]);
        $operator = $this->operator();

        $this->actingAs($operator)->patchJson('/files/bulk-edit', [
            'ids' => [$file->id], 'custom_metadata' => ['accession_number' => 'A-1'],
        ])->assertSuccessful();

        $this->actingAs($operator)->patchJson('/files/bulk-edit', [
            'ids' => [$file->id], 'custom_metadata' => ['accession_number' => 'A-2'],
        ])->assertStatus(422);

        $this->assertSame('A-1', $file->fresh()->custom_metadata['accession_number']);
    }
}
