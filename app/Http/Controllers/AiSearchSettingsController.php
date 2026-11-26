<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AiSearchService;
use App\Services\AuditService;
use Illuminate\Http\Request;

/**
 * The optional AI search mode, kept in its own setting and its own endpoint so
 * that saving it cannot disturb the native search configuration. (Review F07.)
 */
class AiSearchSettingsController extends Controller
{
    public function update(Request $request, AiSearchService $ai, AuditService $audit)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'endpoint' => 'nullable|url|max:500',
            'model' => 'nullable|string|max:120',
            // Left blank, the stored key is kept. There is no way to read it back.
            'api_key' => 'nullable|string|max:300',
            'timeout_seconds' => 'required|integer|min:1|max:30',
            'max_terms' => 'required|integer|min:1|max:20',
            'instructions' => 'nullable|string|max:2000',
        ]);

        $current = $ai->settings();
        if (!array_key_exists('api_key', $data) || trim((string) $data['api_key']) === '') {
            $data['api_key'] = $current['api_key'] ?? '';
        }
        if ($data['enabled'] && (trim((string) $data['endpoint']) === '' || trim((string) $data['model']) === '')) {
            return back()->withErrors(['endpoint' => __('messages.search.ai_needs_endpoint')]);
        }

        SystemSetting::put(AiSearchService::SETTING, $data);
        $audit->log($request, 'settings.search.ai.updated', null, 'Optional AI search settings updated.', [
            // The key never reaches the audit trail.
            'enabled' => $data['enabled'],
            'endpoint' => $data['endpoint'],
            'model' => $data['model'],
            'timeout_seconds' => $data['timeout_seconds'],
            'max_terms' => $data['max_terms'],
        ]);

        return back()->with('success', __('messages.search.ai_saved'));
    }

    /** Ask the configured provider for one expansion, and report what came back. */
    public function test(Request $request, AiSearchService $ai, AuditService $audit)
    {
        $data = $request->validate(['query' => 'required|string|max:200']);
        $result = $ai->expand($data['query']);
        $audit->log($request, 'settings.search.ai.tested', null, 'Optional AI search tested.', [
            'query' => $data['query'], 'used' => $result['used'], 'terms' => count($result['terms']),
        ]);

        return response()->json([
            'used' => $result['used'],
            'terms' => $result['terms'],
            'message' => $result['used']
                ? __('messages.search.ai_test_ok', ['terms' => implode(', ', $result['terms'])])
                : ($result['note'] ?: __('messages.search.ai_unavailable')),
        ]);
    }
}
