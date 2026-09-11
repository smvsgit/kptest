<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('base_role', 40);
            $table->boolean('is_builtin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('permissions')->nullable();
            $table->json('page_access')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['base_role','is_active']);
        });

        $pagesByRole = [
            'super-admin' => ['browse'=>true,'upload'=>true,'access'=>true,'reports'=>true,'integrations'=>true,'guide'=>true,'settings'=>true],
            'department-admin' => ['browse'=>true,'upload'=>true,'access'=>true,'reports'=>true,'integrations'=>true,'guide'=>true,'settings'=>true],
            'department-operator' => ['browse'=>true,'upload'=>true,'access'=>true,'reports'=>true,'integrations'=>false,'guide'=>true,'settings'=>false],
            'viewer' => ['browse'=>true,'upload'=>false,'access'=>true,'reports'=>true,'integrations'=>false,'guide'=>true,'settings'=>false],
        ];
        $permissionsByRole = [
            'super-admin' => ['upload'=>true,'delete'=>true,'manage_policy'=>true,'manage_lifecycle'=>true,'review_access'=>true,'manage_categories'=>true],
            'department-admin' => ['upload'=>true,'delete'=>true,'manage_policy'=>true,'manage_lifecycle'=>true,'review_access'=>true,'manage_categories'=>true],
            'department-operator' => ['upload'=>true,'delete'=>false,'manage_policy'=>true,'manage_lifecycle'=>true,'review_access'=>false,'manage_categories'=>false],
            'viewer' => ['upload'=>false,'delete'=>false,'manage_policy'=>false,'manage_lifecycle'=>false,'review_access'=>false,'manage_categories'=>false],
        ];
        foreach (['super-admin'=>'Super Admin','department-admin'=>'Department Admin','department-operator'=>'Department Operator','viewer'=>'Viewer'] as $slug=>$name) {
            DB::table('portal_roles')->insert([
                'name'=>$name,
                'slug'=>$slug,
                'base_role'=>$slug,
                'is_builtin'=>true,
                'is_active'=>true,
                'permissions'=>json_encode($permissionsByRole[$slug]),
                'page_access'=>json_encode($pagesByRole[$slug]),
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('portal_role_id')->nullable()->after('role')->constrained('portal_roles')->nullOnDelete();
            $table->boolean('external_access_allowed')->default(false)->after('must_change_password');
            $table->timestamp('external_access_starts_at')->nullable()->after('external_access_allowed');
            $table->timestamp('external_access_expires_at')->nullable()->after('external_access_starts_at');
            $table->text('external_access_reason')->nullable()->after('external_access_expires_at');
            $table->foreignId('external_access_approved_by')->nullable()->after('external_access_reason')->constrained('users')->nullOnDelete();
            $table->string('ui_theme', 30)->default('smvs-dark')->after('preferred_language');
            $table->index(['external_access_allowed','external_access_expires_at'], 'users_external_access_idx');
        });

        foreach (DB::table('users')->select('id','role')->orderBy('id')->get() as $user) {
            $roleId = DB::table('portal_roles')->where('slug', $user->role)->value('id');
            DB::table('users')->where('id', $user->id)->update(['portal_role_id'=>$roleId]);
        }

        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->foreignId('portal_role_id')->nullable()->constrained('portal_roles')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['department_id','name'], 'user_groups_department_name_unique');
        });

        Schema::create('user_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_group_id','user_id']);
        });

        $network = $this->readSetting('security.network');
        $network = array_replace([
            'enabled'=>false,
            'allowed_cidrs'=>[],
            'trusted_vpn_proxies'=>[],
            'emergency_super_admin_email'=>'',
            'department_admin_can_manage_external_access'=>false,
        ], $network);
        $this->writeSetting('security.network', $network);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_group_members');
        Schema::dropIfExists('user_groups');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_external_access_idx');
            $table->dropConstrainedForeignId('external_access_approved_by');
            $table->dropConstrainedForeignId('portal_role_id');
            $table->dropColumn(['external_access_allowed','external_access_starts_at','external_access_expires_at','external_access_reason','ui_theme']);
        });
        Schema::dropIfExists('portal_roles');
    }

    private function readSetting(string $key): array
    {
        $row = DB::table('system_settings')->where('key', $key)->first();
        if (!$row) return [];
        $decoded = json_decode((string) $row->value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeSetting(string $key, array $value): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key'=>$key],
            ['value'=>json_encode($value), 'created_at'=>now(), 'updated_at'=>now()]
        );
    }
};
