<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Services\AuditService;
use App\Services\MediaAccessService;
use App\Services\MediaVersionService;
use App\Services\UploadPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VersionChunkUploadController extends Controller
{
    public function store(Request $request, MediaFile $mediaFile, MediaAccessService $access, MediaVersionService $versions, AuditService $audit, UploadPolicyService $uploadPolicy)
    {
        abort_unless($access->canUploadVersion($mediaFile, $request->user()), 403);
        $request->validate([
            'upload_id'=>'required|string|max:64|regex:/^[a-zA-Z0-9\-]+$/',
            'chunk_index'=>'required|integer|min:0',
            'total_chunks'=>'required|integer|min:1|max:200000',
            'total_size'=>'required|integer|min:1',
            'chunk'=>'required|file|max:20480',
            'is_last_chunk'=>'required|in:true,false,1,0',
            'filename'=>'required|string|max:255',
            'change_note'=>'nullable|string|max:1000',
        ]);

        $ext=strtolower(pathinfo($request->filename, PATHINFO_EXTENSION));
        $uploadPolicy->validateFile($request->filename, (int)$request->total_size);
        abort_unless(in_array($ext, MediaFileController::ALLOWED_MIMES, true), 422, 'File type not allowed.');
        abort_unless($this->resolveType($ext)===$mediaFile->type, 422, 'New version must use the same media type as the logical asset.');

        $isLast=in_array($request->is_last_chunk,['true','1',true,1],true);
        if ($isLast && mb_strlen(trim((string)$request->change_note)) < 3) abort(422, 'A change note is required for a new version.');

        $dir="version-chunks/{$request->user()->id}/{$mediaFile->id}/{$request->upload_id}";
        $chunkPath="{$dir}/chunk_".(int)$request->chunk_index;
        $stream=fopen($request->file('chunk')->getRealPath(),'rb');
        Storage::disk('local')->put($chunkPath,$stream);
        if(is_resource($stream)) fclose($stream);
        if(!$isLast) return response()->json(['received'=>(int)$request->chunk_index],202);

        $uuid=Str::uuid();
        $finalRel="uploads/{$mediaFile->type}s/{$uuid}.{$ext}";
        $finalAbs=Storage::disk('media')->path($finalRel);
        if(!is_dir(dirname($finalAbs))) mkdir(dirname($finalAbs),0755,true);
        $out=fopen($finalAbs,'wb');
        if(!$out) abort(500,'Could not create final version file.');
        for($i=0;$i<(int)$request->total_chunks;$i++){
            $chunk=Storage::disk('local')->path("{$dir}/chunk_{$i}");
            if(!file_exists($chunk)){fclose($out);@unlink($finalAbs);Storage::disk('local')->deleteDirectory($dir);return response()->json(['message'=>"Missing chunk {$i}. Please retry."],422);}
            $in=fopen($chunk,'rb'); if(!$in){fclose($out);@unlink($finalAbs);return response()->json(['message'=>"Could not read chunk {$i}."],422);} stream_copy_to_stream($in,$out); fclose($in);
        }
        fclose($out); Storage::disk('local')->deleteDirectory($dir);
        $assembled=(int)filesize($finalAbs);
        if($assembled!==(int)$request->total_size){@unlink($finalAbs);abort(422,'Assembled version file size does not match the expected total size.');}

        try {
            $version=$versions->promoteExistingPath($mediaFile,$finalRel,$request->filename,$assembled,null,$request->user(),trim($request->change_note));
            $audit->log($request,'media.version.created',$mediaFile,'New media version uploaded with chunked transfer.',['version_number'=>$version->version_number,'change_note'=>$version->change_note]);
            return response()->json(['message'=>"Version {$version->version_number} uploaded.",'version_number'=>$version->version_number],201);
        } catch(\Throwable $e){@unlink($finalAbs);throw $e;}
    }

    public function status(Request $request, MediaFile $mediaFile, string $uploadId, MediaAccessService $access)
    {
        abort_unless($access->canUploadVersion($mediaFile,$request->user()),403);
        abort_unless(preg_match('/^[a-zA-Z0-9\-]+$/',$uploadId),422,'Invalid upload id.');
        $dir="version-chunks/{$request->user()->id}/{$mediaFile->id}/{$uploadId}";
        $received=[]; foreach(Storage::disk('local')->files($dir) as $file) if(preg_match('/chunk_(\d+)$/',$file,$m)) $received[]=(int)$m[1]; sort($received);
        return response()->json(['received'=>$received]);
    }

    public function cancel(Request $request, MediaFile $mediaFile, string $uploadId, MediaAccessService $access)
    {
        abort_unless($access->canUploadVersion($mediaFile,$request->user()),403);
        abort_unless(preg_match('/^[a-zA-Z0-9\-]+$/',$uploadId),422,'Invalid upload id.');
        Storage::disk('local')->deleteDirectory("version-chunks/{$request->user()->id}/{$mediaFile->id}/{$uploadId}");
        return response()->json(['message'=>'Version upload chunks cleared.']);
    }

    private function resolveType(string $ext): string
    {
        if(in_array($ext,['mp4','mov','avi','mkv','webm','flv','wmv','m4v','3gp'],true))return'video';
        if(in_array($ext,['jpg','jpeg','png','webp','gif','svg','bmp','tiff','tif','ico','heic','heif','avif','raw'],true))return'image';
        if(in_array($ext,['mp3','wav','aac','ogg','m4a','flac','wma','opus'],true))return'audio';
        return'document';
    }
}
