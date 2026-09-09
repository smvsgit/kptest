<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\MediaStatusHistory;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\MediaAccessService;
use App\Services\SearchIndexService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LifecycleWorkflowController extends Controller
{
    private const DEFAULT_TRANSITIONS = [
        'active' => ['draft', 'review', 'archived'],
        'draft' => ['review', 'archived'],
        'review' => ['draft', 'approved', 'archived'],
        'approved' => ['review', 'published', 'archived'],
        'published' => ['archived'],
        'archived' => ['draft', 'published'],
        'inactive' => ['draft'],
        'broken' => ['draft'],
    ];

    public function transition(Request $request, MediaFile $mediaFile, MediaAccessService $access, AuditService $audit, SearchIndexService $search)
    {
        abort_unless($access->canManageLifecycle($mediaFile, $request->user()), 403);
        $data = $request->validate([
            'status' => 'required|in:draft,review,approved,published,archived',
            'note' => 'nullable|string|max:1000',
        ]);

        $from = strtolower((string) ($mediaFile->asset_status ?: 'draft'));
        $to = $data['status'];
        $settings = SystemSetting::valueFor('lifecycle.settings', ['transitions' => self::DEFAULT_TRANSITIONS]);
        $transitions = is_array($settings['transitions'] ?? null) ? $settings['transitions'] : self::DEFAULT_TRANSITIONS;
        $allowed = is_array($transitions[$from] ?? null) ? $transitions[$from] : (self::DEFAULT_TRANSITIONS[$from] ?? []);

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Lifecycle transition from {$from} to {$to} is not allowed.",
            ]);
        }

        $this->validateMetadata($mediaFile, $to);

        $updates = ['asset_status' => $to];
        if ($to === 'archived') {
            $updates += [
                'archived_from_status' => $from,
                'archived_at' => now(),
                'archived_by' => $request->user()->id,
            ];
        } elseif ($from === 'archived') {
            $updates += [
                'archived_from_status' => null,
                'archived_at' => null,
                'archived_by' => null,
            ];
        }
        $mediaFile->forceFill($updates)->save();

        MediaStatusHistory::create([
            'media_file_id' => $mediaFile->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $data['note'] ?? null,
            'changed_by' => $request->user()->id,
        ]);
        $audit->log($request, 'media.lifecycle.transitioned', $mediaFile, 'Media lifecycle transitioned.', [
            'from' => $from,
            'to' => $to,
            'note' => $data['note'] ?? null,
        ]);
        $search->upsert($mediaFile->fresh(['category', 'subcategory', 'department']));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "Lifecycle status changed from {$from} to {$to}.",
                'asset_status' => $to,
            ]);
        }

        return back()->with('success', 'Lifecycle status updated.');
    }

    private function validateMetadata(MediaFile $media, string $to): void
    {
        if (! in_array($to, ['approved', 'published'], true)) {
            return;
        }

        $base = SystemSetting::valueFor('metadata.settings', [])['required_fields'] ?? [];
        $typed = SystemSetting::valueFor('metadata.type_required', [])[$media->type] ?? [];
        foreach (array_unique(array_merge($base, $typed)) as $field) {
            if (blank(data_get($media, $field))) {
                throw ValidationException::withMessages([
                    'status' => "Required metadata missing before {$to}: {$field}",
                ]);
            }
        }
    }
}
