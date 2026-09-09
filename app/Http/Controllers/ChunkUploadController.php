<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMediaFile;
use App\Models\Department;
use App\Models\MasterDataValue;
use App\Models\MediaFile;
use App\Models\SystemSetting;
use App\Services\UploadPolicyService;
use App\Services\AuditService;
use App\Services\StorageQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChunkUploadController extends Controller
{
    public function store(Request $request, UploadPolicyService $uploadPolicy, AuditService $audit, StorageQuotaService $quota)
    {
        $settings=$uploadPolicy->settings();
        $chunkSizeBytes=max(5,min(20,(int)$settings['chunk_size_mb']))*1024*1024;
        $request->validate([
            'upload_id'=>'required|string|max:64|regex:/^[a-zA-Z0-9\-]+$/',
            'chunk_index'=>'required|integer|min:0',
            'total_chunks'=>'required|integer|min:1|max:200000',
            'total_size'=>'required|integer|min:1',
            'chunk'=>'required|file|max:'.(int)ceil($chunkSizeBytes/1024),
            'is_last_chunk'=>'required|in:true,false,1,0',
            'filename'=>'required|string|max:255',
            'category_id'=>'required_if:is_last_chunk,true|exists:categories,id',
            'subcategory_id'=>'nullable|exists:subcategories,id','tags'=>'nullable|string',
            'access_policy'=>'required_if:is_last_chunk,true|in:public,protected,private','download_allowed'=>'nullable|boolean',
            'year'=>'nullable|integer|min:1800|max:2500',
            'country_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','country')->where('is_active',true)],
            'state_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','state')->where('is_active',true)],
            'city_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','city')->where('is_active',true)],
            'mandir_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','mandir')->where('is_active',true)],
            'event_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','event')->where('is_active',true)],
            'person_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','person')->where('is_active',true)],
            'language_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','language')->where('is_active',true)],
            'media_type_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','media_type')->where('is_active',true)],
            'description'=>'nullable|string|max:5000','internal_remarks'=>'nullable|string|max:5000',
            'source_type'=>'nullable|in:local,nas,google-drive,youtube','asset_status'=>'nullable|in:draft,active','folder_id'=>'nullable|exists:media_folders,id','watermark_enabled'=>'nullable|boolean',
        ]);

        $uploadPolicy->validateFile($request->filename,(int)$request->total_size);
        $uploadId=$request->upload_id; $index=(int)$request->chunk_index; $totalChunks=(int)$request->total_chunks; $totalSize=(int)$request->total_size;
        abort_unless($index < $totalChunks,422,'Chunk index is outside expected sequence.');
        $expectedChunks=(int)ceil($totalSize/$chunkSizeBytes);
        abort_unless($totalChunks===$expectedChunks,422,'Chunk count does not match configured chunk size and total file size. Restart the upload.');
        $isLast=in_array($request->is_last_chunk,['true','1',true,1],true);
        abort_unless($isLast===($index===$totalChunks-1),422,'Last-chunk marker does not match chunk sequence.');

        $dir="chunks/{$request->user()->id}/{$uploadId}";
        $manifestPath="{$dir}/manifest.json";
        $fingerprint=hash('sha256',json_encode($settings));
        $manifest=Storage::disk('local')->exists($manifestPath)?json_decode(Storage::disk('local')->get($manifestPath),true):null;
        $expected=['filename'=>$request->filename,'total_size'=>$totalSize,'total_chunks'=>$totalChunks,'chunk_size'=>$chunkSizeBytes,'settings_fingerprint'=>$fingerprint];
        if($manifest){
            foreach($expected as $key=>$value) abort_unless(($manifest[$key]??null)===$value,409,'Upload settings or file details changed during resume. Cancel and restart this upload.');
        }else{
            Storage::disk('local')->put($manifestPath,json_encode($expected,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        }

        $actualChunkSize=(int)$request->file('chunk')->getSize();
        $expectedChunkSize=$isLast?($totalSize-($index*$chunkSizeBytes)):$chunkSizeBytes;
        abort_unless($actualChunkSize===$expectedChunkSize,422,"Chunk {$index} has unexpected size. Please retry this chunk.");
        $chunkPath="{$dir}/chunk_{$index}";
        $stream=fopen($request->file('chunk')->getRealPath(),'rb');
        Storage::disk('local')->put($chunkPath,$stream); if(is_resource($stream))fclose($stream);
        if(!$isLast)return response()->json(['received'=>$index],202);

        $this->enforceMetadataRequirements($request);
        $this->validateHierarchy($request);
        for($i=0;$i<$totalChunks;$i++) abort_unless(Storage::disk('local')->exists("{$dir}/chunk_{$i}"),422,"Missing chunk {$i}. Resume/retry before finalizing.");

        $ext=strtolower(pathinfo($request->filename,PATHINFO_EXTENSION)); $type=$this->resolveType($ext); $uuid=Str::uuid();
        $finalRel="uploads/{$type}s/{$uuid}.{$ext}"; $finalAbs=Storage::disk('media')->path($finalRel); if(!is_dir(dirname($finalAbs)))mkdir(dirname($finalAbs),0755,true);
        $out=fopen($finalAbs,'wb'); abort_unless($out!==false,500,'Could not create final upload file.');
        for($i=0;$i<$totalChunks;$i++){
            $chunk=Storage::disk('local')->path("{$dir}/chunk_{$i}"); $in=fopen($chunk,'rb');
            if(!$in){fclose($out);@unlink($finalAbs);abort(422,"Could not read chunk {$i}.");} stream_copy_to_stream($in,$out);fclose($in);
        }
        fclose($out);
        $assembled=(int)filesize($finalAbs);
        if($assembled!==$totalSize){@unlink($finalAbs);abort(422,"Assembled file size mismatch ({$assembled} vs {$totalSize}). Upload was not finalized.");}
        Storage::disk('local')->deleteDirectory($dir);

        $departmentId=$request->user()?->department_id??Department::where('is_system',true)->value('id'); if($departmentId)$quota->assertCanStore((int)$departmentId,$assembled);
        $resolution=null;if($type==='image'){[$w,$h]=@getimagesize($finalAbs)?:[null,null];if($w&&$h)$resolution="{$w}x{$h}";}
        $tags=$request->tags?array_values(array_filter(array_map('trim',explode(',',$request->tags)))):[];
        $media=MediaFile::create([
            'name'=>$request->filename,'type'=>$type,'size'=>$assembled,'category_id'=>$request->category_id,'subcategory_id'=>$request->subcategory_id,'department_id'=>$departmentId,'folder_id'=>$request->folder_id,'watermark_enabled'=>$request->has('watermark_enabled')?$request->boolean('watermark_enabled'):null,
            'year'=>$request->integer('year')?:null,'country_id'=>$request->country_id,'state_id'=>$request->state_id,'city_id'=>$request->city_id,'mandir_id'=>$request->mandir_id,
            'event_id'=>$request->event_id,'person_id'=>$request->person_id,'language_id'=>$request->language_id,'media_type_id'=>$request->media_type_id,
            'description'=>$request->description,'internal_remarks'=>$request->internal_remarks,'source_type'=>$request->input('source_type','local'),'asset_status'=>$request->input('asset_status','draft'),
            'access_policy'=>$request->access_policy?:'public','download_allowed'=>$request->boolean('download_allowed',true),'tags'=>$tags,'resolution'=>$resolution,
            'file_path'=>$finalRel,'thumbnail_path'=>null,'uploaded_by'=>$request->user()?->id,'owner_user_id'=>$request->user()?->id,
        ]);
        ProcessMediaFile::dispatch($media->id);
        $audit->log($request,'media.uploaded',$media,'Media uploaded with resumable chunk transfer.',[
            'name'=>$media->name,'size'=>$media->size,'type'=>$media->type,'department_id'=>$media->department_id,
            'chunks'=>$totalChunks,'chunk_size_bytes'=>$chunkSizeBytes,'access_policy'=>$media->access_policy,
        ]);
        return response()->json(['message'=>'Upload complete','id'=>$media->id],201);
    }

    public function status(Request $request,string $uploadId)
    {
        abort_unless(preg_match('/^[a-zA-Z0-9\-]+$/',$uploadId),422,'Invalid upload id.');
        $dir="chunks/{$request->user()->id}/{$uploadId}";$received=[];
        foreach(Storage::disk('local')->files($dir) as $file)if(preg_match('/chunk_(\d+)$/',$file,$m))$received[]=(int)$m[1];sort($received);
        $manifest=Storage::disk('local')->exists("{$dir}/manifest.json")?json_decode(Storage::disk('local')->get("{$dir}/manifest.json"),true):null;
        return response()->json(['received'=>$received,'manifest'=>$manifest]);
    }

    public function cancel(Request $request,string $uploadId)
    {
        abort_unless(preg_match('/^[a-zA-Z0-9\-]+$/',$uploadId),422,'Invalid upload id.');
        Storage::disk('local')->deleteDirectory("chunks/{$request->user()->id}/{$uploadId}");
        return response()->json(['message'=>'Upload chunks cleared.']);
    }

    private function enforceMetadataRequirements(Request $request): void
    {
        $settings=SystemSetting::valueFor('metadata.settings',[]);$required=$settings['required_fields']??[];
        if(($settings['person_required']??false)&&!in_array('person_id',$required,true))$required[]='person_id';
        $labels=['year'=>'Year','country_id'=>'Country','state_id'=>'State','city_id'=>'City','mandir_id'=>'Mandir','event_id'=>'Event / Prasang','person_id'=>'Guruji / Person','language_id'=>'Language','media_type_id'=>'Media Type','description'=>'Description'];
        $missing=[];foreach($required as $field)if(!$request->filled($field))$missing[]=$labels[$field]??$field;if($missing)abort(422,'Required metadata missing: '.implode(', ',$missing));
        $min=(int)($settings['years_min']??1950);$max=(int)($settings['years_max']??((int)date('Y')+2));if($request->filled('year')&&($request->integer('year')<$min||$request->integer('year')>$max))abort(422,"Year must be between {$min} and {$max}.");
    }

    private function validateHierarchy(Request $request): void
    {
        foreach([['state_id','country_id'],['city_id','state_id'],['mandir_id','city_id']] as [$child,$parent])if($request->filled($child)){ $row=MasterDataValue::find($request->input($child)); abort_unless($row&&(int)$row->parent_id===(int)$request->input($parent),422,ucfirst(str_replace('_id','',$child)).' does not belong to selected '.str_replace('_id','',$parent).'.'); }
    }

    private function resolveType(string $ext): string
    {
        if(in_array($ext,['mp4','mov','avi','mkv','webm','flv','wmv','m4v','3gp'],true))return'video';
        if(in_array($ext,['jpg','jpeg','png','webp','gif','svg','bmp','tiff','tif','ico','heic','heif','avif','raw'],true))return'image';
        if(in_array($ext,['mp3','wav','aac','ogg','m4a','flac','wma','opus'],true))return'audio';return'document';
    }
}
