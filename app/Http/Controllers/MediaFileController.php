<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\MediaFile;
use App\Jobs\ProcessMediaFile;
use App\Services\SearchIndexService;
use App\Services\AuditService;
use App\Services\MediaAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Models\SystemSetting;
use App\Services\UploadPolicyService;
use App\Services\StorageQuotaService;

class MediaFileController extends Controller
{
    public function store(Request $request, UploadPolicyService $uploadPolicy, AuditService $audit, StorageQuotaService $quota)
    {
        $request->validate([
            'file'           => 'required|file',
            'category_id'    => 'required|exists:categories,id',
            'subcategory_id' => 'nullable|exists:subcategories,id',
            'tags'           => 'nullable|string',
            'access_policy'  => 'required|in:public,protected,private',
            'download_allowed' => 'nullable|boolean',
            'year' => 'nullable|integer|min:1800|max:2500',
            'country_id' => ['nullable', Rule::exists('master_data_values','id')->where('type','country')->where('is_active',true)],
            'state_id' => ['nullable', Rule::exists('master_data_values','id')->where('type','state')->where('is_active',true)],
            'city_id' => ['nullable', Rule::exists('master_data_values','id')->where('type','city')->where('is_active',true)],
            'mandir_id' => ['nullable', Rule::exists('master_data_values','id')->where('type','mandir')->where('is_active',true)],
            'event_id' => ['nullable', Rule::exists('master_data_values','id')->where('type','event')->where('is_active',true)],
            'person_id' => ['nullable', Rule::exists('master_data_values','id')->where('type','person')->where('is_active',true)],
            'language_id' => ['nullable', Rule::exists('master_data_values','id')->where('type','language')->where('is_active',true)],
            'media_type_id' => ['nullable', Rule::exists('master_data_values','id')->where('type','media_type')->where('is_active',true)],
            'description' => 'nullable|string|max:5000',
            'internal_remarks' => 'nullable|string|max:5000',
            'source_type' => 'nullable|in:local,nas,google-drive,youtube',
            'asset_status' => 'nullable|in:active,draft,review,approved,published,archived,inactive,broken',
            'folder_id' => 'nullable|exists:media_folders,id',
            'watermark_enabled' => 'nullable|boolean',
        ]);
        $this->enforceMetadataRequirements($request);

        $file = $request->file('file');
        $uploadPolicy->validateUploadedFile($file);
        $name = $file->getClientOriginalName();
        $ext  = strtolower($file->getClientOriginalExtension());
        $type = $this->resolveType($ext);
        $size = $file->getSize();
        $departmentId = $request->user()?->department_id ?? Department::where('is_system', true)->value('id');
        if ($departmentId) $quota->assertCanStore((int)$departmentId,(int)$size);
        $path = $file->store("uploads/{$type}s", 'media');

        $thumbnailPath = null;
        $resolution    = null;

        if ($type === 'image') {
            [$w, $h] = @getimagesize($file->getRealPath()) ?: [null, null];
            if ($w && $h) $resolution = "{$w}x{$h}";
        }

        $tags = $request->tags
            ? array_map('trim', explode(',', $request->tags))
            : [];

        $media = MediaFile::create([
            'name'           => $name,
            'type'           => $type,
            'size'           => $size,
            'category_id'    => $request->category_id,
            'subcategory_id' => $request->subcategory_id,
            'department_id'  => $departmentId,
            'folder_id' => $request->folder_id,
            'watermark_enabled' => $request->has('watermark_enabled') ? $request->boolean('watermark_enabled') : null,
            'year' => $request->integer('year') ?: null,
            'country_id' => $request->country_id, 'state_id' => $request->state_id, 'city_id' => $request->city_id, 'mandir_id' => $request->mandir_id,
            'event_id' => $request->event_id, 'person_id' => $request->person_id, 'language_id' => $request->language_id, 'media_type_id' => $request->media_type_id,
            'description' => $request->description, 'internal_remarks' => $request->internal_remarks,
            'source_type' => $request->input('source_type','local'), 'asset_status' => $request->input('asset_status','active'),
            'access_policy'  => $request->access_policy,
            'download_allowed' => $request->boolean('download_allowed', true),
            'tags'           => $tags,
            'resolution'     => $resolution,
            'file_path'      => $path,
            'thumbnail_path' => $thumbnailPath,
            'uploaded_by'=>$request->user()?->id,'owner_user_id'=>$request->user()?->id,
        ]);

        ProcessMediaFile::dispatch($media->id);
        $audit->log($request, 'media.uploaded', $media, 'Media uploaded.', [
            'name'=>$media->name,'size'=>$media->size,'type'=>$media->type,'department_id'=>$media->department_id,
            'category_id'=>$media->category_id,'access_policy'=>$media->access_policy,'source_type'=>$media->source_type,
        ]);

        return response()->json(['message' => 'Uploaded successfully', 'id' => $media->id], 201);
    }

