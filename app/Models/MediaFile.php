<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaFile extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name', 'type', 'size', 'category_id', 'subcategory_id', 'department_id',
        'year', 'country_id', 'state_id', 'city_id', 'mandir_id', 'event_id', 'person_id', 'language_id', 'media_type_id',
        'description', 'internal_remarks', 'source_type', 'asset_status', 'current_version', 'duplicate_of_id',
        'archived_from_status', 'archived_at', 'archived_by',
        'access_policy', 'download_allowed', 'tags', 'resolution', 'file_path', 'thumbnail_path', 'checksum_sha256',
        'technical_metadata','processing_status','uploaded_by','owner_user_id','folder_id','watermark_enabled',
    ];
    protected $casts = ['tags' => 'array', 'technical_metadata' => 'array', 'download_allowed' => 'boolean', 'watermark_enabled'=>'boolean', 'archived_at' => 'datetime'];
    protected $appends = ['thumbnail_url', 'video_url', 'audio_url', 'document_url', 'download_url'];

    public function category() { return $this->belongsTo(Category::class); }
    public function subcategory() { return $this->belongsTo(Subcategory::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function uploader(){return $this->belongsTo(User::class,'uploaded_by');}
    public function ownerUser(){return $this->belongsTo(User::class,'owner_user_id');}
    public function accessRequests() { return $this->hasMany(MediaAccessRequest::class); }
    public function versions() { return $this->hasMany(MediaFileVersion::class)->orderByDesc('version_number'); }
    public function duplicateOf() { return $this->belongsTo(self::class, 'duplicate_of_id')->withTrashed(); }
    public function archivedBy() { return $this->belongsTo(User::class, 'archived_by'); }
    public function favorites() { return $this->hasMany(MediaFavorite::class); }
    public function recentViews() { return $this->hasMany(MediaRecentView::class); }
    public function sources() { return $this->hasMany(MediaSource::class)->orderByDesc('is_primary')->orderBy('id'); }
    public function country() { return $this->belongsTo(MasterDataValue::class, 'country_id'); }
    public function state() { return $this->belongsTo(MasterDataValue::class, 'state_id'); }
    public function city() { return $this->belongsTo(MasterDataValue::class, 'city_id'); }
    public function mandir() { return $this->belongsTo(MasterDataValue::class, 'mandir_id'); }
    public function event() { return $this->belongsTo(MasterDataValue::class, 'event_id'); }
    public function person() { return $this->belongsTo(MasterDataValue::class, 'person_id'); }
    public function language() { return $this->belongsTo(MasterDataValue::class, 'language_id'); }
    public function mediaType() { return $this->belongsTo(MasterDataValue::class, 'media_type_id'); }

    public function folder() { return $this->belongsTo(MediaFolder::class, 'folder_id'); }
    public function collections() { return $this->belongsToMany(MediaCollection::class, 'media_collection_items', 'media_file_id', 'collection_id')->withTimestamps(); }
    public function statusHistory() { return $this->hasMany(MediaStatusHistory::class); }



    protected static function booted(): void
    {
        static::created(function (MediaFile $media) {
            if (!$media->file_path) return;
            if (!$media->versions()->exists()) $media->versions()->create([
                'version_number' => (int) ($media->current_version ?: 1), 'original_name' => $media->name,
                'file_path' => $media->file_path, 'size' => $media->size, 'checksum_sha256' => $media->checksum_sha256,
                'change_note' => 'Initial upload.', 'changed_by' => $media->uploaded_by,
            ]);
            if (!$media->sources()->exists()) $media->sources()->create([
                'type' => in_array($media->source_type,['local','nas','google-drive','youtube'],true)?$media->source_type:'local',
                'integration_connection_id' => ($media->source_type??'local')==='local' ? IntegrationConnection::where('type','local')->where('name','Portal Local Media Storage')->value('id') : null,
                'label'=>'Primary source','locator'=>$media->file_path,'is_primary'=>true,'status'=>'active','last_success_at'=>now(),
            ]);
        });
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->thumbnail_path) return null;
        if (str_starts_with($this->thumbnail_path, 'http')) return $this->thumbnail_path;
        return route('files.thumbnail', $this);
    }
    public function getVideoUrlAttribute(): ?string { return $this->type === 'video' && $this->file_path ? route('files.preview', $this) : null; }
    public function getAudioUrlAttribute(): ?string { return $this->type === 'audio' && $this->file_path ? route('files.preview', $this) : null; }
    public function getDocumentUrlAttribute(): ?string { return $this->type === 'document' && $this->file_path ? route('files.preview', $this) : null; }
    public function getDownloadUrlAttribute(): ?string { return $this->file_path ? route('files.download', $this) : null; }
}
