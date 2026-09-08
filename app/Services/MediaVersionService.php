<?php

namespace App\Services;

use App\Jobs\ProcessMediaFile;
use App\Models\MediaFile;
use App\Models\MediaFileVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MediaVersionService
{
    public function replaceWithUpload(MediaFile $media, UploadedFile $upload, User $user, string $changeNote): MediaFileVersion
    {
        $ext = strtolower($upload->getClientOriginalExtension());
        $path = $upload->storeAs("uploads/{$media->type}s", Str::uuid().'.'.$ext, 'media');
        if (!$path) throw new RuntimeException('Could not store the new version.');

        try {
            return $this->promotePath($media, $path, $upload->getClientOriginalName(), (int) $upload->getSize(), $upload->getMimeType(), $user, $changeNote);
        } catch (\Throwable $e) {
            Storage::disk('media')->delete($path);
            throw $e;
        }
    }


    public function promoteExistingPath(MediaFile $media, string $path, string $originalName, int $size, ?string $mime, User $user, string $changeNote): MediaFileVersion
    {
        return $this->promotePath($media, $path, $originalName, $size, $mime, $user, $changeNote);
    }

    public function restoreVersion(MediaFile $media, MediaFileVersion $source, User $user, ?string $note = null): MediaFileVersion
    {
        if ($source->media_file_id !== $media->id) throw new RuntimeException('Version does not belong to this asset.');
        $disk = Storage::disk('media');
        if (!$disk->exists($source->file_path)) throw new RuntimeException('Historical version bytes are missing from storage.');

        $ext = strtolower(pathinfo($source->original_name ?: $source->file_path, PATHINFO_EXTENSION));
        $newPath = "uploads/{$media->type}s/".Str::uuid().($ext ? '.'.$ext : '');
        if (!$disk->copy($source->file_path, $newPath)) throw new RuntimeException('Could not restore version bytes.');

        try {
            return $this->promotePath(
                $media,
                $newPath,
                $source->original_name,
                (int) $source->size,
                $source->mime_type,
                $user,
                $note ?: "Restored from version {$source->version_number}.",
                $source->id
            );
        } catch (\Throwable $e) {
            $disk->delete($newPath);
            throw $e;
        }
    }

    private function promotePath(MediaFile $media, string $path, string $originalName, int $size, ?string $mime, User $user, string $changeNote, ?int $restoredFrom = null): MediaFileVersion
    {
        $oldThumbnail = $media->thumbnail_path;
        $version = DB::transaction(function () use ($media, $path, $originalName, $size, $mime, $user, $changeNote, $restoredFrom) {
            /** @var MediaFile $locked */
            $locked = MediaFile::query()->whereKey($media->id)->lockForUpdate()->firstOrFail();
            $next = max(1, (int) $locked->current_version) + 1;

            $version = $locked->versions()->create([
                'version_number' => $next,
                'original_name' => $originalName,
                'file_path' => $path,
                'size' => $size,
                'mime_type' => $mime,
                'change_note' => $changeNote,
                'changed_by' => $user->id,
                'restored_from_version_id' => $restoredFrom,
            ]);

            $locked->forceFill([
                'current_version' => $next,
                'file_path' => $path,
                'size' => $size,
                'thumbnail_path' => null,
                'checksum_sha256' => null,
                'duplicate_of_id' => null,
                'resolution' => null,
                'technical_metadata' => null,
                'processing_status' => 'pending',
            ])->save();

            return $version;
        });

        if ($oldThumbnail && !str_starts_with($oldThumbnail, 'http')) Storage::disk('media')->delete($oldThumbnail);
        ProcessMediaFile::dispatch($media->id);
        return $version;
    }
}
