<?php

namespace App\Jobs;

use App\Models\MediaFile;
use App\Services\SearchIndexService;
use App\Services\UploadPolicyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessMediaFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;

    public function __construct(public int $mediaFileId) {}

    public function handle(SearchIndexService $searchIndex, UploadPolicyService $uploadPolicy): void
    {
        $media = MediaFile::find($this->mediaFileId);
        if (!$media || !$media->file_path || str_starts_with($media->file_path, 'http')) return;

        $disk = Storage::disk('media');
        if (!$disk->exists($media->file_path)) return;
        $absolute = $disk->path($media->file_path);

        $metadata = $media->technical_metadata ?? [];
        $media->processing_status = 'processing';
        $media->saveQuietly();

        $media->checksum_sha256 = hash_file('sha256', $absolute) ?: null;

        if ($media->checksum_sha256 && $uploadPolicy->exactDuplicateEnabled()) {
            $duplicate = MediaFile::query()
                ->where('department_id', $media->department_id)
                ->where('checksum_sha256', $media->checksum_sha256)
                ->whereKeyNot($media->id)
                ->orderBy('id')
                ->first();
            $media->duplicate_of_id = $duplicate?->id;
            if ($duplicate) {
                $metadata['duplicate_of'] = $duplicate->id;
                $metadata['duplicate_detected_at'] = now()->toIso8601String();
            } else {
                unset($metadata['duplicate_of'], $metadata['duplicate_detected_at']);
            }
        } elseif (!$uploadPolicy->exactDuplicateEnabled()) {
            $media->duplicate_of_id = null;
            unset($metadata['duplicate_of'], $metadata['duplicate_detected_at']);
        }

        if ($media->type === 'image') {
            $size = @getimagesize($absolute);
            if ($size) {
                $media->resolution = $size[0].'x'.$size[1];
                $metadata['width'] = $size[0];
                $metadata['height'] = $size[1];
                $metadata['mime'] = $size['mime'] ?? null;
            }
            $thumb = $this->createImageThumbnail($absolute, pathinfo($media->file_path, PATHINFO_FILENAME));
            if ($thumb) $media->thumbnail_path = $thumb;
        }

        if (in_array($media->type, ['video', 'audio'], true)) {
            $probe = $this->probeMedia($absolute);
            if ($probe) {
                $metadata['ffprobe'] = $probe;
                if ($media->type === 'video') {
                    foreach (($probe['streams'] ?? []) as $stream) {
                        if (!empty($stream['width']) && !empty($stream['height'])) {
                            $media->resolution = $stream['width'].'x'.$stream['height'];
                            break;
                        }
                    }
                }
            }
        }

        if ($media->type === 'video') {
            $thumb = $this->createVideoThumbnail($absolute, pathinfo($media->file_path, PATHINFO_FILENAME));
            if ($thumb) $media->thumbnail_path = $thumb;
        }

        $metadata['processed_at'] = now()->toIso8601String();
        $media->technical_metadata = $metadata;
        $media->processing_status = 'ready';
        $media->saveQuietly();
        $media->versions()->where('version_number', $media->current_version)->update([
            'checksum_sha256' => $media->checksum_sha256,
            'size' => $media->size,
        ]);
        $searchIndex->upsert($media->fresh(['category','subcategory','department']));
    }

    private function createImageThumbnail(string $source, string $base): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;
        $raw = @file_get_contents($source);
        $image = $raw ? @imagecreatefromstring($raw) : false;
        if (!$image) return null;
        $w=imagesx($image); $h=imagesy($image); $max=640;
        $scale=min(1,$max/max($w,$h)); $nw=max(1,(int)round($w*$scale)); $nh=max(1,(int)round($h*$scale));
        $thumb=imagecreatetruecolor($nw,$nh);
        imagecopyresampled($thumb,$image,0,0,0,0,$nw,$nh,$w,$h);
        $rel='thumbnails/'.$base.'.jpg';
        Storage::disk('media')->makeDirectory('thumbnails');
        imagejpeg($thumb, Storage::disk('media')->path($rel), 82);
        imagedestroy($thumb); imagedestroy($image);
        return $rel;
    }

    private function probeMedia(string $source): ?array
    {
        $ffprobe = env('FFPROBE_BIN', 'ffprobe');
        $cmd = sprintf(
            '%s -v error -show_entries format=duration,format_name,bit_rate -show_entries stream=codec_name,width,height,r_frame_rate,sample_rate,channels -of json %s 2>/dev/null',
            escapeshellcmd($ffprobe),
            escapeshellarg($source)
        );
        @exec($cmd, $out, $code);
        if ($code !== 0 || !$out) return null;
        $decoded = json_decode(implode("\n", $out), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function failed(Throwable $exception): void
    {
        MediaFile::query()->whereKey($this->mediaFileId)->update(['processing_status' => 'failed']);
    }

    private function createVideoThumbnail(string $source, string $base): ?string
    {
        $ffmpeg = env('FFMPEG_BIN', 'ffmpeg');
        $rel='thumbnails/'.$base.'.jpg';
        Storage::disk('media')->makeDirectory('thumbnails');
        $target=Storage::disk('media')->path($rel);
        $cmd=sprintf('%s -y -ss 00:00:02 -i %s -frames:v 1 -vf scale=640:-2 %s 2>/dev/null', escapeshellcmd($ffmpeg), escapeshellarg($source), escapeshellarg($target));
        @exec($cmd,$out,$code);
        return $code===0 && file_exists($target) ? $rel : null;
    }
}
