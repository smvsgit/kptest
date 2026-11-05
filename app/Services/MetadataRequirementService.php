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
    /**
     * Catalogue keys, not English. These names are read back to a user when an
     * asset is refused or held as a draft, so they follow the request locale.
     * ui.fields.* is the same list the React forms label their inputs with.
     */
    public const LABELS = [
        'year' => 'ui.fields.year',
        'country_id' => 'ui.fields.country',
        'state_id' => 'ui.fields.state',
        'city_id' => 'ui.fields.city',
        'mandir_id' => 'ui.fields.mandir',
        'event_id' => 'ui.fields.event',
        'person_id' => 'ui.fields.person',
        'language_id' => 'ui.fields.language',
        'media_type_id' => 'ui.fields.media_type',
        'description' => 'ui.access.description',
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
            if (blank($values[$field] ?? null)) $missing[] = isset(self::LABELS[$field]) ? __(self::LABELS[$field]) : $field;
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
