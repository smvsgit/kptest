<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uat_cases', function (Blueprint $table) {
            $table->string('executed_app_version', 20)->nullable()->after('executed_at')->index();
            $table->string('approved_app_version', 20)->nullable()->after('approved_at')->index();
        });

        Schema::table('readiness_snapshots', function (Blueprint $table) {
            $table->string('app_version', 20)->nullable()->after('overall_status')->index();
        });

        Schema::table('go_live_reviews', function (Blueprint $table) {
            $table->string('app_version', 20)->nullable()->after('decision')->index();
        });
    }

    public function down(): void
    {
        Schema::table('go_live_reviews', function (Blueprint $table) {
            $table->dropIndex(['app_version']);
            $table->dropColumn('app_version');
        });
        Schema::table('readiness_snapshots', function (Blueprint $table) {
            $table->dropIndex(['app_version']);
            $table->dropColumn('app_version');
        });
        Schema::table('uat_cases', function (Blueprint $table) {
            $table->dropIndex(['executed_app_version']);
            $table->dropIndex(['approved_app_version']);
            $table->dropColumn(['executed_app_version','approved_app_version']);
        });
    }
};
