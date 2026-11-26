<?php

namespace App\Mcp;

use App\Models\Category;
use App\Models\MediaAccessRequest;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CustomMetadataService;
use App\Services\FolderPathService;
use App\Services\MediaAccessService;
use App\Services\MediaVersionService;
use App\Services\SearchIndexService;
use App\Services\UploadPolicyService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What the portal offers over MCP. (Review F06.)
 *
 * Each method here is one tool. None of them invents a rule: they call the
 * same services the screens call, and they refuse in the same places, so a
 * token cannot reach anything its owner could not reach by signing in. The
 * refusals are deliberately explicit rather than inherited, because a tool
 * call does not pass through route middleware.
 */
class PortalTools
{
    public function __construct(
        private Request $request,
        private MediaAccessService $access,
        private AuditService $audit,
        private FolderPathService $paths,
        private CustomMetadataService $custom,
        private MediaVersionService $versions,
        private SearchIndexService $searchIndex,
        private UploadPolicyService $uploadPolicy,
    ) {
    }

    private function user(): User
    {
        $user = $this->request->user();
        if (!$user) throw new RuntimeException('No user on this request.');
        return $user;
    }

    /** The same check `role:` middleware makes. */
    private function assertRole(array $roles): void
    {
        if (!in_array($this->user()->role, $roles, true)) {
            throw new RuntimeException(__('messages.access.role_denied'));
        }
    }

    /** The same check `permission:` middleware makes. */
    private function assertPermission(string $permission): void
    {
        if (!$this->user()->hasPermission($permission)) {
            throw new RuntimeException(__('messages.access.role_denied'));
        }
    }

    /** The department a caller works in, as the folder workspace decides it. */
    private function departmentFor(?int $requested): int
    {
        $user = $this->user();
        if ($user->role === 'super-admin') {
            $id = $requested ?: $user->department_id ?: \App\Models\Department::query()
                ->where('is_active', true)->orderBy('is_system')->orderBy('name')->value('id');
            if (!$id) throw new RuntimeException(__('messages.access.no_department_configured'));
            return (int) $id;
        }
        if ($requested && (int) $requested !== (int) $user->department_id) {
            throw new RuntimeException(__('messages.folder.own_department_only'));
        }
        if (!$user->department_id) throw new RuntimeException(__('messages.access.no_department'));
        return (int) $user->department_id;
    }

    private function folder(int $id): MediaFolder
    {
        $folder = MediaFolder::find($id);
        if (!$folder) throw new RuntimeException('No folder with that id.');
        $user = $this->user();
        if ($user->role !== 'super-admin' && (int) $folder->department_id !== (int) $user->department_id) {
            throw new RuntimeException(__('messages.folder.own_department_only'));
        }
        return $folder;
    }

    private function file(int $id): MediaFile
    {
        $file = MediaFile::find($id);
        if (!$file) throw new RuntimeException(__('messages.file.not_found'));
        if (!$this->access->canSeeMetadata($file, $this->user())) {
            throw new RuntimeException(__('messages.access.denied'));
        }
        return $file;
    }

    /** One asset as a tool result: what it is, where it is, and what may be done with it. */
    private function describeFile(MediaFile $file): array
    {
        $user = $this->user();
        $folder = $file->folder_id ? MediaFolder::find($file->folder_id) : null;

        return [
            'id' => (int) $file->id,
            'name' => $file->name,
            'type' => $file->type,
            'size' => (int) $file->size,
            'department' => $file->department?->name,
            'folder_id' => $file->folder_id ? (int) $file->folder_id : null,
            'path' => $folder ? $this->paths->pathOf($folder) : null,
            'category' => $file->category?->name,
            'subcategory' => $file->subcategory?->name,
            'year' => $file->year,
            'tags' => $file->tags ?? [],
            'custom_metadata' => $file->custom_metadata ?? [],
            'access_policy' => $file->access_policy,
            'asset_status' => $file->asset_status,
            'current_version' => (int) ($file->current_version ?: 1),
            'uploaded_at' => optional($file->created_at)->toIso8601String(),
            'uploaded_by' => $file->uploader?->name,
            'can_preview' => $this->access->canPreview($file, $user),
            'can_download' => $this->access->canDownload($file, $user),
        ];
    }

    // ------------------------------------------------ search, list, path lookup

