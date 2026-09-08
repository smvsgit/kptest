<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->string('access_policy', 20)->default('public')->after('department_id')->index();
            $table->boolean('download_allowed')->default(true)->after('access_policy');
            $table->index(['access_policy', 'department_id', 'created_at'], 'media_policy_department_created_idx');
        });

        Schema::create('media_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_level', 20)->default('view'); // view | download
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | more-info
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['media_file_id', 'user_id', 'status'], 'access_media_user_status_idx');
            $table->index(['status', 'created_at'], 'access_status_created_idx');
        });

        Schema::create('portal_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('title', 180);
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at', 'created_at'], 'notification_user_read_created_idx');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100)->index();
            $table->string('auditable_type', 180)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('description')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id'], 'audit_subject_idx');
            $table->index(['user_id', 'created_at'], 'audit_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('portal_notifications');
        Schema::dropIfExists('media_access_requests');
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_policy_department_created_idx');
            $table->dropColumn(['access_policy', 'download_allowed']);
        });
    }
};
