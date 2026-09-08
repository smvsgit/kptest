<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users','preferred_language')) $table->string('preferred_language',5)->default('en')->after('phone');
            if (!Schema::hasColumn('users','two_factor_secret')) $table->text('two_factor_secret')->nullable();
            if (!Schema::hasColumn('users','two_factor_recovery_codes')) $table->text('two_factor_recovery_codes')->nullable();
            if (!Schema::hasColumn('users','two_factor_confirmed_at')) $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        Schema::create('media_folders', function (Blueprint $table) {
            $table->id(); $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->string('name'); $table->text('description')->nullable(); $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
            $table->unique(['department_id','parent_id','name']);
        });
        Schema::create('media_collections', function (Blueprint $table) {
            $table->id(); $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name'); $table->text('description')->nullable(); $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
            $table->unique(['department_id','name']);
        });
        Schema::create('media_collection_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('collection_id')->constrained('media_collections')->cascadeOnDelete();
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
            $table->unique(['collection_id','media_file_id']);
        });
        Schema::create('media_related_assets', function (Blueprint $table) {
            $table->id(); $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->foreignId('related_media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->string('relation_type')->default('related'); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
            $table->unique(['media_file_id','related_media_file_id']);
        });
        Schema::create('media_status_histories', function (Blueprint $table) {
            $table->id(); $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->string('from_status')->nullable(); $table->string('to_status'); $table->text('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('report_type'); $table->string('frequency');
            $table->string('format')->default('csv'); $table->boolean('is_active')->default(true);
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->json('filters')->nullable(); $table->timestamp('last_run_at')->nullable(); $table->timestamp('next_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('scheduled_report_runs', function (Blueprint $table) {
            $table->id(); $table->foreignId('scheduled_report_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued'); $table->string('file_path')->nullable(); $table->string('checksum_sha256',64)->nullable();
            $table->unsignedBigInteger('row_count')->default(0); $table->text('error')->nullable(); $table->timestamp('started_at')->nullable(); $table->timestamp('finished_at')->nullable(); $table->timestamps();
        });

        Schema::table('media_files', function (Blueprint $table) {
            if (!Schema::hasColumn('media_files','folder_id')) $table->foreignId('folder_id')->nullable()->after('department_id')->constrained('media_folders')->nullOnDelete();
            if (!Schema::hasColumn('media_files','watermark_enabled')) $table->boolean('watermark_enabled')->nullable();
        });

        $defaults = [
            'maintenance.settings'=>['enabled'=>false,'message'=>'The Karyalay Portal is temporarily under maintenance.','allow_super_admin_bypass'=>true],
            'branding.settings'=>['portal_name'=>'Karyalay Portal','login_text'=>'Secure office media and document portal','default_language'=>'en','logo_path'=>null],
            'security.two_factor'=>['enabled'=>true,'required_super_admin'=>false,'issuer'=>'Karyalay Portal'],
            'security.network'=>['enabled'=>false,'allowed_cidrs'=>[],'trusted_vpn_proxies'=>[],'emergency_super_admin_email'=>''],
            'lifecycle.settings'=>['transitions'=>['draft'=>['review'],'review'=>['draft','approved'],'approved'=>['review','published'],'published'=>['archived'],'archived'=>['draft','published']]],
            'metadata.type_required'=>['video'=>[],'image'=>[],'audio'=>[],'document'=>[]],
            'watermark.settings'=>['enabled'=>false,'text'=>'Karyalay Portal','opacity'=>18,'protected_only'=>true],
            'audit.retention'=>['retention_days'=>0,'archive_before_prune'=>true,'max_per_run'=>1000,'last_run_at'=>null],
            'recycle.retention'=>['retention_days'=>0,'max_per_run'=>250,'last_run_at'=>null],
            'storage.quotas'=>['default_quota_gb'=>0,'warning_percent'=>80,'departments'=>[]],
            'reports.scheduler'=>['enabled'=>true],
            'help.faq'=>['enabled'=>true],
        ];
        foreach ($defaults as $key=>$value) DB::table('system_settings')->updateOrInsert(['key'=>$key],['value'=>json_encode($value),'created_at'=>now(),'updated_at'=>now()]);

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("DROP TRIGGER IF EXISTS audit_logs_prevent_update");
            DB::unprepared("DROP TRIGGER IF EXISTS audit_logs_prevent_delete");
            DB::unprepared("CREATE TRIGGER audit_logs_prevent_update BEFORE UPDATE ON audit_logs FOR EACH ROW BEGIN IF COALESCE(@karyalay_audit_retention,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only'; END IF; END");
            DB::unprepared("CREATE TRIGGER audit_logs_prevent_delete BEFORE DELETE ON audit_logs FOR EACH ROW BEGIN IF COALESCE(@karyalay_audit_retention,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only'; END IF; END");
        }
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) { if (Schema::hasColumn('media_files','folder_id')) $table->dropConstrainedForeignId('folder_id'); if (Schema::hasColumn('media_files','watermark_enabled')) $table->dropColumn('watermark_enabled'); });
        foreach (['scheduled_report_runs','scheduled_reports','media_status_histories','media_related_assets','media_collection_items','media_collections','media_folders'] as $table) Schema::dropIfExists($table);
        Schema::table('users', function (Blueprint $table) { foreach(['preferred_language','two_factor_secret','two_factor_recovery_codes','two_factor_confirmed_at'] as $c) if(Schema::hasColumn('users',$c)) $table->dropColumn($c); });
    }
};
