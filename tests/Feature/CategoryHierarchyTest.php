<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category / Sub-category management.
 *
 * Covers UAT findings C03 (sub-category creation must be reachable from the drawer,
 * with the parent main category chosen in the form), C04 (edit support for both
 * levels) and C05 (duplicate-safe naming), plus the role boundaries around them.
 */
class CategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super-admin']);
    }

    private function departmentAdmin(): User
    {
        $department = Department::query()->firstOrCreate(
            ['name' => 'Category Test Department'],
            ['is_active' => true, 'is_system' => false],
        );

        return User::factory()->create(['role' => 'department-admin', 'department_id' => $department->id]);
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => 'viewer']);
    }

    // ---------------------------------------------------------------- create

    public function test_super_admin_can_create_main_category(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/categories', ['name' => 'Satsang'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['name' => 'Satsang']);
    }

    public function test_main_category_name_must_be_unique(): void
    {
        Category::create(['name' => 'Satsang']);

        $this->actingAs($this->superAdmin())
            ->post('/categories', ['name' => 'Satsang'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Category::query()->where('name', 'Satsang')->count());
    }

    public function test_main_category_name_is_required(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/categories', ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    /** C03 — the drawer flow posts the chosen parent together with the new name. */
    public function test_subcategory_is_created_for_the_selected_main_category(): void
    {
        $category = Category::create(['name' => 'Satsang']);

        $this->actingAs($this->superAdmin())
            ->post('/subcategories', ['category_id' => $category->id, 'name' => 'Satsang Sabha'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subcategories', [
            'category_id' => $category->id,
            'name' => 'Satsang Sabha',
        ]);
    }

    public function test_subcategory_requires_an_existing_main_category(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/subcategories', ['category_id' => 9999, 'name' => 'Orphan'])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('subcategories', 0);
    }

    public function test_subcategory_requires_a_main_category_to_be_selected(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/subcategories', ['name' => 'No Parent'])
            ->assertSessionHasErrors('category_id');
    }

    public function test_nested_subcategory_route_still_works(): void
    {
        $category = Category::create(['name' => 'Utsav']);

        $this->actingAs($this->superAdmin())
            ->post("/categories/{$category->id}/subcategories", ['name' => 'Annakut'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subcategories', ['category_id' => $category->id, 'name' => 'Annakut']);
    }

    /** C05 — duplicates inside one main category are rejected, but the same name may repeat under a different parent. */
    public function test_subcategory_name_is_unique_within_its_main_category(): void
    {
        $category = Category::create(['name' => 'Satsang']);
        Subcategory::create(['category_id' => $category->id, 'name' => 'Sabha']);

        $this->actingAs($this->superAdmin())
            ->post('/subcategories', ['category_id' => $category->id, 'name' => 'Sabha'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Subcategory::query()->where('category_id', $category->id)->count());
    }

    public function test_same_subcategory_name_is_allowed_under_a_different_main_category(): void
    {
        $first = Category::create(['name' => 'Satsang']);
        $second = Category::create(['name' => 'Utsav']);
        Subcategory::create(['category_id' => $first->id, 'name' => 'Photos']);

        $this->actingAs($this->superAdmin())
            ->post('/subcategories', ['category_id' => $second->id, 'name' => 'Photos'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Subcategory::query()->where('name', 'Photos')->count());
    }

    // ------------------------------------------------------------------ edit

    /** C04 — a main category can be renamed. */
    public function test_super_admin_can_rename_main_category(): void
    {
        $category = Category::create(['name' => 'Satsang']);

        $this->actingAs($this->superAdmin())
            ->put("/categories/{$category->id}", ['name' => 'Satsang Programs'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Satsang Programs']);
    }

    public function test_renaming_main_category_to_an_existing_name_is_rejected(): void
    {
        Category::create(['name' => 'Satsang']);
        $other = Category::create(['name' => 'Utsav']);

        $this->actingAs($this->superAdmin())
            ->put("/categories/{$other->id}", ['name' => 'Satsang'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('categories', ['id' => $other->id, 'name' => 'Utsav']);
    }

    public function test_renaming_main_category_to_its_own_name_is_allowed(): void
    {
        $category = Category::create(['name' => 'Satsang']);

        $this->actingAs($this->superAdmin())
            ->put("/categories/{$category->id}", ['name' => 'Satsang'])
            ->assertSessionHasNoErrors();
    }

    /** C04 — a sub-category can be renamed. */
    public function test_super_admin_can_rename_subcategory(): void
    {
        $category = Category::create(['name' => 'Satsang']);
        $sub = Subcategory::create(['category_id' => $category->id, 'name' => 'Sabha']);

        $this->actingAs($this->superAdmin())
            ->put("/subcategories/{$sub->id}", ['category_id' => $category->id, 'name' => 'Satsang Sabha'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subcategories', ['id' => $sub->id, 'name' => 'Satsang Sabha']);
    }

    public function test_subcategory_can_be_moved_to_another_main_category(): void
    {
        $from = Category::create(['name' => 'Satsang']);
        $to = Category::create(['name' => 'Utsav']);
        $sub = Subcategory::create(['category_id' => $from->id, 'name' => 'Photos']);

        $this->actingAs($this->superAdmin())
            ->put("/subcategories/{$sub->id}", ['category_id' => $to->id, 'name' => 'Photos'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subcategories', ['id' => $sub->id, 'category_id' => $to->id]);
    }

    public function test_renaming_subcategory_into_a_duplicate_is_rejected(): void
    {
        $category = Category::create(['name' => 'Satsang']);
        Subcategory::create(['category_id' => $category->id, 'name' => 'Sabha']);
        $other = Subcategory::create(['category_id' => $category->id, 'name' => 'Photos']);

        $this->actingAs($this->superAdmin())
            ->put("/subcategories/{$other->id}", ['category_id' => $category->id, 'name' => 'Sabha'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('subcategories', ['id' => $other->id, 'name' => 'Photos']);
    }

    // ---------------------------------------------------------------- delete

    public function test_deleting_main_category_removes_its_subcategories(): void
    {
        $category = Category::create(['name' => 'Satsang']);
        Subcategory::create(['category_id' => $category->id, 'name' => 'Sabha']);

        $this->actingAs($this->superAdmin())
            ->delete("/categories/{$category->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('subcategories', 0);
    }

    public function test_subcategory_can_be_deleted_without_touching_its_parent(): void
    {
        $category = Category::create(['name' => 'Satsang']);
        $sub = Subcategory::create(['category_id' => $category->id, 'name' => 'Sabha']);

        $this->actingAs($this->superAdmin())
            ->delete("/subcategories/{$sub->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subcategories', 0);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    // ------------------------------------------------------- authorization

    public function test_department_admin_can_manage_categories(): void
    {
        $admin = $this->departmentAdmin();

        $this->actingAs($admin)
            ->post('/categories', ['name' => 'Department Category'])
            ->assertSessionHasNoErrors();

        $category = Category::query()->where('name', 'Department Category')->firstOrFail();

        $this->actingAs($admin)
            ->post('/subcategories', ['category_id' => $category->id, 'name' => 'Department Sub'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put("/categories/{$category->id}", ['name' => 'Department Category Renamed'])
            ->assertSessionHasNoErrors();
    }

    public function test_viewer_cannot_create_or_edit_categories(): void
    {
        $category = Category::create(['name' => 'Satsang']);
        $sub = Subcategory::create(['category_id' => $category->id, 'name' => 'Sabha']);
        $viewer = $this->viewer();

        $this->actingAs($viewer)->post('/categories', ['name' => 'Hacked'])->assertForbidden();
        $this->actingAs($viewer)->put("/categories/{$category->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->actingAs($viewer)->post('/subcategories', ['category_id' => $category->id, 'name' => 'Hacked'])->assertForbidden();
        $this->actingAs($viewer)->put("/subcategories/{$sub->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->actingAs($viewer)->delete("/categories/{$category->id}")->assertForbidden();
        $this->actingAs($viewer)->delete("/subcategories/{$sub->id}")->assertForbidden();

        $this->assertDatabaseHas('categories', ['name' => 'Satsang']);
        $this->assertDatabaseHas('subcategories', ['name' => 'Sabha']);
    }

    public function test_guest_cannot_manage_categories(): void
    {
        $this->post('/categories', ['name' => 'Guest'])->assertRedirect('/login');
        $this->post('/subcategories', ['category_id' => 1, 'name' => 'Guest'])->assertRedirect('/login');
    }

    // ------------------------------------------------------------- auditing

    public function test_category_and_subcategory_changes_are_audited(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post('/categories', ['name' => 'Audited']);
        $category = Category::query()->where('name', 'Audited')->firstOrFail();

        $this->actingAs($admin)->put("/categories/{$category->id}", ['name' => 'Audited Renamed']);
        $this->actingAs($admin)->post('/subcategories', ['category_id' => $category->id, 'name' => 'Audited Sub']);
        $sub = Subcategory::query()->where('name', 'Audited Sub')->firstOrFail();
        $this->actingAs($admin)->put("/subcategories/{$sub->id}", ['category_id' => $category->id, 'name' => 'Audited Sub Renamed']);

        foreach (['category.created', 'category.updated', 'subcategory.created', 'subcategory.updated'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event]);
        }
    }

    // ---------------------------------------------- dashboard tree payload

    public function test_dashboard_exposes_categories_with_their_subcategories(): void
    {
        $category = Category::create(['name' => 'Satsang']);
        Subcategory::create(['category_id' => $category->id, 'name' => 'Sabha']);

        $this->actingAs($this->superAdmin())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('categories', 1)
                ->where('categories.0.name', 'Satsang')
                ->has('categories.0.subcategories', 1)
                ->where('categories.0.subcategories.0.name', 'Sabha'));
    }
}
