<?php

namespace App\Http\Middleware;

use App\Models\FontFamily;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $assignments = [
            'global' => 'hind-vadodara',
            'gujarati' => 'hind-vadodara',
            'hindi' => 'noto-sans-devanagari',
            'english' => 'inter',
        ];
        $selected = [];
        $hasSettings = Schema::hasTable('system_settings');

        if ($hasSettings && Schema::hasTable('font_families')) {
            $assignments = SystemSetting::valueFor('appearance.fonts', $assignments);
            $slugs = array_values(array_unique(array_filter($assignments)));
            $selected = FontFamily::with('files')->whereIn('slug', $slugs)->get();
        }

        $brandingDefaults = [
            'portal_name' => 'Karyalay Portal',
            'login_text' => 'Secure office media and document portal',
            'default_language' => 'en',
            'logo_path' => null,
        ];
        $branding = $hasSettings
            ? array_replace($brandingDefaults, SystemSetting::valueFor('branding.settings', $brandingDefaults))
            : $brandingDefaults;

        $portalName = trim((string) ($branding['portal_name'] ?? '')) ?: 'Karyalay Portal';
        $loginText = trim((string) ($branding['login_text'] ?? '')) ?: 'Secure office media and document portal';
        $defaultLanguage = in_array(($branding['default_language'] ?? 'en'), ['en', 'gu'], true)
            ? $branding['default_language']
            : 'en';
        $preferredLanguage = $request->user()?->preferred_language;
        $locale = in_array($preferredLanguage, ['en', 'gu'], true) ? $preferredLanguage : $defaultLanguage;
        app()->setLocale($locale);

        $logoPath = is_string($branding['logo_path'] ?? null) ? trim($branding['logo_path']) : '';
        $branding = [
            ...$branding,
            'portal_name' => $portalName,
            'login_text' => $loginText,
            'default_language' => $defaultLanguage,
            'logo_path' => $logoPath ?: null,
            'logo_url' => $logoPath ? '/storage/'.ltrim($logoPath, '/') : '/logo.svg',
        ];

        return [
            ...parent::share($request),
            'auth' => ['user' => $request->user()],
            'flash' => [
                'success' => fn () => session('success'),
                'warning' => fn () => session('warning'),
            ],
            'appearance' => [
                'assignments' => $assignments,
                'families' => $selected,
            ],
            'branding' => $branding,
            'locale' => $locale,
        ];
    }
}