    public function searchFiles(array $args): array
    {
        $user = $this->user();
        $query = MediaFile::with(['category', 'subcategory', 'department', 'uploader']);

        // The same visibility rule the library applies: outside Super Admin a
        // caller sees their own department, plus public and protected assets.
        if ($user->role !== 'super-admin') {
            $query->where(function ($q) use ($user) {
                $q->where('department_id', $user->department_id)
                    ->orWhereIn('access_policy', ['public', 'protected']);
            });
        }

        foreach (['type', 'access_policy', 'asset_status', 'year', 'category_id', 'subcategory_id',
                  'department_id', 'event_id', 'person_id', 'city_id', 'mandir_id', 'language_id'] as $field) {
            if (filled($args[$field] ?? null)) $query->where($field, $args[$field]);
        }
        if (filled($args['folder_id'] ?? null)) $query->where('folder_id', (int) $args['folder_id']);
        if (filled($args['query'] ?? null)) {
            $term = '%'.str_replace('%', '\%', trim((string) $args['query'])).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('description', 'like', $term));
        }

        $limit = max(1, min(100, (int) ($args['limit'] ?? 25)));
        $files = $query->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => $files->count(),
            'files' => $files->map(fn (MediaFile $f) => $this->describeFile($f))->all(),
        ];
    }

    public function listFolders(array $args): array
    {
        $department = $this->departmentFor(isset($args['department_id']) ? (int) $args['department_id'] : null);
        return [
            'department_id' => $department,
            'folders' => $this->paths->tree($department)->all(),
        ];
    }

    public function listFolderFiles(array $args): array
    {
        $folder = $this->folder((int) $args['folder_id']);
        $files = MediaFile::with(['category', 'department', 'uploader'])
            ->where('folder_id', $folder->id)->orderBy('name')->limit(500)->get();

        return [
            'folder' => ['id' => (int) $folder->id, 'name' => $folder->name, 'path' => $this->paths->pathOf($folder)],
            'count' => $files->count(),
            'files' => $files->map(fn (MediaFile $f) => $this->describeFile($f))->all(),
        ];
    }

    public function getFile(array $args): array
    {
        return $this->describeFile($this->file((int) $args['file_id'])->load(['category', 'subcategory', 'department', 'uploader']));
    }

    // -------------------------------------------------------------- upload

    public function uploadFile(array $args): array
    {
        $this->assertRole(['super-admin', 'department-admin', 'department-operator']);
        $this->assertPermission('upload');

        $name = trim((string) ($args['filename'] ?? ''));
        if ($name === '') throw new RuntimeException('A filename is required.');

        $bytes = base64_decode((string) ($args['content_base64'] ?? ''), true);
        if ($bytes === false || $bytes === '') throw new RuntimeException('content_base64 is not valid base64, or is empty.');
        if (strlen($bytes) > 25 * 1024 * 1024) {
            throw new RuntimeException('Send files larger than 25 MB through the portal\'s own resumable upload.');
        }

        $folder = isset($args['folder_id']) && $args['folder_id'] ? $this->folder((int) $args['folder_id']) : null;
        $department = $folder?->department_id ?? $this->departmentFor(null);

        $temp = tempnam(sys_get_temp_dir(), 'mcp_');
        file_put_contents($temp, $bytes);
        $upload = new UploadedFile($temp, $name, null, null, true);

        // The same policy the screen's upload obeys: extension, size, and that
        // an image is really an image.
        $this->uploadPolicy->validateUploadedFile($upload);

        $type = $this->typeFor(strtolower(pathinfo($name, PATHINFO_EXTENSION)));
        $path = $upload->store("uploads/{$type}s", 'media');

        $file = MediaFile::create([
            'name' => $name,
            'type' => $type,
            'size' => strlen($bytes),
            'department_id' => $department,
            'folder_id' => $folder?->id,
            'category_id' => $args['category_id'] ?? null,
            'access_policy' => in_array($args['access_policy'] ?? 'public', ['public', 'protected', 'private'], true)
                ? $args['access_policy'] ?? 'public' : 'public',
            'download_allowed' => true,
            'tags' => array_values(array_filter(array_map('trim', (array) ($args['tags'] ?? [])))),
            'asset_status' => 'draft',
            'file_path' => $path,
            'uploaded_by' => $this->user()->id,
            'owner_user_id' => $this->user()->id,
        ]);
        @unlink($temp);

        \App\Jobs\ProcessMediaFile::dispatch($file->id);
        $this->audit->log($this->request, 'media.uploaded', $file, 'Media uploaded over MCP.', [
            'name' => $file->name, 'size' => $file->size, 'folder_id' => $file->folder_id, 'via' => 'mcp',
        ]);
        $this->searchIndex->upsert($file->fresh(['category', 'subcategory', 'department']));

        return ['uploaded' => true, 'file' => $this->describeFile($file->fresh(['category', 'department', 'uploader']))];
    }

    private function typeFor(string $ext): string
    {
        if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v', '3gp'], true)) return 'video';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp', 'tiff', 'tif', 'ico', 'heic', 'heif', 'avif', 'raw'], true)) return 'image';
        if (in_array($ext, ['mp3', 'wav', 'aac', 'ogg', 'm4a', 'flac', 'wma', 'opus'], true)) return 'audio';
        return 'document';
    }

    // ---------------------------------------------------- metadata and tags

    public function updateFiles(array $args): array
    {
        $this->assertRole(['super-admin', 'department-admin', 'department-operator']);
        $this->assertPermission('manage_lifecycle');

        $ids = array_values(array_unique(array_map('intval', (array) ($args['file_ids'] ?? []))));
        if (!$ids) throw new RuntimeException('file_ids is required.');

        $user = $this->user();
        $files = MediaFile::whereIn('id', $ids)->get();
        if ($files->count() !== count($ids)) throw new RuntimeException(__('messages.file.not_found'));
        if ($user->role !== 'super-admin') {
            $own = $files->every(fn (MediaFile $f) => (int) $f->department_id === (int) $user->department_id);
            if (!$own) throw new RuntimeException(__('messages.file.bulk_own_department'));
        }

        $metadata = array_intersect_key((array) ($args['metadata'] ?? []), array_flip([
            'year', 'country_id', 'state_id', 'city_id', 'mandir_id', 'event_id', 'person_id',
            'language_id', 'media_type_id', 'description', 'internal_remarks', 'category_id', 'subcategory_id',
        ]));
        $tags = (array) ($args['tags'] ?? []);
        $tagsMode = in_array($args['tags_mode'] ?? 'add', ['add', 'remove', 'replace'], true) ? ($args['tags_mode'] ?? 'add') : 'add';
        $customIn = (array) ($args['custom_metadata'] ?? []);

        if (!$metadata && !$tags && !$customIn) throw new RuntimeException(__('messages.folder.nothing_to_apply'));

        $updated = [];
        foreach ($files as $file) {
            $apply = $metadata;
            if ($customIn) {
                $clean = $this->custom->validate($customIn, $file);
                if ($clean) $apply['custom_metadata'] = $this->custom->merge($file, $clean);
            }
            if ($tags) {
                $incoming = array_values(array_unique(array_filter(array_map('trim', $tags))));
                $have = $file->tags ?? [];
                $apply['tags'] = match ($tagsMode) {
                    'replace' => $incoming,
                    'remove' => array_values(array_diff($have, $incoming)),
                    default => array_values(array_unique(array_merge($have, $incoming))),
                };
            }
            if (!$apply) continue;

            $before = $file->only(array_keys($apply));
            $file->fill($apply)->save();
            $this->audit->log($this->request, 'media.bulk.updated', $file, 'Metadata updated over MCP.', [
                'before' => $before, 'after' => $apply, 'via' => 'mcp',
            ]);
            $this->searchIndex->upsert($file->fresh(['category', 'subcategory', 'department']));
            $updated[] = (int) $file->id;
        }

        return ['updated' => count($updated), 'file_ids' => $updated];
    }

    // --------------------------------------------------------- folder actions

    public function createFolder(array $args): array
    {
        $this->assertRole(['super-admin', 'department-admin']);
        $parent = isset($args['parent_id']) && $args['parent_id'] ? $this->folder((int) $args['parent_id']) : null;
        $department = $parent?->department_id ?? $this->departmentFor(isset($args['department_id']) ? (int) $args['department_id'] : null);

        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') throw new RuntimeException('A folder name is required.');

        $folder = MediaFolder::create([
            'department_id' => $department,
            'parent_id' => $parent?->id,
            'name' => $name,
            'description' => $args['description'] ?? null,
            'is_active' => true,
            'created_by' => $this->user()->id,
        ]);
        $this->audit->log($this->request, 'folder.created', $folder, 'Folder created over MCP.', ['via' => 'mcp']);

        return ['folder' => ['id' => (int) $folder->id, 'name' => $folder->name, 'path' => $this->paths->pathOf($folder)]];
    }

    public function renameFolder(array $args): array
    {
        $this->assertRole(['super-admin', 'department-admin']);
        $folder = $this->folder((int) $args['folder_id']);
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') throw new RuntimeException('A folder name is required.');

        $before = $folder->only(['name', 'description']);
        $folder->update(['name' => $name, 'description' => $args['description'] ?? $folder->description]);
        $this->audit->log($this->request, 'folder.updated', $folder, 'Folder renamed over MCP.', ['before' => $before, 'via' => 'mcp']);

        return ['folder' => ['id' => (int) $folder->id, 'name' => $folder->name, 'path' => $this->paths->pathOf($folder)]];
    }

    public function deleteFolder(array $args): array
    {
        $this->assertRole(['super-admin', 'department-admin']);
        $folder = $this->folder((int) $args['folder_id']);

        $files = $folder->files()->count();
        $children = $folder->children()->count();
        if ($files || $children) {
            throw new RuntimeException(__('messages.folder.not_empty_detail', ['files' => $files, 'folders' => $children]));
        }

        $this->audit->log($this->request, 'folder.deleted', $folder, 'Folder deleted over MCP.', ['via' => 'mcp']);
        $folder->delete();

        return ['deleted' => true];
    }

    public function moveFiles(array $args): array
    {
        $this->assertRole(['super-admin', 'department-admin', 'department-operator']);
        $ids = array_values(array_unique(array_map('intval', (array) ($args['file_ids'] ?? []))));
        if (!$ids) throw new RuntimeException('file_ids is required.');

        $files = MediaFile::whereIn('id', $ids)->get();
        if ($files->count() !== count($ids)) throw new RuntimeException(__('messages.file.not_found'));

        $target = isset($args['folder_id']) && $args['folder_id'] ? $this->folder((int) $args['folder_id']) : null;
        $departments = $files->pluck('department_id')->unique();
        if ($departments->count() > 1) throw new RuntimeException(__('messages.folder.one_department'));
        if ($target && (int) $target->department_id !== (int) $departments->first()) {
            throw new RuntimeException(__('messages.folder.destination_department'));
        }
        $user = $this->user();
        if ($user->role !== 'super-admin' && (int) $user->department_id !== (int) $departments->first()) {
            throw new RuntimeException(__('messages.folder.own_department_only'));
        }

        foreach ($files as $file) {
            $file->forceFill(['folder_id' => $target?->id])->save();
            $this->audit->log($this->request, 'media.folder.moved', $file, 'Asset moved over MCP.', [
                'folder_id' => $target?->id, 'via' => 'mcp',
            ]);
        }

        return ['moved' => $files->count(), 'folder_id' => $target?->id];
    }

    // ----------------------------------------------------- version replacement

    public function listVersions(array $args): array
    {
        $file = $this->file((int) $args['file_id']);
        if (!$this->access->canManageLifecycle($file, $this->user())) {
            throw new RuntimeException(__('messages.access.denied'));
        }

        return [
            'current_version' => (int) ($file->current_version ?: 1),
            'versions' => $file->versions()->with('changedBy:id,name')->get()->map(fn ($v) => [
                'id' => (int) $v->id,
                'version_number' => (int) $v->version_number,
                'original_name' => $v->original_name,
                'size' => (int) $v->size,
                'change_note' => $v->change_note,
                'changed_by' => $v->changedBy?->name,
                'created_at' => optional($v->created_at)->toIso8601String(),
                'is_current' => (int) $v->version_number === (int) $file->current_version,
            ])->all(),
        ];
    }

    public function addVersion(array $args): array
    {
        $file = $this->file((int) $args['file_id']);
        if (!$this->access->canUploadVersion($file, $this->user())) {
            throw new RuntimeException(__('messages.access.denied'));
        }
        $note = trim((string) ($args['change_note'] ?? ''));
        if (mb_strlen($note) < 3) throw new RuntimeException('A change note of at least three characters is required.');

        $bytes = base64_decode((string) ($args['content_base64'] ?? ''), true);
        if ($bytes === false || $bytes === '') throw new RuntimeException('content_base64 is not valid base64, or is empty.');

        $name = trim((string) ($args['filename'] ?? $file->name));
        if ($this->typeFor(strtolower(pathinfo($name, PATHINFO_EXTENSION))) !== $file->type) {
            throw new RuntimeException(__('messages.file.version_type_mismatch'));
        }

        $temp = tempnam(sys_get_temp_dir(), 'mcp_');
        file_put_contents($temp, $bytes);
        $upload = new UploadedFile($temp, $name, null, null, true);
        $this->uploadPolicy->validateUploadedFile($upload);

        $version = $this->versions->replaceWithUpload($file, $upload, $this->user(), $note);
        @unlink($temp);

        $this->audit->log($this->request, 'media.version.created', $file, 'New media version uploaded over MCP.', [
            'version_number' => $version->version_number, 'change_note' => $note, 'via' => 'mcp',
        ]);
        $this->searchIndex->upsert($file->fresh(['category', 'subcategory', 'department']));

        return ['version_number' => (int) $version->version_number, 'file' => $this->describeFile($file->fresh(['category', 'department', 'uploader']))];
    }

    public function restoreVersion(array $args): array
    {
        $file = $this->file((int) $args['file_id']);
        if (!$this->access->canRestoreVersion($file, $this->user())) {
            throw new RuntimeException(__('messages.access.denied'));
        }
        $version = $file->versions()->whereKey((int) $args['version_id'])->first();
        if (!$version) throw new RuntimeException('No such version on this asset.');

        $new = $this->versions->restoreVersion($file, $version, $this->user(), $args['change_note'] ?? null);
        $this->audit->log($this->request, 'media.version.restored', $file, 'Version restored over MCP.', [
            'restored_from_version' => $version->version_number, 'new_version' => $new->version_number, 'via' => 'mcp',
        ]);
        $this->searchIndex->upsert($file->fresh(['category', 'subcategory', 'department']));

        return ['version_number' => (int) $new->version_number];
    }

    // ------------------------------------------------- access and approvals

    public function requestAccess(array $args): array
    {
        $file = $this->file((int) $args['file_id']);
        $user = $this->user();
        if (!$this->access->canRequest($file, $user)) {
            throw new RuntimeException('This asset is not one you can request access to.');
        }
        $reason = trim((string) ($args['reason'] ?? ''));
        if (mb_strlen($reason) < 5) throw new RuntimeException('A reason of at least five characters is required.');
        $level = in_array($args['level'] ?? 'view', ['view', 'download'], true) ? $args['level'] ?? 'view' : 'view';

        $request = MediaAccessRequest::create([
            'media_file_id' => $file->id,
            'user_id' => $user->id,
            'access_level' => $level,
            'reason' => $reason,
            'status' => 'pending',
        ]);
        $this->audit->log($this->request, 'access.requested', $file, 'Access requested over MCP.', [
            'level' => $level, 'via' => 'mcp',
        ]);

        return ['request_id' => (int) $request->id, 'status' => 'pending'];
    }

    public function decideAccessRequest(array $args): array
    {
        $this->assertPermission('review_access');
        $request = MediaAccessRequest::with('mediaFile')->find((int) $args['request_id']);
        if (!$request) throw new RuntimeException('No such access request.');
        if (!$this->access->canReviewRequest($request, $this->user())) {
            throw new RuntimeException(__('messages.access.denied'));
        }

        $status = $args['status'] ?? '';
        if (!in_array($status, ['approved', 'rejected', 'more-info'], true)) {
            throw new RuntimeException('status must be approved, rejected or more-info.');
        }

        $request->forceFill([
            'status' => $status,
            'decision_note' => $args['note'] ?? null,
            'decided_by' => $this->user()->id,
            'decided_at' => now(),
        ])->save();

        $this->audit->log($this->request, 'access.'.$status, $request->mediaFile, 'Access request decided over MCP.', [
            'status' => $status, 'via' => 'mcp',
        ]);

        return ['request_id' => (int) $request->id, 'status' => $status];
    }
}
