<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->unsignedInteger('current_version')->default(1)->after('asset_status');
            $table->foreignId('duplicate_of_id')->nullable()->after('checksum_sha256')->constrained('media_files')->nullOnDelete();
            $table->string('archived_from_status', 30)->nullable()->after('asset_status');
            $table->timestamp('archived_at')->nullable()->after('archived_from_status')->index();
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->index(['department_id', 'deleted_at'], 'media_department_deleted_idx');
            $table->index(['asset_status', 'deleted_at'], 'media_status_deleted_idx');
        });

        Schema::create('media_file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('original_name');
            $table->string('file_path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum_sha256', 64)->nullable()->index();
            $table->string('mime_type', 150)->nullable();
            $table->text('change_note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_from_version_id')->nullable()->constrained('media_file_versions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['media_file_id', 'version_number'], 'media_version_unique');
            $table->index(['media_file_id', 'created_at'], 'media_version_created_idx');
        });

        // Existing assets become immutable v1 history entries without moving their bytes.
        DB::table('media_files')->orderBy('id')->chunkById(250, function ($rows) {
            foreach ($rows as $row) {
                if (!$row->file_path) continue;
                DB::table('media_file_versions')->insert([
                    'media_file_id' => $row->id,
                    'version_number' => 1,
                    'original_name' => $row->name,
                    'file_path' => $row->file_path,
                    'size' => $row->size ?? 0,
                    'checksum_sha256' => $row->checksum_sha256 ?? null,
                    'change_note' => 'Initial version (backfilled during v05.00 migration).',
                    'changed_by' => $row->uploaded_by,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('media_file_versions');
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_department_deleted_idx');
            $table->dropIndex('media_status_deleted_idx');
            $table->dropConstrainedForeignId('duplicate_of_id');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['current_version', 'archived_from_status', 'archived_at']);
            $table->dropSoftDeletes();
        });
    }
};
