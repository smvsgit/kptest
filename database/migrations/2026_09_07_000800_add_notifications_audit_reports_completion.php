<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('portal_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('whatsapp_enabled')->default(true);
            $table->boolean('sms_enabled')->default(true);
            $table->boolean('access_enabled')->default(true);
            $table->boolean('file_enabled')->default(true);
            $table->boolean('storage_enabled')->default(true);
            $table->boolean('security_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_notification_id')->nullable()->constrained('portal_notifications')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100)->index();
            $table->string('category', 40)->default('access')->index();
            $table->string('channel', 20)->index();
            $table->string('provider', 80)->nullable();
            $table->string('recipient', 320)->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->json('response_meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at'], 'delivery_user_status_created_idx');
            $table->index(['channel', 'status', 'created_at'], 'delivery_channel_status_created_idx');
        });

        Schema::table('media_access_requests', function (Blueprint $table) {
            $table->timestamp('last_escalated_at')->nullable()->after('decided_at');
            $table->unsignedSmallInteger('escalation_count')->default(0)->after('last_escalated_at');
            $table->index(['status', 'created_at', 'last_escalated_at'], 'access_escalation_scan_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('session_id', 120)->nullable()->after('ip_address');
            $table->string('source', 40)->default('web')->after('session_id')->index();
            $table->string('previous_hash', 64)->nullable()->after('source');
            $table->string('record_hash', 64)->nullable()->after('previous_hash')->index();
        });

        // Backfill an ordered hash chain for the audit history that already exists.
        $previous = null;
        DB::table('audit_logs')->orderBy('id')->chunkById(250, function ($rows) use (&$previous) {
            foreach ($rows as $row) {
                $context = $row->context ? json_decode($row->context, true) : null;
                $payload = [
                    'user_id' => $row->user_id,
                    'event' => $row->event,
                    'auditable_type' => $row->auditable_type,
                    'auditable_id' => $row->auditable_id,
                    'description' => $row->description,
                    'context' => $context,
                    'ip_address' => $row->ip_address,
                    'user_agent' => $row->user_agent,
                    'session_id' => null,
                    'source' => 'web',
                    'created_at' => (string) $row->created_at,
                    'previous_hash' => $previous,
                ];
                $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                DB::table('audit_logs')->where('id', $row->id)->update([
                    'source' => 'web',
                    'previous_hash' => $previous,
                    'record_hash' => $hash,
                ]);
                $previous = $hash;
            }
        }, 'id');

        $now = now();
        $channels = DB::table('system_settings')->where('key', 'notification.channels')->first();
        $settings = $channels ? (json_decode($channels->value, true) ?: []) : [];
        $settings['events'] = array_merge([
            'access_request_created' => ['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
            'access_request_decided' => ['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
            'access_request_escalated' => ['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
            'file_updated' => ['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
            'broken_link_detected' => ['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
            'storage_warning' => ['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
            'security_alert' => ['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
        ], $settings['events'] ?? []);
        DB::table('system_settings')->updateOrInsert(['key'=>'notification.channels'], [
            'value'=>json_encode($settings, JSON_UNESCAPED_UNICODE), 'created_at'=>$now, 'updated_at'=>$now,
        ]);
        DB::table('system_settings')->updateOrInsert(['key'=>'notification.escalation'], [
            'value'=>json_encode([
                'enabled'=>false,
                'first_after_hours'=>24,
                'repeat_every_hours'=>24,
                'max_escalations'=>3,
            ], JSON_UNESCAPED_UNICODE), 'created_at'=>$now, 'updated_at'=>$now,
        ]);

        // Production MariaDB/MySQL gets a DB-level append-only guard in addition to
        // application model protection. Audit retention is intentionally not automated
        // until Management approves the retention policy.
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("DROP TRIGGER IF EXISTS audit_logs_prevent_update");
            DB::unprepared("DROP TRIGGER IF EXISTS audit_logs_prevent_delete");
            DB::unprepared("CREATE TRIGGER audit_logs_prevent_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only'");
            DB::unprepared("CREATE TRIGGER audit_logs_prevent_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("DROP TRIGGER IF EXISTS audit_logs_prevent_update");
            DB::unprepared("DROP TRIGGER IF EXISTS audit_logs_prevent_delete");
        }
        DB::table('system_settings')->where('key', 'notification.escalation')->delete();
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['session_id','source','previous_hash','record_hash']);
        });
        Schema::table('media_access_requests', function (Blueprint $table) {
            $table->dropIndex('access_escalation_scan_idx');
            $table->dropColumn(['last_escalated_at','escalation_count']);
        });
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_preferences');
    }
};
