<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('organization_units')->cascadeOnDelete();
            $table->string('type', 30); // sub-department | team
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['department_id','parent_id','type','name'], 'org_unit_unique');
            $table->index(['department_id','type','is_active'], 'org_unit_scope_idx');
        });

        Schema::create('permission_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->json('permissions');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_unit_id')->nullable()->after('department_id')->constrained('organization_units')->nullOnDelete();
            $table->foreignId('permission_set_id')->nullable()->after('role')->constrained('permission_sets')->nullOnDelete();
            $table->string('status', 20)->default('active')->after('permission_set_id')->index();
            $table->text('status_reason')->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');
            $table->foreignId('status_changed_by')->nullable()->after('status_changed_at')->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('failed_login_count')->default(0)->after('status_changed_by');
            $table->timestamp('locked_until')->nullable()->after('failed_login_count')->index();
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->timestamp('last_seen_at')->nullable()->after('last_login_at');
            $table->boolean('must_change_password')->default(false)->after('last_seen_at');
            $table->index(['department_id','status','role'], 'users_department_status_role_idx');
        });

        Schema::create('user_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['user_id','created_at'], 'user_status_history_idx');
        });

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('delegator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['department_id','is_active','starts_at','ends_at'], 'delegation_active_window_idx');
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('uploaded_by')->constrained('users')->nullOnDelete();
            $table->index(['owner_user_id','department_id'], 'media_owner_department_idx');
        });

        DB::table('media_files')->whereNull('owner_user_id')->update(['owner_user_id' => DB::raw('uploaded_by')]);

        Schema::table('media_access_requests', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('decided_at')->index();
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
            $table->text('revoke_reason')->nullable()->after('revoked_at');
            $table->foreignId('reviewed_via_delegation_id')->nullable()->after('revoke_reason')->constrained('approval_delegations')->nullOnDelete();
            $table->index(['status','expires_at'], 'access_status_expiry_idx');
        });

        DB::table('system_settings')->updateOrInsert(['key'=>'security.auth'], [
            'value'=>json_encode([
                'failed_login_limit'=>5,
                'lockout_minutes'=>15,
                'session_timeout_minutes'=>120,
                'password_min_length'=>12,
            ]),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('system_settings')->updateOrInsert(['key'=>'access.maturity'], [
            'value'=>json_encode([
                'default_expiry_hours'=>0,
                'signed_download_minutes'=>15,
                'max_bulk_request_files'=>100,
            ]),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key',['security.auth','access.maturity'])->delete();
        Schema::table('media_access_requests', function (Blueprint $table) {
            $table->dropIndex('access_status_expiry_idx');
            $table->dropConstrainedForeignId('reviewed_via_delegation_id');
            $table->dropColumn(['expires_at','revoked_at','revoke_reason']);
        });
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_owner_department_idx');
            $table->dropConstrainedForeignId('owner_user_id');
        });
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('user_status_histories');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_department_status_role_idx');
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropConstrainedForeignId('permission_set_id');
            $table->dropConstrainedForeignId('organization_unit_id');
            $table->dropColumn(['status','status_reason','status_changed_at','failed_login_count','locked_until','last_login_at','last_seen_at','must_change_password']);
        });
        Schema::dropIfExists('permission_sets');
        Schema::dropIfExists('organization_units');
    }
};
