<?php
namespace App\Services;

use App\Models\Department;
use App\Models\MediaFile;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;

class StorageQuotaService
{
    private const BYTES_PER_GB = 1073741824;

    public function usageBytes(int $departmentId): int
    {
        $active = (int) MediaFile::withTrashed()->where('department_id', $departmentId)->sum('size');
        $versions = (int) DB::table('media_file_versions')
            ->join('media_files', 'media_files.id', '=', 'media_file_versions.media_file_id')
            ->where('media_files.department_id', $departmentId)
            ->sum('media_file_versions.size');

        return max($active, $versions);
    }

    /** Effective quota in GB for a department; 0 means unlimited. */
    public function quotaGb(int $departmentId, ?array $settings = null): float
    {
        $settings ??= SystemSetting::valueFor('storage.quotas', []);

        return (float) ($settings['departments'][(string) $departmentId] ?? $settings['default_quota_gb'] ?? 0);
    }

    public function assertCanStore(int $departmentId, int $incomingBytes): void
    {
        $gb = $this->quotaGb($departmentId);
        if ($gb <= 0) return;

        abort_if(
            $this->usageBytes($departmentId) + $incomingBytes > (int) round($gb * self::BYTES_PER_GB),
            422,
            'Department storage quota would be exceeded.',
        );
    }

    /**
     * Per-department usage for the Storage Quotas admin screen.
     *
     * Deliberately reuses usageBytes()/quotaGb() so what an administrator is
     * shown is exactly what upload enforcement applies.
     */
    public function overview(): array
    {
        $settings = SystemSetting::valueFor('storage.quotas', []);
        $warningPercent = (float) ($settings['warning_percent'] ?? 80);

        return Department::query()->orderBy('is_system')->orderBy('name')->get()
            ->map(function (Department $department) use ($settings, $warningPercent) {
                $used = $this->usageBytes($department->id);
                $gb = $this->quotaGb($department->id, $settings);
                $limit = (int) round($gb * self::BYTES_PER_GB);
                $percent = $limit > 0 ? round($used / $limit * 100, 1) : null;
                $explicit = array_key_exists((string) $department->id, $settings['departments'] ?? []);

                return [
                    'id' => $department->id,
                    'name' => $department->name,
                    'is_system' => (bool) $department->is_system,
                    'is_active' => (bool) $department->is_active,
                    'used_bytes' => $used,
                    'quota_gb' => $gb,
                    'quota_bytes' => $limit,
                    'uses_default' => !$explicit,
                    'percent_used' => $percent,
                    'state' => $limit <= 0 ? 'unlimited'
                        : ($used >= $limit ? 'full' : ($percent >= $warningPercent ? 'warning' : 'ok')),
                ];
            })->values()->all();
    }
}
