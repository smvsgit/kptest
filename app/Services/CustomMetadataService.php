<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\MetadataFieldDefinition;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Administrator-defined metadata: what the fields are, and what a value for
 * one has to look like before it is written. (Review F09b.)
 *
 * Three rules hold throughout, and the tests pin each of them down:
 *
 *  - a retired field keeps its values and stops being asked for;
 *  - a locked field may be set once and not changed again;
 *  - nothing here can touch a system column - the definitions cannot even be
 *    named after one.
 */
class CustomMetadataService
{
    /** @return Collection<int,MetadataFieldDefinition> */
    public function active(?string $mediaType = null): Collection
    {
        return MetadataFieldDefinition::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('label')
            ->get()
            ->filter(fn (MetadataFieldDefinition $field) => $field->appliesToType($mediaType))
            ->values();
    }

    /** Every definition, retired ones included, for the administrator's screen. */
    public function all(): Collection
    {
        return MetadataFieldDefinition::query()->orderBy('sort_order')->orderBy('label')->get();
    }

    /** The fields an asset of this type must carry before it may go live. */
    public function missingRequired(MediaFile $file, ?array $incoming = null): array
    {
        $values = array_replace($file->custom_metadata ?? [], $incoming ?? []);
        $missing = [];
        foreach ($this->active($file->type) as $field) {
            if (!$field->is_required) continue;
            if (blank($values[$field->key] ?? null)) $missing[] = $field->label;
        }
        return $missing;
    }

    /**
     * Check and clean one set of incoming values.
     *
     * Unknown keys are dropped rather than stored: a form that sends a field
     * someone retired in the meantime should not resurrect it.
     *
     * @param  MediaFile|null  $file  the asset being written, for the locked rule
     * @throws ValidationException
     */
    public function validate(array $incoming, ?MediaFile $file = null, ?string $mediaType = null): array
    {
        $type = $mediaType ?? $file?->type;
        $fields = $this->active($type)->keyBy('key');
        $existing = $file?->custom_metadata ?? [];
        $clean = [];
        $errors = [];

        foreach ($incoming as $key => $value) {
            $field = $fields->get($key);
            if (!$field) continue;

            if ($field->is_locked && filled($existing[$key] ?? null) && (string) $existing[$key] !== (string) $value) {
                $errors['custom_metadata.'.$key] = __('messages.metadata.field_locked', ['label' => $field->label]);
                continue;
            }

            if (blank($value)) {
                if ($field->is_required) {
                    $errors['custom_metadata.'.$key] = __('messages.metadata.field_required', ['label' => $field->label]);
                    continue;
                }
                $clean[$key] = null;
                continue;
            }

            $checked = $this->castValue($field, $value);
            if ($checked === null) {
                $errors['custom_metadata.'.$key] = __('messages.metadata.field_invalid', ['label' => $field->label]);
                continue;
            }
            $clean[$key] = $checked;
        }

        if ($errors) throw ValidationException::withMessages($errors);
        return $clean;
    }

    /** The stored value for one field, or null when it does not fit its type. */
    private function castValue(MetadataFieldDefinition $field, mixed $value): mixed
    {
        return match ($field->input_type) {
            'number' => is_numeric($value) ? 0 + $value : null,
            'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'date' => $this->dateOrNull((string) $value),
            'select' => in_array((string) $value, array_map('strval', $field->options ?: []), true) ? (string) $value : null,
            'textarea' => mb_strlen((string) $value) <= 5000 ? (string) $value : null,
            default => mb_strlen((string) $value) <= 300 ? (string) $value : null,
        };
    }

    /**
     * A business date, which is the administrator's to set and change - not
     * the upload timestamp, which belongs to the system and is never written
     * from here.
     */
    private function dateOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d', $time);
    }

    /** Merge new values over an asset's existing ones, keeping retired keys. */
    public function merge(MediaFile $file, array $clean): array
    {
        $next = array_replace($file->custom_metadata ?? [], $clean);
        return array_filter($next, fn ($value) => $value !== null || $value === false);
    }
}
