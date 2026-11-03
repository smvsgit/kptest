<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\SystemSetting;

/**
 * The mandatory-metadata rule, in one place.
 *
 * It used to be enforced only at upload, which meant a batch of two hundred
 * photographs could not be stored at all until each one had been described.
 * The requirement is really about what may appear in the library, not about
 * what may be stored, so it is now checked when an asset is made active. An
 * undescribed upload lands as a draft and is described afterwards, one file,
 * a selection, or a whole folder at a time.
 */
class MetadataRequirementService
{
    public const LABELS = [
        'year' => 'Year',
        'country_id' => 'Country',
        'state_id' => 'State',
        'city_id' => 'City',
        'mandir_id' => 'Mandir',
        'event_id' => 'Event / Prasang',
        'person_id' => 'Guruji / Person',
        'language_id' => 'Language',
        'media_type_id' => 'Media Type',
        'description' => 'Description',
    ];

    public function settings(): array
    {
        return SystemSetting::valueFor('metadata.settings', []);
    }

    /** The fields an administrator has made mandatory. */
    public function requiredFields(?array $settings = null): array
    {
        $settings ??= $this->settings();
        $required = $settings['required_fields'] ?? [];
        if (($settings['person_required'] ?? false) && !in_array('person_id', $required, true)) {
            $required[] = 'person_id';
        }
        return array_values(array_unique($required));
    }

    /** Human labels for the required fields an array of values does not fill. */
    public function missingFromValues(array $values, ?array $settings = null): array
    {
        $missing = [];
        foreach ($this->requiredFields($settings) as $field) {
            if (blank($values[$field] ?? null)) $missing[] = self::LABELS[$field] ?? $field;
        }
        return $missing;
    }

    /** Human labels for what a stored asset is still missing. */
    public function missingFor(MediaFile $media, ?array $settings = null): array
    {
        $values = [];
        foreach ($this->requiredFields($settings) as $field) $values[$field] = $media->{$field};
        return $this->missingFromValues($values, $settings);
    }

    /** True when the asset carries everything needed to appear in the library. */
    public function isDescribed(MediaFile $media): bool
    {
        return $this->missingFor($media) === [];
    }
}
