<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Services\AuditService;
use App\Services\MediaAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class FileDeliveryController extends Controller
{
    public function preview(Request $request, MediaFile $mediaFile, MediaAccessService $access, AuditService $audit)
    {
        abort_unless($access->canSeeMetadata($mediaFile, $request->user()), 404);
        abort_unless($access->canPreview($mediaFile, $request->user()), 403, 'Access approval is required to preview this protected file.');
        return $this->deliver($request, $mediaFile, false, $audit);
    }

    public function download(Request $request, MediaFile $mediaFile, MediaAccessService $access, AuditService $audit)
    {
        abort_unless($access->canSeeMetadata($mediaFile, $request->user()), 404);
        abort_unless($access->canDownload($mediaFile, $request->user()), 403, 'Download permission is not available for this file.');
        return $this->deliver($request, $mediaFile, true, $audit);
    }


    public function approvedDownload(Request $request, MediaFile $mediaFile, MediaAccessService $access, AuditService $audit)
    {
        abort_unless($request->hasValidSignature(), 403, 'This protected download link has expired or is invalid.');
        abort_unless((int)$request->query('request_id') > 0, 403);
        $approved=$access->approvedRequest($mediaFile,$request->user());
        abort_unless($approved && $approved->id === (int)$request->query('request_id') && $approved->access_level === 'download', 403, 'Protected download entitlement is no longer active.');
        return $this->deliver($request,$mediaFile,true,$audit);
    }

    public function thumbnail(Request $request, MediaFile $mediaFile, MediaAccessService $access)
    {
        abort_unless($access->canSeeMetadata($mediaFile, $request->user()), 404);
        abort_unless($access->canPreview($mediaFile, $request->user()), 403);
        $path = $mediaFile->thumbnail_path;
        if (!$path || str_starts_with($path, 'http')) return redirect()->away($path ?: '/logo.svg');
        abort_unless(Storage::disk('media')->exists($path), 404);
        return Storage::disk('media')->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function bulkDownload(Request $request, MediaAccessService $access, AuditService $audit)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer']);
        $files = MediaFile::query()->whereIn('id', $data['ids'])->get();
        abort_if($files->isEmpty(), 404);
        abort_if($files->count() !== count(array_unique($data['ids'])), 404, 'One or more files were not found.');
        foreach ($files as $file) {
            abort_unless($access->canSeeMetadata($file, $request->user()) && $access->canDownload($file, $request->user()), 403, 'One or more selected files are not downloadable with your current access.');
        }
        abort_unless(class_exists(ZipArchive::class), 503, 'Server ZIP support is unavailable. Enable php-zip.');

        $tmp = tempnam(sys_get_temp_dir(), 'karyalay_zip_');
        $zip = new ZipArchive();
        abort_unless($zip->open($tmp, ZipArchive::OVERWRITE) === true, 500, 'Could not prepare ZIP.');
        foreach ($files as $file) {
            if (!$file->file_path || str_starts_with($file->file_path, 'http')) continue;
            if (!Storage::disk('media')->exists($file->file_path)) continue;
            $zip->addFile(Storage::disk('media')->path($file->file_path), $file->name);
        }
        $zip->close();
        $audit->log($request, 'media.bulk-downloaded', null, 'Authorized media ZIP download.', ['media_file_ids' => $files->pluck('id')->all()]);
        return response()->download($tmp, 'karyalay-assets-'.now()->format('Ymd-His').'.zip')->deleteFileAfterSend(true);
    }

    private function deliver(Request $request, MediaFile $mediaFile, bool $download, AuditService $audit)
    {
        $path = $mediaFile->file_path;
        abort_unless($path, 404);
        if (str_starts_with($path, 'http')) {
            $audit->log($request, $download ? 'media.downloaded' : 'media.previewed', $mediaFile, 'Authorized external media delivery.');
            return redirect()->away($path);
        }
        abort_unless(Storage::disk('media')->exists($path), 404);

        $absolute = Storage::disk('media')->path($path);
        if ($download) {
            $audit->log($request, 'media.downloaded', $mediaFile, 'Authorized media download.');
            return response()->download($absolute, $mediaFile->name);
        }

        $size = filesize($absolute);
        $mime = mime_content_type($absolute) ?: 'application/octet-stream';
        $range = $request->header('Range');
        if (!$range) {
            $audit->log($request, 'media.previewed', $mediaFile, 'Authorized media preview.');
            return response()->file($absolute, ['Content-Type' => $mime, 'Accept-Ranges' => 'bytes', 'Content-Disposition' => 'inline; filename="'.addslashes($mediaFile->name).'"']);
        }

        if (!preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) return response('', 416, ['Content-Range' => 'bytes */'.$size]);
        $start = $m[1] !== '' ? (int) $m[1] : 0;
        $end = $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
        if ($start > $end || $start >= $size) return response('', 416, ['Content-Range' => 'bytes */'.$size]);
        $length = $end - $start + 1;
        if ($start === 0) $audit->log($request, 'media.previewed', $mediaFile, 'Authorized ranged media preview.');
        return response()->stream(function () use ($absolute, $start, $length) {
            $fh = fopen($absolute, 'rb'); fseek($fh, $start); $remaining = $length;
            while ($remaining > 0 && !feof($fh)) {
                $chunk = fread($fh, min(1024 * 1024, $remaining));
                if ($chunk === false) break;
                echo $chunk; $remaining -= strlen($chunk); flush();
            }
            fclose($fh);
        }, 206, [
            'Content-Type' => $mime, 'Content-Length' => (string) $length,
            'Content-Range' => "bytes {$start}-{$end}/{$size}", 'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="'.addslashes($mediaFile->name).'"',
        ]);
    }
}
