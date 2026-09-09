<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\UserGuideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserGuideAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_source_contains_exactly_eighteen_pages(): void
    {
        $pages = app(UserGuideService::class)->allPages();
        $this->assertCount(UserGuideService::TOTAL_PAGES, $pages);
        $this->assertSame(range(1, UserGuideService::TOTAL_PAGES), array_column($pages, 'number'));
    }

    public function test_role_default_limits_portal_and_html_manual(): void
    {
        SystemSetting::put('user_guide.access', [
            'enabled' => true,
            'role_pages' => ['department-admin' => 14, 'department-operator' => 10, 'viewer' => 5],
            'department_pages' => [],
            'user_pages' => [],
        ]);
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->getJson('/user-guide')
            ->assertOk()
            ->assertJsonPath('allowed_pages', 5)
            ->assertJsonCount(5, 'pages')
            ->assertJsonPath('document_url', null);

        $this->actingAs($viewer)->get('/user-guide/html')
            ->assertOk()
            ->assertSee('પાનું 5', false)
            ->assertDontSee('પાનું 6', false);
    }

    public function test_user_override_wins_over_department_and_role_defaults(): void
    {
        $department = Department::create(['name' => 'Media', 'is_active' => true, 'is_system' => false]);
        $user = User::factory()->create(['role' => 'viewer', 'department_id' => $department->id]);
        SystemSetting::put('user_guide.access', [
            'enabled' => true,
            'role_pages' => ['department-admin' => 14, 'department-operator' => 10, 'viewer' => 5],
            'department_pages' => [(string) $department->id => 8],
            'user_pages' => [(string) $user->id => 3],
        ]);

        $this->assertSame(3, app(UserGuideService::class)->allowedPageCount($user));
        $this->actingAs($user)->getJson('/user-guide')->assertJsonPath('allowed_pages', 3)->assertJsonCount(3, 'pages');
    }

    public function test_zero_pages_hides_manual_and_direct_endpoint_is_forbidden(): void
    {
        SystemSetting::put('user_guide.access', [
            'enabled' => true,
            'role_pages' => ['department-admin' => 14, 'department-operator' => 10, 'viewer' => 0],
            'department_pages' => [],
            'user_pages' => [],
        ]);
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->getJson('/user-guide')->assertForbidden();
        $this->actingAs($viewer)->get('/user-guide/html')->assertForbidden();
        $this->actingAs($viewer)->get('/user-guide/document')->assertForbidden();
    }

    public function test_super_admin_always_has_full_manual_and_word_download_route(): void
    {
        SystemSetting::put('user_guide.access', [
            'enabled' => false,
            'role_pages' => ['department-admin' => 0, 'department-operator' => 0, 'viewer' => 0],
            'department_pages' => [],
            'user_pages' => [],
        ]);
        $admin = User::factory()->create(['role' => 'super-admin']);

        $this->actingAs($admin)->getJson('/user-guide')
            ->assertOk()
            ->assertJsonPath('allowed_pages', UserGuideService::TOTAL_PAGES)
            ->assertJsonCount(UserGuideService::TOTAL_PAGES, 'pages');
    }
}
