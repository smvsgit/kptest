<?php

use App\Support\SettingsSections;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-section access to the Settings console, held on the role. (Review F05.)
 *
 * Every existing role is seeded with the defaults for its base role, which are
 * exactly what that role could already do, so this migration changes nobody's
 * access on the day it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_roles', function (Blueprint $table) {
            $table->json('settings_access')->nullable()->after('page_access');
        });

        foreach (DB::table('portal_roles')->get(['id', 'base_role']) as $role) {
            DB::table('portal_roles')->where('id', $role->id)->update([
                'settings_access' => json_encode(SettingsSections::defaultsFor((string) $role->base_role)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('portal_roles', function (Blueprint $table) {
            $table->dropColumn('settings_access');
        });
    }
};
