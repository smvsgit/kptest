<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now=now();
        DB::table('system_settings')->updateOrInsert(['key'=>'upload.settings'],[
            'value'=>json_encode([
                'allowed_extensions'=>['mp4','mov','avi','mkv','webm','flv','wmv','m4v','3gp','jpg','jpeg','png','webp','gif','svg','bmp','tiff','tif','ico','heic','heif','avif','raw','mp3','wav','aac','ogg','m4a','flac','wma','opus','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf','zip','rar','7z'],
                'max_file_size_mb'=>0,'max_batch_count'=>0,'chunk_threshold_mb'=>50,'chunk_size_mb'=>10,'retry_count'=>3,
                'exact_duplicate_detection'=>true,'possible_duplicate_warning'=>true,
            ],JSON_UNESCAPED_UNICODE),'created_at'=>$now,'updated_at'=>$now,
        ]);
        Schema::table('media_files', function(Blueprint $table){
            $table->index(['department_id','name','size'],'media_department_name_size_idx');
            $table->index(['department_id','asset_status','access_policy'],'media_bulk_scope_idx');
        });
    }
    public function down(): void
    {
        DB::table('system_settings')->where('key','upload.settings')->delete();
        Schema::table('media_files',function(Blueprint $table){$table->dropIndex('media_department_name_size_idx');$table->dropIndex('media_bulk_scope_idx');});
    }
};
