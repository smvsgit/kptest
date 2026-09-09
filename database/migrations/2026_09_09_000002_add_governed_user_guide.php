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

        $existing = DB::table('system_settings')->where('key', 'user_guide.access')->first();
        if ($existing) {
            return;
        }

        DB::table('system_settings')->insert([
            'key' => 'user_guide.access',
            'value' => json_encode([
                'enabled' => true,
                'role_pages' => [
                    'department-admin' => 14,
                    'department-operator' => 10,
                    'viewer' => 5,
                ],
                'department_pages' => [],
                'user_pages' => [],
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Non-destructive by design. Super Admin may have changed the policy after deployment.
    }
};
