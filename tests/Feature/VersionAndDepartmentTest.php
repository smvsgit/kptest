<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionAndDepartmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_version_is_14_00(): void
    {
        $this->assertSame('14.00', config('version.current'));
        $this->assertSame('13.06', config('version.previous'));
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
