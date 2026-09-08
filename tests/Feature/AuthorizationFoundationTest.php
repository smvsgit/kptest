<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_access_upload_endpoint(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($user)
            ->post('/files', [])
            ->assertForbidden();
    }

    public function test_department_operator_reaches_upload_validation_instead_of_being_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'department-operator']);

        $this->actingAs($user)
            ->post('/files', [])
            ->assertSessionHasErrors('file');
    }

    public function test_department_operator_cannot_bulk_delete(): void
    {
        $user = User::factory()->create(['role' => 'department-operator']);

        $this->actingAs($user)
            ->delete('/files/bulk-delete', ['ids' => [1]])
            ->assertForbidden();
    }

    public function test_non_super_admin_cannot_simulate_roles(): void
    {
        $user = User::factory()->create(['role' => 'department-admin']);

        $this->actingAs($user)
            ->post('/simulate-role', ['role' => 'super-admin'])
            ->assertForbidden();
    }
}
