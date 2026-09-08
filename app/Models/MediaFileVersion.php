<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaFileVersion extends Model
{
    protected $fillable = [
        'media_file_id', 'version_number', 'original_name', 'file_path', 'size',
        'checksum_sha256', 'mime_type', 'change_note', 'changed_by', 'restored_from_version_id',
    ];

    public function mediaFile() { return $this->belongsTo(MediaFile::class); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
    public function restoredFrom() { return $this->belongsTo(self::class, 'restored_from_version_id'); }
}