    public function updateAccessPolicy(Request $request, MediaFile $mediaFile, MediaAccessService $access, AuditService $audit, SearchIndexService $searchIndex)
    {
        abort_unless($access->canManagePolicy($mediaFile, $request->user()), 403);
        $data = $request->validate([
            'access_policy' => 'required|in:public,protected,private',
            'download_allowed' => 'required|boolean',
        ]);
        $before = ['access_policy' => $mediaFile->access_policy, 'download_allowed' => $mediaFile->download_allowed];
        $mediaFile->update($data);
        $searchIndex->upsert($mediaFile->fresh(['category','subcategory','department']));
        $audit->log($request, 'media.access-policy.updated', $mediaFile, 'Media access policy updated.', [
            'before' => $before,
            'after' => $data,
        ]);
        return response()->json(['message' => 'Access policy updated.']);
    }

    public function bulkDelete(Request $request, SearchIndexService $searchIndex, AuditService $audit)
    {
        $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);

        $query = MediaFile::whereIn('id', $request->ids);
        if ($request->user()?->role === 'department-admin') {
            $allowedCount = (clone $query)->where('department_id', $request->user()->department_id)->count();
            if ($allowedCount !== count($request->ids)) {
                abort(403, 'You can only move files owned by your department to Recycle Bin.');
            }
            $query->where('department_id', $request->user()->department_id);
        }

        $files = $query->get();
        foreach ($files as $file) {
            $id = $file->id;
            $audit->log($request, 'media.recycle-bin.moved', $file, 'Media moved to Recycle Bin.', [
                'name' => $file->name, 'current_version' => $file->current_version,
            ]);
            $file->delete(); // SoftDeletes: bytes and immutable versions are retained.
            $searchIndex->delete($id);
        }

        return back()->with('success', count($files).' file(s) moved to Recycle Bin.');
    }



    private function enforceMetadataRequirements(Request $request): void
    {
        $settings = SystemSetting::valueFor('metadata.settings', []);
        $required = $settings['required_fields'] ?? [];
        if (($settings['person_required'] ?? false) && !in_array('person_id', $required, true)) $required[] = 'person_id';
        $labels = ['year'=>'Year','country_id'=>'Country','state_id'=>'State','city_id'=>'City','mandir_id'=>'Mandir','event_id'=>'Event / Prasang','person_id'=>'Guruji / Person','language_id'=>'Language','media_type_id'=>'Media Type','description'=>'Description'];
        $missing=[];
        foreach ($required as $field) if (!$request->filled($field)) $missing[]=$labels[$field] ?? $field;
        if ($missing) abort(422, 'Required metadata missing: '.implode(', ', $missing));
        $min=(int)($settings['years_min'] ?? 1950); $max=(int)($settings['years_max'] ?? ((int)date('Y')+2));
        if ($request->filled('year') && ($request->integer('year')<$min || $request->integer('year')>$max)) abort(422, "Year must be between {$min} and {$max}.");
        $pairs=[['state_id','country_id'],['city_id','state_id'],['mandir_id','city_id']];
        foreach($pairs as [$childField,$parentField]) { if($request->filled($childField) && $request->filled($parentField)) { $child=\App\Models\MasterDataValue::find($request->input($childField)); if(!$child || (int)$child->parent_id !== (int)$request->input($parentField)) abort(422, ucfirst(str_replace('_id','',$childField)).' does not belong to selected '.str_replace('_id','',$parentField).'.'); } }
    }

    const ALLOWED_MIMES = [
        // Video
        'mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v', '3gp',
        // Image
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp', 'tiff', 'tif',
        'ico', 'heic', 'heif', 'avif', 'raw',
        // Audio
        'mp3', 'wav', 'aac', 'ogg', 'm4a', 'flac', 'wma', 'opus',
        // Document
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'rtf', 'zip', 'rar', '7z',
    ];

    private function resolveType(string $ext): string
    {
        if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v', '3gp'])) return 'video';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp', 'tiff', 'tif', 'ico', 'heic', 'heif', 'avif', 'raw'])) return 'image';
        if (in_array($ext, ['mp3', 'wav', 'aac', 'ogg', 'm4a', 'flac', 'wma', 'opus'])) return 'audio';
        return 'document';
    }
}
