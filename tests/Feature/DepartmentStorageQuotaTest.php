<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MediaFile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UAT C18: storage quotas have to be manageable per department, and the figures
 * an administrator sees must be the same ones upload enforcement applies.
 */
class DepartmentStorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private function department(string $name): Department
    {
        return Department::create(['name' => $name, 'is_active' => true, 'is_system' => false]);
    }

    private function fileFor(Department $department, int $size): MediaFile
    {
        return MediaFile::create([
            'name' => "{$department->name}-{$size}.bin", 'type' => 'document', 'size' => $size,
            'file_path' => "uat/{$department->id}-{$size}.bin", 'department_id' => $department->id,
            'access_policy' => 'public', 'download_allowed' => true, 'tags' => [],
        ]);
    }

    public function test_department_override_takes_precedence_over_the_default(): void
    {
        $a = $this->department('Audio Video');
        $b = $this->department('Website');
        SystemSetting::put('storage.quotas', [
            'default_quota_gb' => 5,
            'departments' => [(string) $a->id => 20],
        ]);

        $quota = app(StorageQuotaService::class);
        $this->assertSame(20.0, $quota->quotaGb($a->id), 'Explicit override wins.');
        $this->assertSame(5.0, $quota->quotaGb($b->id), 'Others fall back to the default.');
    }

    public function test_quota_zero_means_unlimited(): void
    {
        $department = $this->department('Audio Video');
        SystemSetting::put('storage.quotas', ['default_quota_gb' => 0, 'departments' => []]);

        $this->fileFor($department, 2 * self::GB);
        app(StorageQuotaService::class)->assertCanStore($department->id, 5 * self::GB);

        $this->assertTrue(true, 'No quota is enforced when the limit is zero.');
    }

    public function test_upload_beyond_the_department_quota_is_rejected(): void
    {
        $department = $this->department('Audio Video');
        SystemSetting::put('storage.quotas', [
            'default_quota_gb' => 100,
            'departments' => [(string) $department->id => 1],
        ]);
        $this->fileFor($department, (int) (0.9 * self::GB));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(StorageQuotaService::class)->assertCanStore($department->id, (int) (0.5 * self::GB));
    }

    public function test_one_department_quota_does_not_constrain_another(): void
    {
        $a = $this->department('Audio Video');
        $b = $this->department('Website');
        SystemSetting::put('storage.quotas', [
            'default_quota_gb' => 0,
            'departments' => [(string) $a->id => 1],
        ]);
        $this->fileFor($a, (int) (0.95 * self::GB));

        // B has no limit of its own and the default is unlimited.
        app(StorageQuotaService::class)->assertCanStore($b->id, 10 * self::GB);
        $this->assertTrue(true);
    }

    public function test_overview_reports_usage_state_per_department(): void
    {
        $full = $this->department('Full');
        $warn = $this->department('Warning');
        $free = $this->department('Unlimited');
        SystemSetting::put('storage.quotas', [
            'default_quota_gb' => 0,
            'warning_percent' => 80,
            'departments' => [(string) $full->id => 1, (string) $warn->id => 10],
        ]);
        $this->fileFor($full, self::GB);
        $this->fileFor($warn, (int) (8.5 * self::GB));

        $rows = collect(app(StorageQuotaService::class)->overview())->keyBy('name');

        $this->assertSame('full', $rows['Full']['state']);
        $this->assertSame('warning', $rows['Warning']['state']);
        $this->assertSame('unlimited', $rows['Unlimited']['state']);
        $this->assertFalse($rows['Full']['uses_default']);
        $this->assertTrue($rows['Unlimited']['uses_default']);
        $this->assertSame(85.0, $rows['Warning']['percent_used']);
    }

    public function test_overview_is_exposed_to_super_admin_only(): void
    {
        $department = $this->department('Audio Video');
        $admin = User::factory()->create(['role' => 'department-admin', 'department_id' => $department->id, 'status' => 'active']);
        $super = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);

        // The system "Unassigned" department ships with the schema, so the
        // overview covers it plus the one created here.
        $this->actingAs($super)->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('departmentStorage', Department::count())
                ->where('departmentStorage', fn ($rows) => collect($rows)->contains('name', $department->name)));

        $this->actingAs($admin)->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('departmentStorage', []));
    }

    public function test_super_admin_can_save_per_department_quotas(): void
    {
        $department = $this->department('Audio Video');
        $super = User::factory()->create(['role' => 'super-admin', 'status' => 'active']);

        $this->actingAs($super)->patch('/settings/feature-completion', [
            'section' => 'storage_quotas',
            'settings' => ['default_quota_gb' => 2, 'warning_percent' => 75, 'departments' => [(string) $department->id => 50]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(50.0, app(StorageQuotaService::class)->quotaGb($department->id));
    }
}
