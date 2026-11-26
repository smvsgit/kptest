<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\PortalRole;
use App\Models\User;
use App\Support\SettingsSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F05: the Settings console is divided into sections a Super Admin grants one
 * by one, and the rule holds on the server, not only in the screen.
 *
 * The review's acceptance asks for real accounts and direct requests, so every
 * test here signs a user in and calls the endpoint itself rather than checking
 * a flag.
 */
class SettingsSectionAccessTest extends TestCase
{
    use RefreshDatabase;

    private function department(string $name = 'Multimedia'): Department
    {
        return Department::firstOrCreate(['name' => $name], ['is_active' => true, 'is_system' => false]);
    }

    /** A custom role with exactly the sections named, at the level given. */
    private function roleWith(array $sections, string $base = 'department-operator', array $pages = []): PortalRole
    {
        return PortalRole::create([
            'name' => 'Asset Manager '.uniqid(),
            'slug' => 'asset-manager-'.uniqid(),
            'base_role' => $base,
            'is_builtin' => false,
            'is_active' => true,
            'permissions' => [],
            'page_access' => array_replace(['browse' => true, 'settings' => true], $pages),
            'settings_access' => SettingsSections::normaliseMap($sections, $base),
        ]);
    }

    private function userFor(PortalRole $role): User
    {
        return User::factory()->create([
            'role' => $role->base_role,
            'portal_role_id' => $role->id,
            'department_id' => $this->department()->id,
            'status' => 'active',
        ]);
    }


    /** The metadata endpoint validates its whole form, so send one. */
    private function metadataPayload(): array
    {
        return [
            // `required|array` treats an empty array as absent, so name one field.
            'required_fields' => ['year'],
            'person_required' => false,
            'allow_free_tags' => true,
            'years_min' => 1950,
            'years_max' => 2030,
        ];
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super-admin', 'department_id' => null, 'status' => 'active']);
    }

    // --------------------------------------------------------- the defaults

    public function test_nothing_changes_for_a_role_that_was_never_granted_a_section(): void
    {
        $role = $this->roleWith([]);

        $this->actingAs($this->userFor($role))
            ->patchJson('/settings/metadata', $this->metadataPayload())
            ->assertStatus(403);
    }

    public function test_a_super_admin_still_reaches_every_section(): void
    {
        $admin = $this->superAdmin();
        foreach (SettingsSections::keys() as $section) {
            $this->assertSame(SettingsSections::EDIT, $admin->settingsAccess($section), $section);
        }
    }

    public function test_a_department_admin_keeps_exactly_what_it_had_before_sections_existed(): void
    {
        $user = User::factory()->create([
            'role' => 'department-admin',
            'department_id' => $this->department()->id,
            'status' => 'active',
        ]);

        $this->assertSame(SettingsSections::EDIT, $user->settingsAccess('users'));
        $this->assertSame(SettingsSections::EDIT, $user->settingsAccess('lifecycle'));
        $this->assertSame(SettingsSections::EDIT, $user->settingsAccess('system'));
        $this->assertSame(SettingsSections::NONE, $user->settingsAccess('security'));
        $this->assertSame(SettingsSections::NONE, $user->settingsAccess('backup'));
        $this->assertSame(SettingsSections::NONE, $user->settingsAccess('metadata'));
    }

    // ---------------------------------------------------- a granted section

    public function test_a_granted_section_opens_that_endpoint_and_no_other(): void
    {
        $role = $this->roleWith(['metadata' => SettingsSections::EDIT]);
        $user = $this->userFor($role);

        $this->actingAs($user)
            ->patch('/settings/metadata', $this->metadataPayload())
            ->assertRedirect();

        // Security and backups were not granted, and are not implied.
        $this->actingAs($user)->patchJson('/settings/security-auth', [])->assertStatus(403);
        $this->actingAs($user)->patchJson('/settings/backups', [])->assertStatus(403);
    }

