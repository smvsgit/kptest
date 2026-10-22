<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionAndDepartmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * VERSIONING.md makes config/version.php the source of truth and the root
     * VERSION file its mirror. Asserting that contract keeps the test correct
     * across releases, instead of pinning a number that goes stale every time.
     */
    public function test_application_version_config_matches_version_file(): void
    {
        $current = config('version.current');
        $previous = config('version.previous');

        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $current);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $previous);

        $this->assertSame(
            trim((string) file_get_contents(base_path('VERSION'))),
            (string) $current,
            'Root VERSION must mirror config/version.php.',
        );

        $this->assertGreaterThan(
            (float) $previous,
            (float) $current,
            'Current version must be newer than the previous version.',
        );
    }

    public function test_super_admin_can_create_department(): void
    {
        $user = User::factory()->create(['role' => 'super-admin']);

        $this->actingAs($user)
            ->post('/departments', ['name' => 'Audio Video'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('departments', ['name' => 'Audio Video']);
    }

    public function test_department_admin_cannot_create_department(): void
    {
        $department = Department::query()->firstOrCreate(
            ['name' => 'Test Department'],
            ['is_active' => true, 'is_system' => false],
        );
        $user = User::factory()->create([
            'role' => 'department-admin',
            'department_id' => $department->id,
        ]);

        $this->actingAs($user)
            ->post('/departments', ['name' => 'Forbidden Department'])
            ->assertForbidden();
    }
}
