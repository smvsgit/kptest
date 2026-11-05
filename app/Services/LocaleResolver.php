<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Which language this request speaks.
 *
 * Until now the locale was a side effect of building Inertia's shared props,
 * which was fine while only the React catalogue needed it. Backend messages
 * need it too, and those come from plain JSON endpoints and from middleware,
 * where no Inertia response is ever rendered. So the decision lives here, a
 * middleware applies it early, and the Inertia layer reads the result.
 *
 * Registered as a singleton, so the branding row is read once per request no
 * matter how many callers ask.
 */
class LocaleResolver
{
    public const SUPPORTED = ['en', 'gu'];

    public const FALLBACK = 'en';

    /** Where a visitor's choice on the sign-in screen is kept. */
    public const COOKIE = 'portal_locale';

    /** @var array<string,mixed>|null */
    private ?array $branding = null;

    /** @return array<string,mixed> */
    public function branding(): array
    {
        if ($this->branding !== null) {
            return $this->branding;
        }

        $defaults = [
            'portal_name' => 'Karyalay Portal',
            'login_text' => 'Secure office media and document portal',
            'default_language' => self::FALLBACK,
            'logo_path' => null,
        ];

        // The settings table is absent during the very first migration run, so
        // branding has to survive not being there.
        $this->branding = Schema::hasTable('system_settings')
            ? array_replace($defaults, SystemSetting::valueFor('branding.settings', $defaults))
            : $defaults;

        return $this->branding;
    }

    /** The portal-wide default, which guests and unset users get. */
    public function defaultLanguage(): string
    {
        $value = $this->branding()['default_language'] ?? self::FALLBACK;

        return in_array($value, self::SUPPORTED, true) ? $value : self::FALLBACK;
    }

    /**
     * A signed-in account's own preference wins. Failing that, the language a
     * visitor picked on the sign-in screen, which rides in a cookie because
     * there is no account to hang it on. Failing that, the portal default.
     */
    public function resolve(?User $user, ?Request $request = null): string
    {
        $preference = $user?->preferred_language;
        if (in_array($preference, self::SUPPORTED, true)) {
            return $preference;
        }

        $chosen = $request?->cookie(self::COOKIE);
        if (is_string($chosen) && in_array($chosen, self::SUPPORTED, true)) {
            return $chosen;
        }

        return $this->defaultLanguage();
    }
}
