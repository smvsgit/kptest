<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $this->mergeSetting('branding.settings', [
            'portal_name' => 'Karyalay Portal',
            'login_text' => 'Secure office media and document portal',
            'default_language' => 'en',
            'logo_path' => null,
        ]);

        $this->mergeSetting('security.two_factor', [
            'enabled' => true,
            'required_super_admin' => false,
            'issuer' => 'Karyalay Portal',
        ]);

        $lifecycle = $this->readSetting('lifecycle.settings');
        $transitions = is_array($lifecycle['transitions'] ?? null) ? $lifecycle['transitions'] : [];
        $bridges = [
            'active' => ['draft', 'review', 'archived'],
            'inactive' => ['draft'],
            'broken' => ['draft'],
        ];
        foreach ($bridges as $from => $targets) {
            if (! array_key_exists($from, $transitions) || ! is_array($transitions[$from])) {
                $transitions[$from] = $targets;
            }
        }
        $lifecycle['transitions'] = $transitions;
        $this->writeSetting('lifecycle.settings', $lifecycle);
    }

    public function down(): void
    {
        // Intentionally non-destructive. These settings may have been adjusted by management after deployment.
    }

    private function mergeSetting(string $key, array $defaults): void
    {
        $current = $this->readSetting($key);
        $this->writeSetting($key, array_replace($defaults, $current));
    }

    private function readSetting(string $key): array
    {
        $row = DB::table('system_settings')->where('key', $key)->first();
        if (! $row) {
            return [];
        }
        $decoded = json_decode((string) $row->value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeSetting(string $key, array $value): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()],
        );
    }
};
