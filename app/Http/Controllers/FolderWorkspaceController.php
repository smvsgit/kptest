<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Services\AuditService;
use App\Services\FolderPathService;
use App\Services\LocationHierarchyService;
use App\Services\SearchIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The folder workspace: a Windows / Drive style tree over the assets a
 * department already owns.
 *
 * Folder creation, rename and delete already lived in ContentOrganizationController
 * as Inertia form posts. This controller adds what a file-manager screen needs
 * on top of that — reading the tree, moving assets between folders, and
 * applying metadata, policy or lifecycle to a whole folder at once — all as
 * JSON, so the tree can update without a full page round trip.
 */
class FolderWorkspaceController extends Controller
{
    /** Metadata fields a bulk or folder-wide edit may write. */
    private const METADATA_FIELDS = [
        'year', 'country_id', 'state_id', 'city_id', 'mandir_id',
        'event_id', 'person_id', 'language_id', 'media_type_id',
        'description', 'internal_remarks', 'category_id', 'subcategory_id',
    ];

    private function departmentFor(Request $request, ?int $requested = null): int
    {
        $user = $request->user();
        if ($user->role === 'super-admin') {
            // A Super Admin usually belongs to no department, so the workspace
            // opens on one and offers the rest rather than refusing to load.
            return (int) ($requested ?: $user->department_id ?: $this->firstBrowsableDepartment());
        }
        return (int) ($user->department_id ?: abort(422, __('messages.access.no_department')));
    }

    private function firstBrowsableDepartment(): int
    {
        $id = \App\Models\Department::query()
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN is_system THEN 1 ELSE 0 END')
            ->orderBy('name')
            ->value('id');
        return (int) ($id ?: abort(422, __('messages.access.no_department_configured')));
    }

    /** Departments the signed-in user may switch between in the workspace. */
    private function switchableDepartments(Request $request): array
    {
        if ($request->user()->role !== 'super-admin') return [];
        return \App\Models\Department::query()
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN is_system THEN 1 ELSE 0 END')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();
    }

    /** A folder is only reachable by the department that owns it. */
    private function assertReachable(Request $request, MediaFolder $folder): void
    {
        $user = $request->user();
        abort_unless(
            $user->role === 'super-admin' || (int) $folder->department_id === (int) $user->department_id,
            403,
            'This folder belongs to another department.'
        );
    }

    private function assertCanOrganise(Request $request): void
    {
        abort_unless(
            in_array($request->user()->role, ['super-admin', 'department-admin', 'department-operator'], true),
            403,
            'Your role cannot reorganise the library.'
        );
    }

