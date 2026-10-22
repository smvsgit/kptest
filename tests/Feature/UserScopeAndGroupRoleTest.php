<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\PortalRole;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UAT-014, UAT-018 and UAT-019: Department Admin scoping, groupwise role
 * assignment, and privilege-escalation protection. These rows had never been
 * executed, so each test is named after the row it provides evidence for.
 */
class UserScopeAndGroupRoleTest extends TestCase
{
    use RefreshDatabase;

    private function departments(): array
    {
        return [
            Department::create(['name' => 'Audio Video', 'is_active' => true, 'is_system' => false]),
            Department::create(['name' => 'Website', 'is_active' => true, 'is_system' => false]),
        ];
    }

    private function roleFor(string $baseRole): PortalRole
    {
        return PortalRole::where('slug', $baseRole)->where('is_builtin', true)->firstOrFail();
    }

    // ------------------------------------- UAT-014 Department Admin scoping

    public function test_uat_014_department_admin_creates_users_only_inside_their_own_department(): void
    {
        [$own, $other] = $this->departments();
        $admin = User::factory()->create(['role' => 'department-admin', 'department_id' => $own->id, 'status' => 'active']);

        // Even when another department is requested, the actor's own department wins.
        $this->actingAs($admin)->postJson('/users', [
            'name' => 'AV Operator', 'email' => 'avop@example.org',
            'department_id' => $other->id,
            'portal_role_id' => $this->roleFor('department-operator')->id,
        ])->assertOk();

        $created = User::where('email', 'avop@example.org')->firstOrFail();
        $this->assertSame($own->id, $created->department_id, 'The new user must land in the admin\'s own department.');
    }

    public function test_uat_014_department_admin_only_sees_users_from_their_department(): void
    {
        [$own, $other] = $this->departments();
        $admin = User::factory()->create(['role' => 'department-admin', 'department_id' => $own->id, 'status' => 'active']);
        $mine = User::factory()->create(['role' => 'viewer', 'department_id' => $own->id, 'status' => 'active']);
        User::factory()->create(['role' => 'viewer', 'department_id' => $other->id, 'status' => 'active']);

        $this->actingAs($admin)->get('/?panel=settings')->assertOk()
            ->assertInertia(fn ($page) => $page->where('users', fn ($users) => collect($users)
                ->every(fn ($u) => (int) $u['department_id'] === $own->id)));

        $this->actingAs($admin)->get('/?panel=settings')->assertOk()
            ->assertInertia(fn ($page) => $page->where('users', fn ($users) => collect($users)->contains('id', $mine->id)));
    }

    // ------------------------------------------- UAT-019 Privilege escalation

    public function test_uat_019_department_admin_cannot_create_an_administrator(): void
    {
        [$own] = $this->departments();
        $admin = User::factory()->create(['role' => 'department-admin', 'department_id' => $own->id, 'status' => 'active']);

        foreach (['super-admin', 'department-admin'] as $baseRole) {
            $this->actingAs($admin)->postJson('/users', [
                'name' => "Escalated {$baseRole}", 'email' => "{$baseRole}@example.org",
                'department_id' => $own->id,
                'portal_role_id' => $this->roleFor($baseRole)->id,
            ])->assertStatus(422)->assertJsonValidationErrors('portal_role_id');
        }

        $this->assertSame(0, User::whereIn('role', ['super-admin', 'department-admin'])->where('id', '!=', $admin->id)->count());
    }

    public function test_uat_019_department_admin_cannot_promote_a_user_to_administrator(): void
    {
        [$own] = $this->departments();
        $admin = User::factory()->create(['role' => 'department-admin', 'department_id' => $own->id, 'status' => 'active']);
        $target = User::factory()->create(['role' => 'viewer', 'department_id' => $own->id, 'status' => 'active']);

        // The route itself is Super Admin only, so escalation is refused outright.
        $this->actingAs($admin)->patchJson("/users/{$target->id}/role", ['role' => 'super-admin'])->assertForbidden();
        $this->assertSame('viewer', $target->fresh()->role);
    }

    public function test_uat_019_department_admin_cannot_touch_users_in_another_department(): void
    {
        [$own, $other] = $this->departments();
        $admin = User::factory()->create(['role' => 'department-admin', 'department_id' => $own->id, 'status' => 'active']);
        $outsider = User::factory()->create(['role' => 'viewer', 'department_id' => $other->id, 'status' => 'active']);

        $this->actingAs($admin)->patchJson("/users/{$outsider->id}/status", ['status' => 'disabled', 'reason' => 'Nope'])
            ->assertForbidden();
        $this->actingAs($admin)->patchJson("/users/{$outsider->id}/department", ['department_id' => $own->id])
            ->assertForbidden();

        $this->assertSame('active', $outsider->fresh()->status);
        $this->assertSame($other->id, $outsider->fresh()->department_id);
    }