    public function test_master_data_rides_with_metadata(): void
    {
        $user = $this->userFor($this->roleWith(['metadata' => SettingsSections::EDIT]));

        $this->actingAs($user)
            ->post('/settings/master-data', ['type' => 'event', 'name' => 'Annakut 2026'])
            ->assertRedirect();
        $this->assertDatabaseHas('master_data_values', ['name' => 'Annakut 2026']);
    }

    public function test_view_lets_a_role_read_a_section_but_not_write_it(): void
    {
        $user = $this->userFor($this->roleWith(['users' => SettingsSections::VIEW]));

        $this->assertTrue($user->canViewSettingsSection('users'));
        $this->assertFalse($user->canEditSettingsSection('users'));

        // The export is a read; the import is a write.
        $this->actingAs($user)->get('/users/export.xlsx')->assertSuccessful();
        $this->actingAs($user)->post('/users/import.xlsx', [])->assertStatus(403);
    }

    // ------------------------------------------- administration stays apart

    public function test_a_granted_role_still_cannot_administer_roles_or_departments(): void
    {
        $user = $this->userFor($this->roleWith([
            'metadata' => SettingsSections::EDIT,
            'users' => SettingsSections::EDIT,
            'security' => SettingsSections::EDIT,
        ]));

        $this->actingAs($user)->postJson('/portal-roles', [
            'name' => 'Escalation', 'base_role' => 'super-admin', 'page_access' => ['settings' => true],
        ])->assertStatus(403);

        $this->actingAs($user)->postJson('/departments', ['name' => 'New'])->assertStatus(403);
    }

    public function test_a_disabled_role_grants_nothing(): void
    {
        $role = $this->roleWith(['metadata' => SettingsSections::EDIT]);
        $user = $this->userFor($role);
        $role->update(['is_active' => false]);

        $this->actingAs($user->fresh())
            ->patchJson('/settings/metadata', $this->metadataPayload())
            ->assertStatus(403);
    }

    public function test_a_role_without_the_settings_page_reaches_no_section(): void
    {
        $role = $this->roleWith(['metadata' => SettingsSections::EDIT], 'department-operator', ['settings' => false]);

        $this->assertSame(SettingsSections::NONE, $this->userFor($role)->settingsAccess('metadata'));
    }

    // ------------------------------------------------- the Super Admin's own control

    public function test_a_super_admin_can_grant_a_section_to_a_role(): void
    {
        $role = $this->roleWith([]);

        $this->actingAs($this->superAdmin())->patchJson('/portal-roles/'.$role->id, [
            'name' => $role->name,
            'base_role' => $role->base_role,
            'is_active' => true,
            'page_access' => $role->page_access,
            'permissions' => $role->permissions,
            'settings_access' => ['metadata' => 'edit', 'security' => 'edit'],
        ])->assertSuccessful();

        $fresh = $role->fresh();
        $this->assertSame('edit', $fresh->settings_access['metadata']);
        $this->assertSame('edit', $fresh->settings_access['security']);
        $this->assertSame('none', $fresh->settings_access['backup'], 'An ungranted section stays none.');
    }

    public function test_an_unknown_section_or_level_is_discarded(): void
    {
        $role = $this->roleWith([]);

        $this->actingAs($this->superAdmin())->patchJson('/portal-roles/'.$role->id, [
            'name' => $role->name,
            'base_role' => $role->base_role,
            'is_active' => true,
            'page_access' => $role->page_access,
            'permissions' => $role->permissions,
            'settings_access' => ['metadata' => 'ADMIN', 'not_a_section' => 'edit'],
        ])->assertSuccessful();

        $map = $role->fresh()->settings_access;
        $this->assertSame('none', $map['metadata']);
        $this->assertArrayNotHasKey('not_a_section', $map);
    }

    public function test_the_screen_is_told_what_the_signed_in_user_may_reach(): void
    {
        $user = $this->userFor($this->roleWith(['metadata' => SettingsSections::EDIT]));

        $map = $user->settingsAccessMap();
        $this->assertSame('edit', $map['metadata']);
        $this->assertSame('none', $map['backup']);
        $this->assertCount(count(SettingsSections::keys()), $map, 'Every section is reported, granted or not.');
    }
}
