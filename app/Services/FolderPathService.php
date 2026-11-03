<?php

namespace App\Services;

use App\Models\MediaFolder;
use Illuminate\Support\Collection;

/**
 * Folder paths for the workspace.
 *
 * A folder upload arrives as a flat list of files, each carrying the relative
 * path the browser saw ("Annakut 2024/Photos/01.jpg"). This turns that path
 * into a real folder chain inside one department, reusing folders that already
 * exist rather than making a second "Photos" beside the first.
 */
class FolderPathService
{
    /** A path deeper than this is almost certainly a mistake, and would make the tree unusable. */
    public const MAX_DEPTH = 12;

    /**
     * Resolve a relative path to the folder that should hold the file,
     * creating any missing folders along the way.
     *
     * The final segment of the path is the file name and is not made into a
     * folder. Returns null for a file uploaded at the root.
     */
    public function resolveForUpload(string $relativePath, int $departmentId, int $userId, ?int $rootFolderId = null): ?int
    {
        $segments = $this->segments($relativePath);
        array_pop($segments); // the file itself
        if (!$segments) return $rootFolderId;

        $parentId = $rootFolderId;
        foreach (array_slice($segments, 0, self::MAX_DEPTH) as $segment) {
            $parentId = $this->findOrCreate($segment, $departmentId, $parentId, $userId)->id;
        }
        return $parentId;
    }

    /** Split a browser-supplied path into safe folder names. */
    public function segments(string $relativePath): array
    {
        $parts = preg_split('#[\\\\/]+#', str_replace("\0", '', $relativePath)) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $part = trim($part);
            // "." and ".." would let an upload climb out of its own folder.
            if ($part === '' || $part === '.' || $part === '..') continue;
            $clean[] = mb_substr($part, 0, 120);
        }
        return $clean;
    }

    public function findOrCreate(string $name, int $departmentId, ?int $parentId, int $userId): MediaFolder
    {
        $existing = MediaFolder::query()
            ->where('department_id', $departmentId)
            ->where('parent_id', $parentId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($existing) return $existing;

        return MediaFolder::create([
            'department_id' => $departmentId,
            'parent_id' => $parentId,
            'name' => $name,
            'is_active' => true,
            'created_by' => $userId,
        ]);
    }

    /** The folder's path from the root, as names ("Annakut 2024 / Photos"). */
    public function pathOf(MediaFolder $folder): string
    {
        $names = [];
        for ($node = $folder; $node; $node = $node->parent) {
            array_unshift($names, $node->name);
            if (count($names) > self::MAX_DEPTH) break;
        }
        return implode(' / ', $names);
    }

    /**
     * Ids of a folder and every folder beneath it. Used wherever an action is
     * applied "to this folder and everything in it".
     */
    public function descendantIds(MediaFolder $folder): array
    {
        $all = MediaFolder::query()
            ->where('department_id', $folder->department_id)
            ->get(['id', 'parent_id']);

        $byParent = $all->groupBy('parent_id');
        $ids = [$folder->id];
        $queue = [$folder->id];
        $guard = 0;

        while ($queue && $guard++ < 10000) {
            $current = array_shift($queue);
            foreach ($byParent->get($current, collect()) as $child) {
                $ids[] = (int) $child->id;
                $queue[] = (int) $child->id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * The whole department tree as a flat list the UI can render, each row
     * carrying its own direct file count.
     */
    public function tree(int $departmentId): Collection
    {
        $folders = MediaFolder::query()
            ->where('department_id', $departmentId)
            ->withCount('files')
            ->orderBy('parent_id')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'description', 'is_active', 'department_id']);

        $depthOf = [];
        $byId = $folders->keyBy('id');
        $depth = function ($folder) use (&$depth, &$depthOf, $byId) {
            if (isset($depthOf[$folder->id])) return $depthOf[$folder->id];
            $parent = $folder->parent_id ? $byId->get($folder->parent_id) : null;
            return $depthOf[$folder->id] = $parent ? $depth($parent) + 1 : 0;
        };

        return $folders->map(fn ($folder) => [
            'id' => (int) $folder->id,
            'parent_id' => $folder->parent_id ? (int) $folder->parent_id : null,
            'name' => $folder->name,
            'description' => $folder->description,
            'is_active' => (bool) $folder->is_active,
            'depth' => $depth($folder),
            'file_count' => (int) $folder->files_count,
        ])->values();
    }
}
