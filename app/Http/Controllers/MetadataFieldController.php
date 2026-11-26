<?php

namespace App\Http\Controllers;

use App\Models\MetadataFieldDefinition;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The administrator's field builder. (Review F09b.)
 *
 * A field can be added, edited and retired. It is never deleted while assets
 * carry values for it, because the value is the record and the definition is
 * only how it is asked for.
 */
class MetadataFieldController extends Controller
{
    public function store(Request $request, AuditService $audit)
    {
        $data = $this->rules($request);
        $key = $this->keyFrom($data['label'], $request->input('key'));

        $field = MetadataFieldDefinition::create([
            'key' => $key,
            'label' => $data['label'],
            'help' => $data['help'] ?? null,
            'input_type' => $data['input_type'],
            'applies_to' => $data['applies_to'] ?? [],
            'options' => $this->optionsFor($data),
            'default_value' => $data['default_value'] ?? null,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_locked' => (bool) ($data['is_locked'] ?? false),
            'is_active' => true,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'created_by' => $request->user()->id,
        ]);

        $audit->log($request, 'metadata.field.created', null, 'Metadata field defined.', $field->only([
            'key', 'label', 'input_type', 'applies_to', 'is_required', 'is_locked',
        ]));

        return response()->json(['message' => __('messages.metadata.field_created'), 'field' => $field]);
    }

    public function update(Request $request, MetadataFieldDefinition $metadataField, AuditService $audit)
    {
        $data = $this->rules($request, $metadataField);
        $before = $metadataField->toArray();

        $metadataField->update([
            // The key is what stored values are filed under, so it never moves.
            'label' => $data['label'],
            'help' => $data['help'] ?? null,
            'input_type' => $data['input_type'],
            'applies_to' => $data['applies_to'] ?? [],
            'options' => $this->optionsFor($data),
            'default_value' => $data['default_value'] ?? null,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_locked' => (bool) ($data['is_locked'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $audit->log($request, 'metadata.field.updated', null, 'Metadata field updated.', [
            'before' => $before, 'after' => $metadataField->fresh()->toArray(),
        ]);

        return response()->json(['message' => __('messages.metadata.field_updated'), 'field' => $metadataField->fresh()]);
    }

    /**
     * Retiring is the normal end of a field's life. Deleting is allowed only
     * while nothing has ever been filed under it.
     */
    public function destroy(Request $request, MetadataFieldDefinition $metadataField, AuditService $audit)
    {
        $used = \App\Models\MediaFile::query()
            ->whereNotNull('custom_metadata')
            ->get(['id', 'custom_metadata'])
            ->contains(fn ($file) => array_key_exists($metadataField->key, $file->custom_metadata ?? []));

        abort_if($used, 422, __('messages.metadata.field_in_use', ['label' => $metadataField->label]));

        $audit->log($request, 'metadata.field.deleted', null, 'Metadata field deleted.', $metadataField->only(['key', 'label']));
        $metadataField->delete();

        return response()->json(['message' => __('messages.metadata.field_deleted')]);
    }

    private function rules(Request $request, ?MetadataFieldDefinition $existing = null): array
    {
        return $request->validate([
            'label' => 'required|string|max:120',
            'key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('metadata_field_definitions', 'key')->ignore($existing?->id)],
            'help' => 'nullable|string|max:300',
            'input_type' => ['required', Rule::in(MetadataFieldDefinition::INPUT_TYPES)],
            'applies_to' => 'nullable|array',
            'applies_to.*' => Rule::in(MetadataFieldDefinition::MEDIA_TYPES),
            'options' => 'nullable|array|max:50',
            'options.*' => 'string|max:120',
            'default_value' => 'nullable|string|max:300',
            'is_required' => 'nullable|boolean',
            'is_locked' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:999',
        ]);
    }

    private function optionsFor(array $data): array
    {
        if (($data['input_type'] ?? '') !== 'select') return [];
        $options = array_values(array_filter(array_map('trim', $data['options'] ?? [])));
        if (!$options) {
            throw ValidationException::withMessages(['options' => __('messages.metadata.select_needs_options')]);
        }
        return $options;
    }

    /**
     * A key is derived from the label unless one is given, and may never be a
     * name the portal keeps for itself - that is what stops a custom field
     * shadowing an id, a timestamp or a checksum.
     */
    private function keyFrom(string $label, ?string $given): string
    {
        $key = Str::snake(Str::ascii(trim((string) ($given ?: $label))));
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key)) ?: 'field';
        if (!preg_match('/^[a-z]/', $key)) $key = 'f_'.$key;
        if (in_array($key, MetadataFieldDefinition::RESERVED_KEYS, true)) {
            throw ValidationException::withMessages(['key' => __('messages.metadata.key_reserved', ['key' => $key])]);
        }
        if (MetadataFieldDefinition::where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => __('messages.metadata.key_taken', ['key' => $key])]);
        }
        return $key;
    }
}
