<?php

namespace App\Services;

use App\Models\MasterDataValue;

/**
 * One place for the Country -> State -> City -> Mandir rule.
 *
 * Upload and bulk edit previously carried their own copies that disagreed:
 * upload only checked a level when both the child and its parent were supplied,
 * so a city could be saved with no country or state. Bulk edit then re-validated
 * the stored values and refused, which stranded records that upload had accepted
 * even when the edit had nothing to do with location.
 */
class LocationHierarchyService
{
    /** Child field => the parent field it must hang under. */
    public const LEVELS = [
        'state_id' => 'country_id',
        'city_id' => 'state_id',
        'mandir_id' => 'city_id',
    ];

    public const FIELDS = ['country_id', 'state_id', 'city_id', 'mandir_id'];

    private function label(string $field): string
    {
        return match ($field) {
            'country_id' => 'Country',
            'state_id' => 'State',
            'city_id' => 'City',
            'mandir_id' => 'Mandir',
            default => ucfirst(str_replace('_id', '', $field)),
        };
    }

    /**
     * @param array<string,int|string|null> $ids
     * @return string|null the first problem, or null when the set is consistent
     */
    public function problemWith(array $ids): ?string
    {
        foreach (self::LEVELS as $child => $parent) {
            if (blank($ids[$child] ?? null)) continue;

            if (blank($ids[$parent] ?? null)) {
                return "Select a {$this->label($parent)} before choosing a {$this->label($child)}.";
            }

            $row = MasterDataValue::find($ids[$child]);
            if (!$row || (int) $row->parent_id !== (int) $ids[$parent]) {
                return "{$this->label($child)} does not belong to the selected {$this->label($parent)}.";
            }
        }

        return null;
    }

    public function assertValid(array $ids): void
    {
        if ($problem = $this->problemWith($ids)) abort(422, $problem);
    }

    /** Does this payload set or clear any location field? */
    public function touches(array $payload): bool
    {
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $payload)) return true;
        }

        return false;
    }
}
