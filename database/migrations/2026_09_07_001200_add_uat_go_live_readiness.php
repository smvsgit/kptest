<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uat_cases', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('title', 500);
            $table->string('priority', 10)->default('P0')->index();
            $table->string('category', 80)->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('pending')->index();
            $table->text('execution_notes')->nullable();
            $table->string('evidence_reference', 1000)->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('readiness_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('overall_status', 20)->index();
            $table->json('checks');
            $table->json('summary')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('run_at')->index();
            $table->timestamps();
        });

        Schema::create('go_live_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('readiness_snapshot_id')->nullable()->constrained('readiness_snapshots')->nullOnDelete();
            $table->string('decision', 20)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->json('dependency_snapshot')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        $cases = [
            ['AC-01','Secure login + role/department visibility UAT','Security & Identity'],
            ['AC-02','Public / Protected / Private policy enforcement UAT','Access Control'],
            ['AC-03','Protected Request -> Decision -> Notification -> Audit end-to-end UAT','Access Workflow'],
            ['AC-04/05','NAS / Google Drive / YouTube access and Broken source behavior UAT','Integrations'],
            ['AC-06/07','Mandatory metadata, bulk upload, search and filters UAT','Upload & Search'],
            ['AC-08','Preview / video streaming and HTTP Range UAT','Media Delivery'],
            ['AC-09','Version history and Recycle Bin rules UAT','Lifecycle'],
            ['AC-10/11','Audit completeness and tamper-protection UAT','Audit & Security'],
            ['AC-12','Backup procedure and actual restore verification UAT','Backup & DR'],
            ['AC-13','Storage / integration health visibility UAT','Operations'],
            ['AC-14','Non-technical user usability validation / UAT sign-off','Usability'],
        ];
        $now=now();
        foreach ($cases as $i=>$case) {
            DB::table('uat_cases')->insert([
                'code'=>$case[0],'title'=>$case[1],'priority'=>'P0','category'=>$case[2],'sort_order'=>($i+1)*10,
                'status'=>'pending','created_at'=>$now,'updated_at'=>$now,
            ]);
        }

        DB::table('system_settings')->updateOrInsert(['key'=>'go_live.settings'],[
            'value'=>json_encode([
                'planned_go_live_at'=>'',
                'deployment_owner'=>'',
                'rollback_owner'=>'',
                'business_signoff_owner'=>'',
                'smoke_test_owner'=>'',
                'support_contact'=>'',
                'rollback_window_minutes'=>0,
                'change_freeze_confirmed'=>false,
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'created_at'=>$now,'updated_at'=>$now,
        ]);

        DB::table('system_settings')->updateOrInsert(['key'=>'go_live.dependencies'],[
            'value'=>json_encode([
                ['key'=>'backup_destination_owner','label'=>'Production backup destination and responsible owner','status'=>'pending','note'=>''],
                ['key'=>'app_key_dr_custody','label'=>'Matching APP_KEY disaster-recovery custody','status'=>'pending','note'=>''],
                ['key'=>'backup_retention','label'=>'Backup retention policy','status'=>'pending','note'=>''],
                ['key'=>'rpo_target','label'=>'Approved RPO target','status'=>'pending','note'=>''],
                ['key'=>'rto_target','label'=>'Approved RTO target','status'=>'pending','note'=>''],
                ['key'=>'max_upload_size','label'=>'Production maximum upload/file size policy','status'=>'pending','note'=>''],
                ['key'=>'recycle_bin_retention','label'=>'Recycle Bin retention / permanent-purge policy','status'=>'pending','note'=>''],
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'created_at'=>$now,'updated_at'=>$now,
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key',['go_live.settings','go_live.dependencies','readiness.scheduler_heartbeat'])->delete();
        Schema::dropIfExists('go_live_reviews');
        Schema::dropIfExists('readiness_snapshots');
        Schema::dropIfExists('uat_cases');
    }
};
