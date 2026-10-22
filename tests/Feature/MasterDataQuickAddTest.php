<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MasterDataValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UAT C19: a value missing from a Batch Upload dropdown can be added from the
 * upload form itself. ADM-01 keeps master data admin-controlled, so the
 * endpoint stays limited to the roles that administer controlled lists.
 */
class MasterDataQuickAddTest extends TestCase
{
    use RefreshDatabase;

    private function department(string $name = 'Audio Video'): Department
    {
        return Department::firstOrCreate(['name' => $name], ['is_active' => true, 'is_system' => false]);
    }

    private function departmentAdmin(): User
    {
        return User::factory()->create(['role' => 'department-admin', 'department_id' => $this->department()->id, 'status' => 'active']);
    }

    public function test_department_admin_can_quick_add_a_flat_master_value(): void
    {
        $this->actingAs($this->departmentAdmin())
            ->postJson('/master-data/quick', ['type' => 'event', 'name' => 'Annakut'])
            ->assertCreated()
            ->assertJsonPath('value.name', 'Annakut')
            ->assertJsonPath('value.is_active', true);

        $this->assertDatabaseHas('master_data_values', ['type' => 'event', 'name' => 'Annakut', 'is_active' => true]);
    }

    public function test_quick_add_returns_the_existing_value_instead_of_failing(): void
    {
        $existing = MasterDataValue::create(['type' => 'event', 'name' => 'Annakut', 'is_active' => true]);

        $this->actingAs($this->departmentAdmin())
            ->postJson('/master-data/quick', ['type' => 'event', 'name' => 'annakut'])
            ->assertOk()
            ->assertJsonPath('value.id', $existing->id);

        $this->assertSame(1, MasterDataValue::where('type', 'event')->count());
    }

    public function test_quick_add_reactivates_a_deactivated_value(): void
    {
        // 'Gujarati' ships as seeded master data, so deactivate that row rather than adding one.
        $existing = MasterDataValue::where('type', 'language')->where('name', 'Gujarati')->firstOrFail();
        $existing->update(['is_active' => false]);

        $this->actingAs($this->departmentAdmin())
            ->postJson('/master-data/quick', ['type' => 'language', 'name' => 'Gujarati'])
            ->assertOk();

        $this->assertTrue($existing->fresh()->is_active);
    }

    public function test_hierarchical_value_requires_a_valid_parent(): void
    {
        $admin = $this->departmentAdmin();
        $country = MasterDataValue::create(['type' => 'country', 'name' => 'India', 'is_active' => true]);

        $this->actingAs($admin)
            ->postJson('/master-data/quick', ['type' => 'state', 'name' => 'Gujarat'])
            ->assertStatus(422);

        // A city cannot hang directly under a country.
        $this->actingAs($admin)
            ->postJson('/master-data/quick', ['type' => 'city', 'name' => 'Vadodara', 'parent_id' => $country->id])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/master-data/quick', ['type' => 'state', 'name' => 'Gujarat', 'parent_id' => $country->id])
            ->assertCreated();

        $this->assertDatabaseHas('master_data_values', ['type' => 'state', 'name' => 'Gujarat', 'parent_id' => $country->id]);
    }

    public function test_flat_types_ignore_a_supplied_parent(): void
    {
        $country = MasterDataValue::create(['type' => 'country', 'name' => 'India', 'is_active' => true]);

        $this->actingAs($this->departmentAdmin())
            ->postJson('/master-data/quick', ['type' => 'event', 'name' => 'Utsav', 'parent_id' => $country->id])
            ->assertCreated();

        $this->assertDatabaseHas('master_data_values', ['type' => 'event', 'name' => 'Utsav', 'parent_id' => null]);
    }

    public function test_operator_and_viewer_cannot_quick_add(): void
    {
        $department = $this->department();
        foreach (['department-operator', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'department_id' => $department->id, 'status' => 'active']);
            $this->actingAs($user)
                ->postJson('/master-data/quick', ['type' => 'event', 'name' => "From {$role}"])
                ->assertForbidden();
        }

        $this->assertSame(0, MasterDataValue::where('name', 'like', 'From %')->count(), 'No value may be created by these roles.');
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->actingAs($this->departmentAdmin())
            ->postJson('/master-data/quick', ['type' => 'department', 'name' => 'Nope'])
            ->assertStatus(422);
    }

    public function test_quick_add_is_audited(): void
    {
        $this->actingAs($this->departmentAdmin())
            ->postJson('/master-data/quick', ['type' => 'person', 'name' => 'Guruji'])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['event' => 'master-data.created']);
    }
}
