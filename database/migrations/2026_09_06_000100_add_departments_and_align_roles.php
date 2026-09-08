<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->after('phone')
                ->constrained('departments')
                ->nullOnDelete();
        });

        // v01.00 used a database ENUM (admin/uploader). v02.00 moves role
        // validation to the application layer so future role additions do not
        // require destructive ENUM alterations on MariaDB/MySQL.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default('viewer')->change();
        });

        DB::table('users')->where('role', 'admin')->update(['role' => 'department-admin']);
        DB::table('users')->where('role', 'uploader')->update(['role' => 'department-operator']);

        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Unassigned',
            'is_active' => true,
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('media_files', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->after('subcategory_id')
                ->constrained('departments')
                ->nullOnDelete();
        });

        DB::table('media_files')
            ->whereNull('department_id')
            ->update(['department_id' => $departmentId]);

        DB::table('users')
            ->where('role', '!=', 'super-admin')
            ->whereNull('department_id')
            ->update(['department_id' => $departmentId]);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'department-admin')->update(['role' => 'admin']);
        DB::table('users')->where('role', 'department-operator')->update(['role' => 'uploader']);

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->enum('role', ['super-admin', 'admin', 'uploader', 'viewer'])
                ->default('viewer')
                ->change();
        });

        Schema::dropIfExists('departments');
    }
};
