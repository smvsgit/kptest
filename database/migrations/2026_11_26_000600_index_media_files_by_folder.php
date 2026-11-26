<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index on the column the folder workspace navigates by. (Review F10.)
 *
 * The benchmark the review asked for found this. `media_files` carries
 * eighteen indexes and not one of them covered `folder_id`, which has been the
 * portal's home screen since v19.20: the folder tree counts the files in every
 * folder, and every one of those counts was a full scan of the library. On
 * SQLite, where a foreign key brings no index of its own, the tree took six
 * seconds against twenty-five thousand files. On MariaDB the foreign key's own
 * index hid most of it, but the soft-delete filter still ran row by row.
 *
 * The index is on (folder_id, deleted_at) because every one of those counts
 * also excludes deleted assets.
 */
return new class extends Migration
{
    private const NAME = 'media_folder_deleted_idx';

    public function up(): void
    {
        if (!Schema::hasTable('media_files') || !Schema::hasColumn('media_files', 'folder_id')) {
            return;
        }

        if ($this->hasIndex()) {
            return;
        }

        Schema::table('media_files', function (Blueprint $table) {
            $table->index(['folder_id', 'deleted_at'], self::NAME);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('media_files') || !$this->hasIndex()) {
            return;
        }

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex(self::NAME);
        });
    }

    private function hasIndex(): bool
    {
        foreach (Schema::getIndexes('media_files') as $index) {
            if (($index['name'] ?? null) === self::NAME) return true;
        }

        return false;
    }
};
