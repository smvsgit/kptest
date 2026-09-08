<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class UploadPolicyService
{
    public const DEFAULT_EXTENSIONS = [
        'mp4','mov','avi','mkv','webm','flv','wmv','m4v','3gp',
        'jpg','jpeg','png','webp','gif','svg','bmp','tiff','tif','ico','heic','heif','avif','raw',
        'mp3','wav','aac','ogg','m4a','flac','wma','opus',
        'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf','zip','rar','7z',
    ];

    public function settings(): array
    {
        $saved = SystemSetting::valueFor('upload.settings', []);
        return array_merge([
            'allowed_extensions' => self::DEFAULT_EXTENSIONS,
            'max_file_size_mb' => 0,
            'max_batch_count' => 0,
            'chunk_threshold_mb' => 50,
            'chunk_size_mb' => 10,
            'retry_count' => 3,
            'exact_duplicate_detection' => true,
            'possible_duplicate_warning' => true,
        ], $saved);
    }

    public function validateFile(string $filename, int $size): void
    {
        $settings = $this->settings();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = array_values(array_unique(array_map('strtolower', $settings['allowed_extensions'] ?? [])));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw ValidationException::withMessages(['file' => 'File type .'.$ext.' is not allowed by Central Admin upload policy.']);
        }
        $maxMb = max(0, (int)($settings['max_file_size_mb'] ?? 0));
        if ($maxMb > 0 && $size > $maxMb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => "File exceeds the configured {$maxMb} MB application limit."]);
        }
    }

    public function validateUploadedFile(UploadedFile $file): void
    {
        $this->validateFile($file->getClientOriginalName(), (int)$file->getSize());
    }

    public function possibleDuplicates(User $user, string $filename, int $size, MediaAccessService $access, int $limit = 5): array
    {
        if (!($this->settings()['possible_duplicate_warning'] ?? true)) return [];
        $q = MediaFile::query()->where('name', $filename)->where('size', $size);
        $access->applyVisibility($q, $user);
        return $q->latest('id')->limit($limit)->get(['id','name','size','department_id','created_at'])
            ->map(fn(MediaFile $m) => ['id'=>$m->id,'name'=>$m->name,'size'=>$m->size,'department_id'=>$m->department_id,'created_at'=>optional($m->created_at)->toIso8601String()])
            ->all();
    }

    public function exactDuplicateEnabled(): bool
    {
        return (bool)($this->settings()['exact_duplicate_detection'] ?? true);
    }
}
