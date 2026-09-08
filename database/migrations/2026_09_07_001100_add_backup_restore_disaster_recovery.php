<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 30)->default('manual')->index();
            $table->string('retention_class', 20)->default('manual')->index();
            $table->string('status', 20)->default('running')->index();
            $table->string('destination_type', 30)->default('local');
            $table->string('storage_path', 1000)->nullable();
            $table->string('filename', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable()->index();
            $table->string('format_version', 30)->default('kp-backup-v1');
            $table->boolean('encrypted')->default(true);
            $table->json('scope')->nullable();
            $table->json('manifest')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['status','completed_at'], 'backup_status_completed_idx');
        });

        Schema::create('restore_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->string('verification_type', 30)->default('checksum')->index();
            $table->string('status', 20)->index();
            $table->string('target_environment', 120)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedBigInteger('verified_size_bytes')->nullable();
            $table->string('verified_sha256', 64)->nullable();
            $table->json('results')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['status','verified_at'], 'restore_verify_status_at_idx');
        });

        $now=now();
        DB::table('system_settings')->updateOrInsert(['key'=>'backup.settings'],[
            'value'=>json_encode([
                'destination_type'=>'local',
                'filesystem_path'=>'',
                'responsible_owner'=>'',
                'daily_enabled'=>false,
                'daily_hour'=>2,
                'weekly_enabled'=>false,
                'weekly_day'=>0,
                'weekly_hour'=>3,
                'monthly_enabled'=>false,
                'monthly_day'=>1,
                'monthly_hour'=>4,
                'daily_retention_days'=>0,
                'weekly_retention_days'=>0,
                'monthly_retention_days'=>0,
                'rpo_minutes'=>0,
                'rto_minutes'=>0,
                'backup_database'=>true,
                'backup_configuration'=>true,
                'backup_audit_security'=>true,
                'external_source_responsibility'=>'NAS / Google Drive / YouTube source-content backup is owned outside the portal backup and must be covered by the responsible source-system owner.',
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'created_at'=>$now,'updated_at'=>$now,
        ]);

        $channels=DB::table('system_settings')->where('key','notification.channels')->first();
        $notificationSettings=$channels?(json_decode($channels->value,true)?:[]):[];
        $notificationSettings['events']=array_merge([
            'backup_failed'=>['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
            'backup_rpo_warning'=>['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
        ],$notificationSettings['events']??[]);
        DB::table('system_settings')->updateOrInsert(['key'=>'notification.channels'],[
            'value'=>json_encode($notificationSettings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now,'updated_at'=>$now,
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key','backup.settings')->delete();
        Schema::dropIfExists('restore_verifications');
        Schema::dropIfExists('backup_runs');
    }
};