    /** The folder tree, plus the files sitting directly in one folder. */
    public function index(Request $request, FolderPathService $paths)
    {
        $department = $this->departmentFor($request, $request->integer('department_id') ?: null);
        $folderId = $request->integer('folder_id') ?: null;

        $current = null;
        if ($folderId) {
            $folder = MediaFolder::findOrFail($folderId);
            $this->assertReachable($request, $folder);
            $current = [
                'id' => (int) $folder->id,
                'name' => $folder->name,
                'path' => $paths->pathOf($folder),
                'parent_id' => $folder->parent_id ? (int) $folder->parent_id : null,
                'description' => $folder->description,
            ];
        }

        $files = MediaFile::query()
            ->where('department_id', $department)
            ->when($folderId, fn ($q) => $q->where('folder_id', $folderId), fn ($q) => $q->whereNull('folder_id'))
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'type', 'size', 'asset_status', 'access_policy', 'folder_id', 'created_at'])
            ->map(fn (MediaFile $file) => [
                'id' => (int) $file->id,
                'name' => $file->name,
                'type' => $file->type,
                'size' => (int) $file->size,
                'asset_status' => $file->asset_status,
                'access_policy' => $file->access_policy,
                // An asset uploaded without metadata is a draft until it is described.
                'needs_metadata' => $file->asset_status === 'draft',
                'created_at' => optional($file->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'department_id' => $department,
            'departments' => $this->switchableDepartments($request),
            'folders' => $paths->tree($department),
            'current' => $current,
            'files' => $files,
        ]);
    }

    /** Move assets into a folder, or back to the root when folder_id is null. */
    public function moveFiles(Request $request, AuditService $audit, SearchIndexService $searchIndex)
    {
        $this->assertCanOrganise($request);
        $data = $request->validate([
            'media_ids' => 'required|array|min:1|max:500',
            'media_ids.*' => 'integer|distinct',
            'folder_id' => 'nullable|integer|exists:media_folders,id',
        ]);

        $target = null;
        if (!empty($data['folder_id'])) {
            $target = MediaFolder::findOrFail($data['folder_id']);
            $this->assertReachable($request, $target);
        }

        $files = MediaFile::query()->whereIn('id', $data['media_ids'])->get();
        abort_unless($files->count() === count($data['media_ids']), 404, 'One or more assets were not found.');
        $this->assertSameDepartment($request, $files, $target);

        DB::transaction(function () use ($files, $target, $request, $audit) {
            foreach ($files as $file) {
                $from = $file->folder_id;
                if ((int) $from === (int) ($target->id ?? 0)) continue;
                $file->folder_id = $target?->id;
                $file->save();
                $audit->log($request, 'media.folder.moved', $file, 'Asset moved between folders.', [
                    'from_folder_id' => $from,
                    'to_folder_id' => $target?->id,
                ]);
            }
        });

        $files->each(fn (MediaFile $file) => $searchIndex->upsert($file->fresh(['category', 'subcategory', 'department'])));

        return response()->json([
            'message' => $files->count().' asset(s) moved to '.($target?->name ?? 'the library root').'.',
            'folder_id' => $target?->id,
        ]);
    }

    /**
     * Apply metadata to every asset in a folder.
     *
     * This is the other half of uploading without being asked for metadata:
     * describe the folder once and every file inside takes those values. Only
     * the fields sent are written, so a later pass can fill one more field
     * without clearing the rest.
     */
    public function applyMetadata(Request $request, MediaFolder $folder, AuditService $audit, SearchIndexService $searchIndex, FolderPathService $paths)
    {
        $this->assertCanOrganise($request);
        $this->assertReachable($request, $folder);

        $data = $request->validate([
            'metadata' => 'required|array',
            'metadata.year' => 'nullable|integer|min:1800|max:2500',
            'metadata.country_id' => ['nullable', Rule::exists('master_data_values', 'id')->where('type', 'country')],
            'metadata.state_id' => ['nullable', Rule::exists('master_data_values', 'id')->where('type', 'state')],
            'metadata.city_id' => ['nullable', Rule::exists('master_data_values', 'id')->where('type', 'city')],
            'metadata.mandir_id' => ['nullable', Rule::exists('master_data_values', 'id')->where('type', 'mandir')],
            'metadata.event_id' => ['nullable', Rule::exists('master_data_values', 'id')->where('type', 'event')],
            'metadata.person_id' => ['nullable', Rule::exists('master_data_values', 'id')->where('type', 'person')],
            'metadata.language_id' => ['nullable', Rule::exists('master_data_values', 'id')->where('type', 'language')],
            'metadata.media_type_id' => ['nullable', Rule::exists('master_data_values', 'id')->where('type', 'media_type')],
            'metadata.category_id' => 'nullable|exists:categories,id',
            'metadata.subcategory_id' => 'nullable|exists:subcategories,id',
            'metadata.description' => 'nullable|string|max:5000',
            'metadata.internal_remarks' => 'nullable|string|max:5000',
            'tags' => 'nullable|array|max:100',
            'tags.*' => 'string|max:80',
            'tags_mode' => 'nullable|in:add,replace',
            'include_subfolders' => 'nullable|boolean',
            // Only fill the gaps, leaving values a file already carries alone.
            'only_empty' => 'nullable|boolean',
        ]);

        $updates = array_intersect_key($data['metadata'], array_flip(self::METADATA_FIELDS));
        abort_if(!$updates && empty($data['tags']), 422, 'Nothing to apply.');

        $location = app(LocationHierarchyService::class);
        if ($location->touches($updates)) $location->assertValid($updates);

        $folderIds = $request->boolean('include_subfolders', true)
            ? $paths->descendantIds($folder)
            : [$folder->id];

        $onlyEmpty = $request->boolean('only_empty');
        $touched = [];

        DB::transaction(function () use ($folderIds, $updates, $data, $onlyEmpty, &$touched, $request, $audit) {
            MediaFile::query()->whereIn('folder_id', $folderIds)->chunkById(200, function ($chunk) use ($updates, $data, $onlyEmpty, &$touched, $request, $audit) {
                foreach ($chunk as $file) {
                    $apply = $onlyEmpty
                        ? array_filter($updates, fn ($value, $field) => blank($file->{$field}) && filled($value), ARRAY_FILTER_USE_BOTH)
                        : $updates;

                    if (!empty($data['tags'])) {
                        $incoming = array_values(array_unique(array_filter(array_map('trim', $data['tags']))));
                        $apply['tags'] = ($data['tags_mode'] ?? 'add') === 'replace'
                            ? $incoming
                            : array_values(array_unique(array_merge($file->tags ?? [], $incoming)));
                    }
                    if (!$apply) continue;

                    $before = $file->only(array_keys($apply));
                    $file->fill($apply)->save();
                    $audit->log($request, 'media.folder.metadata-applied', $file, 'Metadata applied from the folder.', [
                        'before' => $before,
                        'after' => $apply,
                    ]);
                    $touched[] = (int) $file->id;
                }
            });
        });

        if ($touched) {
            MediaFile::with(['category', 'subcategory', 'department'])->whereIn('id', $touched)->get()
                ->each(fn (MediaFile $file) => $searchIndex->upsert($file));
        }

        return response()->json([
            'message' => count($touched).' asset(s) updated in '.$folder->name.'.',
            'updated_ids' => $touched,
        ]);
    }

    /**
     * Publish a folder: set the access policy, download rule and lifecycle
     * status of everything inside it in one action.
     *
     * An asset is only moved to "active" once it carries the metadata the
     * administrator made mandatory, so folder publishing cannot be used to
     * push undescribed assets into the library.
     */
    public function publish(Request $request, MediaFolder $folder, AuditService $audit, SearchIndexService $searchIndex, FolderPathService $paths)
    {
        abort_unless(in_array($request->user()->role, ['super-admin', 'department-admin'], true), 403, 'Only Super Admin or Department Admin can publish a folder.');
        $this->assertReachable($request, $folder);

        $data = $request->validate([
            'access_policy' => 'nullable|in:public,protected,private',
            'download_allowed' => 'nullable|boolean',
            'asset_status' => 'nullable|in:draft,active',
            'visible_role_ids' => 'nullable|array|max:20',
            'visible_role_ids.*' => ['integer', Rule::exists('portal_roles', 'id')->where('is_active', true)],
            'include_subfolders' => 'nullable|boolean',
        ]);
        abort_if(!$data, 422, 'Nothing to publish.');

        $folderIds = $request->boolean('include_subfolders', true) ? $paths->descendantIds($folder) : [$folder->id];
        $metadata = app(\App\Services\MetadataRequirementService::class);

        $published = [];
        $skipped = [];

        DB::transaction(function () use ($folderIds, $data, $request, $audit, $metadata, &$published, &$skipped) {
            MediaFile::query()->whereIn('folder_id', $folderIds)->chunkById(200, function ($chunk) use ($data, $request, $audit, $metadata, &$published, &$skipped) {
                foreach ($chunk as $file) {
                    $updates = array_intersect_key($data, array_flip(['access_policy', 'download_allowed', 'asset_status']));

                    if (($updates['asset_status'] ?? null) === 'active') {
                        $missing = $metadata->missingFor($file);
                        if ($missing) {
                            $skipped[] = ['id' => (int) $file->id, 'name' => $file->name, 'missing' => $missing];
                            unset($updates['asset_status']);
                        }
                    }

                    if (array_key_exists('visible_role_ids', $data)) {
                        $roleIds = array_values(array_unique(array_map('intval', (array) $data['visible_role_ids'])));
                        $file->visibleToRoles()->sync(array_fill_keys($roleIds, ['granted_by' => $request->user()->id]));
                    }

                    if ($updates) {
                        $before = $file->only(array_keys($updates));
                        $file->fill($updates)->save();
                        $audit->log($request, 'media.folder.published', $file, 'Folder publishing applied.', ['before' => $before, 'after' => $updates]);
                    }
                    $published[] = (int) $file->id;
                }
            });
        });

        if ($published) {
            MediaFile::with(['category', 'subcategory', 'department'])->whereIn('id', $published)->get()
                ->each(fn (MediaFile $file) => $searchIndex->upsert($file));
        }

        $message = count($published).' asset(s) updated in '.$folder->name.'.';
        if ($skipped) $message .= ' '.count($skipped).' could not be activated because required metadata is missing.';

        return response()->json([
            'message' => $message,
            'updated_ids' => $published,
            'blocked' => $skipped,
        ]);
    }

    /** Every asset a move touches, and its destination, must sit in one department. */
    private function assertSameDepartment(Request $request, $files, ?MediaFolder $target): void
    {
        $user = $request->user();
        $departments = $files->pluck('department_id')->unique();
        abort_if($departments->count() > 1, 422, 'Assets from more than one department cannot be moved together.');

        $department = (int) $departments->first();
        if ($target) abort_unless((int) $target->department_id === $department, 422, 'The destination folder is in another department.');
        if ($user->role !== 'super-admin') {
            abort_unless((int) $user->department_id === $department, 403, 'You can only organise your own department.');
        }
    }
}
