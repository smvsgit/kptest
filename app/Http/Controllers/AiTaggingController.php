<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\SystemSetting;
use App\Services\AiTaggingService;
use App\Services\AuditService;
use App\Services\MediaAccessService;
use Illuminate\Http\Request;

/**
 * On-demand tag suggestions. (Review F08.)
 *
 * Three things have to be true before a paid call is made: an administrator
 * configured and enabled it, the role carries the `ai_tagging` permission, and
 * this person may already describe this asset. Nothing is written here — the
 * suggestions go back for the person to accept or reject.
 */
class AiTaggingController extends Controller
{
    public function suggest(Request $request, MediaFile $mediaFile, AiTaggingService $tagging, MediaAccessService $access, AuditService $audit)
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('ai_tagging'), 403, __('messages.tagging.not_permitted'));
        abort_unless($access->canManageLifecycle($mediaFile, $user), 403);

        $result = $tagging->suggest($mediaFile);

        // Every call is recorded, successful or not: this one costs money, and
        // the owner asked for cost to be governed.
        $audit->log($request, 'media.ai-tags.suggested', $mediaFile, 'AI tag suggestions requested.', [
            'used' => $result['used'],
            'suggested' => count($result['tags']),
            'model' => $tagging->settings()['model'],
        ]);

        return response()->json([
            'used' => $result['used'],
            'tags' => $result['tags'],
            'message' => $result['used'] ? null : ($result['note'] ?: __('messages.tagging.unavailable')),
        ], $result['used'] ? 200 : 422);
    }

    public function update(Request $request, AiTaggingService $tagging, AuditService $audit)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'endpoint' => 'nullable|url|max:500',
            'model' => 'nullable|string|max:120',
            'api_key' => 'nullable|string|max:300',
            'timeout_seconds' => 'required|integer|min:5|max:60',
            'max_tags' => 'required|integer|min:1|max:25',
            'instructions' => 'nullable|string|max:2000',
        ]);

        $current = $tagging->settings();
        if (trim((string) ($data['api_key'] ?? '')) === '') {
            $data['api_key'] = $current['api_key'] ?? '';
        }
        if ($data['enabled'] && (trim((string) $data['endpoint']) === '' || trim((string) $data['model']) === '')) {
            return back()->withErrors(['endpoint' => __('messages.tagging.needs_endpoint')]);
        }

        SystemSetting::put(AiTaggingService::SETTING, $data);
        $audit->log($request, 'settings.tagging.ai.updated', null, 'AI tagging settings updated.', [
            'enabled' => $data['enabled'], 'endpoint' => $data['endpoint'], 'model' => $data['model'],
            'timeout_seconds' => $data['timeout_seconds'], 'max_tags' => $data['max_tags'],
        ]);

        return back()->with('success', __('messages.tagging.saved'));
    }
}
