<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\MediaFileVersion;
use App\Services\AuditService;
use App\Services\MediaAccessService;
use App\Services\MediaVersionService;
use App\Services\UploadPolicyService;
use App\Services\SearchIndexService;
use App\Services\NotificationDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaLifecycleController extends Controller
{
    public function versions(Request $request, MediaFile $mediaFile, MediaAccessService $access)
    {
        abort_unless($access->canManageLifecycle($mediaFile, $request->user()), 403);
        $versions = $mediaFile->versions()->with('changedBy:id,name')->get()->map(fn (MediaFileVersion $v) => [
            'id' => $v->id,
            'version_number' => $v->version_number,
            'original_name' => $v->original_name,
            'size' => $v->size,
            'checksum_sha256' => $v->checksum_sha256,
            'change_note' => $v->change_note,
            'changed_by' => $v->changedBy?->name,
            'restored_from_version_id' => $v->restored_from_version_id,
            'created_at' => optional($v->created_at)->toIso8601String(),
            'is_current' => $v->version_number === (int) $mediaFile->current_version,
            'download_url' => route('files.versions.download', [$mediaFile, $v]),
        ]);

        return response()->json([
            'current_version' => (int) $mediaFile->current_version,
            'versions' => $versions,
            'can_upload_version' => $access->canUploadVersion($mediaFile, $request->user()),
            'can_restore_version' => $access->canRestoreVersion($mediaFile, $request->user()),
            'can_archive' => $access->canArchive($mediaFile, $request->user()),
        ]);
    }

    public function storeVersion(Request $request, MediaFile $mediaFile, MediaAccessService $access, MediaVersionService $versions, AuditService $audit, UploadPolicyService $uploadPolicy, NotificationDeliveryService $notifications)
    {
        abort_unless($access->canUploadVersion($mediaFile, $request->user()), 403);
        $data = $request->validate([
            'file' => 'required|file',
            'change_note' => 'required|string|min:3|max:1000',
        ]);
        $file = $request->file('file');
        $uploadPolicy->validateUploadedFile($file);
        $ext = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($ext, \App\Http\Controllers\MediaFileController::ALLOWED_MIMES, true), 422, 'File type not allowed.');
        abort_unless($this->resolveType($ext) === $mediaFile->type, 422, 'New version must use the same media type as the logical asset.');

        $version = $versions->replaceWithUpload($mediaFile, $file, $request->user(), $data['change_note']);
        $audit->log($request, 'media.version.created', $mediaFile, 'New media version uploaded.', [
            'version_number' => $version->version_number,
            'change_note' => $version->change_note,
        ]);
        $mediaFile->loadMissing('uploader');
        if ($mediaFile->uploader && !$mediaFile->uploader->is($request->user())) $notifications->sendEvent($mediaFile->uploader,'file_updated','File version updated',$mediaFile->name.' has a new version '.$version->version_number.'.',['media_file_id'=>$mediaFile->id,'version'=>$version->version_number]);
        return response()->json(['message' => "Version {$version->version_number} uploaded.", 'version_number' => $version->version_number], 201);
    }

    public function restoreVersion(Request $request, MediaFile $mediaFile, MediaFileVersion $version, MediaAccessService $access, MediaVersionService $versions, AuditService $audit, NotificationDeliveryService $notifications)
    {
        abort_unless($version->media_file_id === $mediaFile->id, 404);
        abort_unless($access->canRestoreVersion($mediaFile, $request->user()), 403);
        $data = $request->validate(['change_note' => 'nullable|string|max:1000']);
        $new = $versions->restoreVersion($mediaFile, $version, $request->user(), $data['change_note'] ?? null);
        $audit->log($request, 'media.version.restored', $mediaFile, 'Historical version restored as a new current version.', [
            'restored_from_version' => $version->version_number,
            'new_version' => $new->version_number,
        ]);
        $mediaFile->loadMissing('uploader');
        if ($mediaFile->uploader && !$mediaFile->uploader->is($request->user())) $notifications->sendEvent($mediaFile->uploader,'file_updated','File version restored',$mediaFile->name.' restored an older version as current version '.$new->version_number.'.',['media_file_id'=>$mediaFile->id,'version'=>$new->version_number]);
        return response()->json(['message' => "Version {$version->version_number} restored as new version {$new->version_number}."]);
    }

    public function downloadVersion(Request $request, MediaFile $mediaFile, MediaFileVersion $version, MediaAccessService $access)
    {
        abort_unless($version->media_file_id === $mediaFile->id, 404);
        abort_unless($access->canManageLifecycle($mediaFile, $request->user()), 403);
        $disk = Storage::disk('media');
        abort_unless($disk->exists($version->file_path), 404, 'Version file is missing.');
        return $disk->download($version->file_path, $version->original_name);
    }

    public function archive(Request $request, MediaFile $mediaFile, MediaAccessService $access, SearchIndexService $search, AuditService $audit, NotificationDeliveryService $notifications)
    {
        abort_unless($access->canArchive($mediaFile, $request->user()), 403);
        $data = $request->validate(['archived' => 'required|boolean']);
        $before = $mediaFile->asset_status;
        if ($data['archived']) {
            if ($mediaFile->asset_status !== 'archived') {
                $mediaFile->forceFill([
                    'archived_from_status' => $mediaFile->asset_status,
                    'asset_status' => 'archived',
                    'archived_at' => now(),
                    'archived_by' => $request->user()->id,
                ])->save();
            }
        } else {
            $mediaFile->forceFill([
                'asset_status' => $mediaFile->archived_from_status ?: 'active',
                'archived_from_status' => null,
                'archived_at' => null,
                'archived_by' => null,
            ])->save();
        }
        $search->upsert($mediaFile->fresh(['category','subcategory','department']));
        $audit->log($request, $data['archived'] ? 'media.archived' : 'media.unarchived', $mediaFile, $data['archived'] ? 'Media archived.' : 'Media restored from archive.', [
            'before_status' => $before,
            'after_status' => $mediaFile->asset_status,
        ]);
        $mediaFile->loadMissing('uploader');
        if ($mediaFile->uploader && !$mediaFile->uploader->is($request->user())) $notifications->sendEvent($mediaFile->uploader,'file_updated',$data['archived']?'File archived':'File restored from archive',$mediaFile->name.' status changed to '.$mediaFile->asset_status.'.',['media_file_id'=>$mediaFile->id,'asset_status'=>$mediaFile->asset_status]);
        return response()->json(['message' => $data['archived'] ? 'Asset archived.' : 'Asset restored from archive.', 'asset_status' => $mediaFile->asset_status]);
    }

    public function recycleBin(Request $request, MediaAccessService $access)
    {
        $query = MediaFile::onlyTrashed()->with(['department:id,name','uploader:id,name'])->latest('deleted_at');
        if ($request->user()->role !== 'super-admin') {
            abort_unless($request->user()->role === 'department-admin', 403);
            $query->where('department_id', $request->user()->department_id);
        }
        return response()->json(['items' => $query->limit(250)->get()->map(fn (MediaFile $m) => [
            'id'=>$m->id,'name'=>$m->name,'type'=>$m->type,'size'=>$m->size,'department'=>$m->department?->name,
            'current_version'=>$m->current_version,'deleted_at'=>optional($m->deleted_at)->toIso8601String(),
        ])]);
    }

    public function restoreDeleted(Request $request, int $mediaFile, MediaAccessService $access, SearchIndexService $search, AuditService $audit)
    {
        $media = MediaFile::onlyTrashed()->findOrFail($mediaFile);
        abort_unless($access->canRestoreDeleted($media, $request->user()), 403);
        $media->restore();
        $search->upsert($media->fresh(['category','subcategory','department']));
        $audit->log($request, 'media.recycle-bin.restored', $media, 'Media restored from Recycle Bin.');
        return response()->json(['message' => 'File restored from Recycle Bin.']);
    }

    public function forceDelete(Request $request, int $mediaFile, AuditService $audit, SearchIndexService $search)
    {
        abort_unless($request->user()->role === 'super-admin', 403, 'Permanent deletion is restricted to Super Admin.');
        $media = MediaFile::withTrashed()->with('versions')->findOrFail($mediaFile);
        abort_unless($media->trashed(), 422, 'Move the file to Recycle Bin before permanent deletion.');

        $paths = $media->versions->pluck('file_path')->filter()->unique()->values()->all();
        if ($media->thumbnail_path) $paths[] = $media->thumbnail_path;
        $paths = array_values(array_unique(array_filter($paths, fn ($p) => !str_starts_with((string)$p, 'http'))));
        $audit->log($request, 'media.permanently-deleted', $media, 'Media permanently deleted from Recycle Bin.', [
            'name'=>$media->name,'department_id'=>$media->department_id,'versions'=>$media->versions->count(),
        ]);
        Storage::disk('media')->delete($paths);
        $id = $media->id;
        $media->forceDelete();
        $search->delete($id);
        return response()->json(['message' => 'File permanently deleted.']);
    }

    private function resolveType(string $ext): string
    {
        if (in_array($ext, ['mp4','mov','avi','mkv','webm','flv','wmv','m4v','3gp'], true)) return 'video';
        if (in_array($ext, ['jpg','jpeg','png','webp','gif','svg','bmp','tiff','tif','ico','heic','heif','avif','raw'], true)) return 'image';
        if (in_array($ext, ['mp3','wav','aac','ogg','m4a','flac','wma','opus'], true)) return 'audio';
        return 'document';
    }
}
