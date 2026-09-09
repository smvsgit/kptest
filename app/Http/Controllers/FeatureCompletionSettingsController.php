<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\NetworkPolicyService;
use App\Services\UserGuideService;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FeatureCompletionSettingsController extends Controller
{
    public function update(Request $request, AuditService $audit, NetworkPolicyService $network, UserGuideService $userGuide)
    {
        $data = $request->validate([
            'section' => 'required|in:maintenance,branding,network,two_factor,watermark,lifecycle,type_required,audit_retention,recycle_retention,storage_quotas,user_guide',
            'settings' => 'required|array',
        ]);
        $map = [
            'maintenance' => 'maintenance.settings',
            'branding' => 'branding.settings',
            'network' => 'security.network',
            'two_factor' => 'security.two_factor',
            'watermark' => 'watermark.settings',
            'lifecycle' => 'lifecycle.settings',
            'type_required' => 'metadata.type_required',
            'audit_retention' => 'audit.retention',
            'recycle_retention' => 'recycle.retention',
            'storage_quotas' => 'storage.quotas',
            'user_guide' => 'user_guide.access',
        ];
        $key = $map[$data['section']];
        $before = SystemSetting::valueFor($key, []);
        $incoming = $data['settings'];

        if ($data['section'] === 'branding') {
            $validated = $request->validate([
                'settings.portal_name' => 'sometimes|required|string|max:120',
                'settings.login_text' => 'sometimes|required|string|max:500',
                'settings.default_language' => ['sometimes', 'required', Rule::in(['en', 'gu'])],
            ]);
            $incoming = $validated['settings'];
            unset($incoming['logo_path'], $incoming['logo_url']);
        }

        if ($data['section'] === 'user_guide') {
            $request->validate([
                'settings.enabled' => 'required|boolean',
                'settings.role_pages' => 'required|array',
                'settings.role_pages.department-admin' => 'required|integer|min:0|max:'.UserGuideService::TOTAL_PAGES,
                'settings.role_pages.department-operator' => 'required|integer|min:0|max:'.UserGuideService::TOTAL_PAGES,
                'settings.role_pages.viewer' => 'required|integer|min:0|max:'.UserGuideService::TOTAL_PAGES,
                'settings.department_pages' => 'present|array|max:500',
                'settings.department_pages.*' => 'integer|min:0|max:'.UserGuideService::TOTAL_PAGES,
                'settings.user_pages' => 'present|array|max:5000',
                'settings.user_pages.*' => 'integer|min:0|max:'.UserGuideService::TOTAL_PAGES,
            ]);
            $incoming = $userGuide->normalizeSettings($incoming);
            $departmentIds = array_map('intval', array_keys($incoming['department_pages']));
            $userIds = array_map('intval', array_keys($incoming['user_pages']));
            if ($departmentIds && Department::whereIn('id', $departmentIds)->count() !== count(array_unique($departmentIds))) {
                abort(422, 'One or more User Guide department overrides reference an invalid department.');
            }
            if ($userIds && User::whereIn('id', $userIds)->count() !== count(array_unique($userIds))) {
                abort(422, 'One or more User Guide user overrides reference an invalid user.');
            }
        }

        // User Guide access is a complete policy object. Replacing it (instead of recursive merge)
        // lets Super Admin intentionally remove old department/user overrides from the UI.
        $settings = $data['section'] === 'user_guide' ? $incoming : array_replace_recursive($before, $incoming);
        if ($data['section'] === 'network' && ($settings['enabled'] ?? false)) {
            SystemSetting::put($key, $settings);
            if (! $network->allows($request, $request->user())) {
                SystemSetting::put($key, $before);
                abort(422, 'Network policy would lock out the current Super Admin. Add this IP/CIDR or configure the emergency Super Admin bypass first.');
            }
        } else {
            SystemSetting::put($key, $settings);
        }

        $audit->log($request, 'settings.feature-completion.updated', null, 'Remaining-feature setting updated.', [
            'section' => $data['section'],
            'before' => $before,
            'after' => $settings,
        ]);

        return back()->with('success', 'Settings saved.');
    }

    public function logo(Request $request, AuditService $audit)
    {
        $request->validate(['logo' => 'required|file|mimes:png,jpg,jpeg,webp|max:2048']);
        $path = $request->file('logo')->store('branding', 'public');
        $settings = SystemSetting::valueFor('branding.settings', []);
        if (! empty($settings['logo_path'])) {
            Storage::disk('public')->delete($settings['logo_path']);
        }
        $settings['logo_path'] = $path;
        SystemSetting::put('branding.settings', $settings);
        $audit->log($request, 'branding.logo.updated', null, 'Portal logo updated.');
        return back()->with('success', 'Logo updated.');
    }

    public function resetLogo(Request $request, AuditService $audit)
    {
        $settings = SystemSetting::valueFor('branding.settings', []);
        if (! empty($settings['logo_path'])) {
            Storage::disk('public')->delete($settings['logo_path']);
        }
        $settings['logo_path'] = null;
        SystemSetting::put('branding.settings', $settings);
        $audit->log($request, 'branding.logo.reset', null, 'Portal logo reset.');
        return back()->with('success', 'Logo reset.');
    }
}