    public function test_uat_019_department_admin_cannot_create_a_role_at_all(): void
    {
        [$own] = $this->departments();
        $admin = User::factory()->create(['role' => 'department-admin', 'department_id' => $own->id, 'status' => 'active']);

        $this->actingAs($admin)->postJson('/portal-roles', [
            'name' => 'Escalation Role', 'base_role' => 'super-admin',
            'page_access' => ['browse' => true, 'settings' => true], 'permissions' => [],
        ])->assertForbidden();
    }

    // --------------------------------------------- UAT-018 Group role assignment

    public function test_uat_018_adding_a_member_applies_the_configured_group_role(): void
    {
        [$own] = $this->departments();
        $super = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);
        $member = User::factory()->create(['role' => 'viewer', 'department_id' => $own->id, 'status' => 'active']);
        $operatorRole = $this->roleFor('department-operator');

        $group = UserGroup::create([
            'name' => 'AV Editors', 'department_id' => $own->id,
            'portal_role_id' => $operatorRole->id, 'is_active' => true,
        ]);

        $this->actingAs($super)->postJson("/user-groups/{$group->id}/members", [
            'user_id' => $member->id, 'apply_group_role' => true,
        ])->assertOk();

        $fresh = $member->fresh();
        $this->assertSame($operatorRole->id, $fresh->portal_role_id, 'The group role must be applied to the member.');
        $this->assertSame('department-operator', $fresh->role);
        $this->assertTrue($group->members()->whereKey($member->id)->exists());
    }

    public function test_uat_018_group_membership_is_restricted_to_the_group_department(): void
    {
        [$own, $other] = $this->departments();
        $super = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);
        $outsider = User::factory()->create(['role' => 'viewer', 'department_id' => $other->id, 'status' => 'active']);

        $group = UserGroup::create(['name' => 'AV Editors', 'department_id' => $own->id, 'is_active' => true]);

        $this->actingAs($super)->postJson("/user-groups/{$group->id}/members", ['user_id' => $outsider->id])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_uat_018_apply_group_role_updates_every_member(): void
    {
        [$own] = $this->departments();
        $super = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);
        $a = User::factory()->create(['role' => 'viewer', 'department_id' => $own->id, 'status' => 'active']);
        $b = User::factory()->create(['role' => 'viewer', 'department_id' => $own->id, 'status' => 'active']);
        $operatorRole = $this->roleFor('department-operator');

        $group = UserGroup::create(['name' => 'AV Editors', 'department_id' => $own->id, 'is_active' => true]);
        foreach ([$a, $b] as $member) {
            $this->actingAs($super)->postJson("/user-groups/{$group->id}/members", ['user_id' => $member->id, 'apply_group_role' => false])->assertOk();
        }
        $this->assertSame('viewer', $a->fresh()->role, 'Nothing applied while the group had no role.');

        $this->actingAs($super)->patchJson("/user-groups/{$group->id}", [
            'name' => 'AV Editors', 'description' => null,
            'portal_role_id' => $operatorRole->id, 'is_active' => true,
        ])->assertOk();

        $this->actingAs($super)->postJson("/user-groups/{$group->id}/apply-role")->assertOk();

        $this->assertSame('department-operator', $a->fresh()->role);
        $this->assertSame('department-operator', $b->fresh()->role);
    }

    public function test_uat_018_group_role_application_is_audited(): void
    {
        [$own] = $this->departments();
        $super = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);
        $member = User::factory()->create(['role' => 'viewer', 'department_id' => $own->id, 'status' => 'active']);

        $group = UserGroup::create([
            'name' => 'AV Editors', 'department_id' => $own->id,
            'portal_role_id' => $this->roleFor('department-operator')->id, 'is_active' => true,
        ]);
        $this->actingAs($super)->postJson("/user-groups/{$group->id}/members", ['user_id' => $member->id, 'apply_group_role' => true])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event' => 'user-group.member-added']);
    }

    public function test_uat_018_department_admin_cannot_give_a_group_an_administrator_role(): void
    {
        [$own] = $this->departments();
        $admin = User::factory()->create(['role' => 'department-admin', 'department_id' => $own->id, 'status' => 'active']);

        $this->actingAs($admin)->postJson('/user-groups', [
            'name' => 'Escalating Group', 'department_id' => $own->id,
            'portal_role_id' => $this->roleFor('department-admin')->id,
        ])->assertStatus(422);
    }
}
