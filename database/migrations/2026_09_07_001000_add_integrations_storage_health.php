<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name',120);
            $table->string('type',30); // local, nas, google-drive, youtube
            $table->boolean('is_active')->default(true)->index();
            $table->string('root_path',1000)->nullable();
            $table->string('base_url',1000)->nullable();
            $table->longText('credentials_encrypted')->nullable();
            $table->unsignedInteger('sync_frequency_minutes')->default(60);
            $table->unsignedTinyInteger('storage_warning_percent')->default(90);
            $table->string('status',30)->default('unknown')->index(); // healthy,degraded,unavailable,inactive
            $table->timestamp('last_checked_at')->nullable()->index();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('capacity_bytes')->nullable();
            $table->unsignedBigInteger('used_bytes')->nullable();
            $table->unsignedBigInteger('free_bytes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['type','name']);
        });

        Schema::create('media_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->nullable()->constrained('integration_connections')->nullOnDelete();
            $table->string('type',30)->index();
            $table->string('label',160)->nullable();
            $table->text('locator');
            $table->string('external_id',255)->nullable()->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->string('status',30)->default('active')->index(); // active,inactive,missing,broken
            $table->timestamp('last_checked_at')->nullable()->index();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('broken_detected_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('repair_note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['media_file_id','status']);
            $table->index(['integration_connection_id','last_checked_at'],'media_source_connection_check_idx');
        });

        Schema::create('integration_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_connection_id')->nullable()->constrained('integration_connections')->cascadeOnDelete();
            $table->foreignId('media_source_id')->nullable()->constrained('media_sources')->cascadeOnDelete();
            $table->string('status',30)->index();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('message',1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('checked_at')->useCurrent()->index();
        });

        $localConnectionId = DB::table('integration_connections')->insertGetId([
            'name'=>'Portal Local Media Storage','type'=>'local','is_active'=>true,'sync_frequency_minutes'=>60,
            'storage_warning_percent'=>90,'status'=>'unknown','created_at'=>now(),'updated_at'=>now(),
        ]);

        // Backfill the current physical/reference location as the first source for every existing asset.
        DB::table('media_files')->whereNotNull('file_path')->orderBy('id')->chunkById(250, function ($rows) use ($localConnectionId) {
            foreach ($rows as $row) {
                $type = in_array($row->source_type ?? 'local',['local','nas','google-drive','youtube'],true) ? ($row->source_type ?? 'local') : 'local';
                DB::table('media_sources')->insert([
                    'media_file_id'=>$row->id,'integration_connection_id'=>$type==='local'?$localConnectionId:null,'type'=>$type,'label'=>'Primary source','locator'=>$row->file_path,
                    'is_primary'=>true,'status'=>'active','last_success_at'=>$row->created_at ?? now(),
                    'created_at'=>$row->created_at ?? now(),'updated_at'=>$row->updated_at ?? now(),
                ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_health_checks');
        Schema::dropIfExists('media_sources');
        Schema::dropIfExists('integration_connections');
    }
};
