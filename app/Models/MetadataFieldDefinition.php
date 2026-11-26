<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetadataFieldDefinition extends Model
{
    public const INPUT_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];
    public const MEDIA_TYPES = ['image', 'video', 'audio', 'document'];

    /**
     * Names the portal keeps for itself. A custom field may not take one of
     * these, so a schema change can never shadow an id, a timestamp, a
     * checksum or anything else the audit trail depends on. (Review F09b.)
     */
    public const RESERVED_KEYS = [
        'id', 'name', 'type', 'size', 'file_path', 'thumbnail_path', 'checksum_sha256',
        'created_at', 'updated_at', 'deleted_at', 'uploaded_by', 'owner_user_id',
        'current_version', 'department_id', 'folder_id', 'category_id', 'subcategory_id',
        'access_policy', 'download_allowed', 'asset_status', 'tags', 'custom_metadata',
        'year', 'country_id', 'state_id', 'city_id', 'mandir_id', 'event_id', 'person_id',
        'language_id', 'media_type_id', 'description', 'internal_remarks', 'source_type',
        'resolution', 'processing_status', 'duplicate_of_id', 'watermark_enabled',
    ];

    protected $fillable = [
        'key', 'label', 'help', 'input_type', 'applies_to', 'options', 'default_value',
        'is_required', 'is_locked', 'is_active', 'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'applies_to' => 'array',
            'options' => 'array',
            'is_required' => 'boolean',
            'is_locked' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** No `applies_to` means every type. */
    public function appliesToType(?string $mediaType): bool
    {
        $types = $this->applies_to ?: [];
        return !$types || ($mediaType && in_array($mediaType, $types, true));
    }
}
