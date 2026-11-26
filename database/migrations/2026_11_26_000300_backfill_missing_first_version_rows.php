<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every asset the version row it is currently on. (Review F11.)
 *
 * `MediaFile::created` records version 1, but only for assets created through
 * the model with events firing. Anything inserted before that hook existed, or
 * imported in bulk, has no row at all — its Version History reads empty, and
 * replacing its file would have dropped the original out of the record.
 *
 * This writes the missing row from what the asset itself says. It invents
 * nothing: the path, name, size and checksum are the asset's own, and the
 * change note says plainly that the row was reconstructed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('media_files')
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $current = max(1, (int) ($row->current_version ?: 1));
                    $exists = DB::table('media_file_versions')
                        ->where('media_file_id', $row->id)
                        ->where('version_number', $current)
                        ->exists();
                    if ($exists) continue;

                    DB::table('media_file_versions')->insert([
                        'media_file_id' => $row->id,
                        'version_number' => $current,
                        'original_name' => $row->name,
                        'file_path' => $row->file_path,
                        'size' => $row->size,
                        'checksum_sha256' => $row->checksum_sha256 ?? null,
                        'change_note' => 'Recorded from the asset itself; this version predates the version history.',
                        'changed_by' => $row->uploaded_by ?? null,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('media_file_versions')
            ->where('change_note', 'Recorded from the asset itself; this version predates the version history.')
            ->delete();
    }
};
