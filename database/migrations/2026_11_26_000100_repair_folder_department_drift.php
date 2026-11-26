<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put assets back in the department of the folder they live in.
 *
 * Until v19.13 an upload took its owner department from the person uploading.
 * A Super Admin belongs to no department, so their uploads landed in the system
 * "Unassigned" department even when they were uploaded into a folder owned by a
 * real one. The folder pane filters by department, so those files were invisible
 * there while the tree still counted them — and the folder could not be deleted,
 * because it was not empty after all. The same drift applies to sub-folders a
 * directory upload created under a parent in another department.
 *
 * This is a repair, not a policy change: nothing moves between folders, and only
 * rows whose folder already says otherwise are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sub-folders first, so files then inherit a corrected parent.
        for ($pass = 0; $pass < 12; $pass++) {
            $fixed = DB::table('media_folders as child')
                ->join('media_folders as parent', 'parent.id', '=', 'child.parent_id')
                ->whereColumn('child.department_id', '!=', 'parent.department_id')
                ->pluck('child.id');
            if ($fixed->isEmpty()) break;
            foreach ($fixed as $id) {
                $parent = DB::table('media_folders as child')
                    ->join('media_folders as parent', 'parent.id', '=', 'child.parent_id')
                    ->where('child.id', $id)
                    ->value('parent.department_id');
                DB::table('media_folders')->where('id', $id)->update(['department_id' => $parent]);
            }
        }

        DB::table('media_files')->whereNotNull('folder_id')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $folderDept = DB::table('media_folders')->where('id', $row->folder_id)->value('department_id');
                if ($folderDept && (int) $folderDept !== (int) $row->department_id) {
                    DB::table('media_files')->where('id', $row->id)->update(['department_id' => $folderDept]);
                }
            }
        });
    }

    public function down(): void
    {
        // The original department is not recoverable, and it was wrong anyway.
    }
};
