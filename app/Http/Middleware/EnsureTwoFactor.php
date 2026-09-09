<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactor
{
    private const CHALLENGE_TTL_SECONDS = 43200;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $settings = SystemSetting::valueFor('security.two_factor', [
            'enabled' => true,
            'required_super_admin' => false,
        ]);

        if (! ($settings['enabled'] ?? true)) {
            return $next($request);
        }

        $confirmed = $user->two_factor_confirmed_at !== null;
        $mustEnroll = (bool) ($settings['required_super_admin'] ?? false)
            && $user->role === 'super-admin'
            && ! $confirmed;

        // A Super Admin that is required to enroll must still be able to reach
        // Profile, change a temporary password, enroll 2FA, and sign out.
        if ($mustEnroll) {
            if ($request->routeIs('profile', 'profile.*', 'security.2fa.setup', 'security.2fa.confirm', 'logout')) {
                return $next($request);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Two-factor authentication enrollment is required before continuing.',
                    'redirect' => route('profile'),
                ], 423);
            }

            return redirect()->route('profile')
                ->with('warning', 'Two-factor authentication enrollment is required before continuing.');
        }

        // Users who have not enabled 2FA and are not policy-required continue normally.
        if (! $confirmed) {
            return $next($request);
        }

        // The challenge endpoints and logout must never challenge themselves.
        if ($request->routeIs('security.2fa.challenge-page', 'security.2fa.challenge', 'logout')) {
            return $next($request);
        }

        $passedAt = (int) $request->session()->get('2fa_passed_at', 0);
        if ($passedAt >= time() - self::CHALLENGE_TTL_SECONDS) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Two-factor authentication challenge required.',
                'redirect' => route('security.2fa.challenge-page'),
            ], 423);
        }

        // Store the originally requested URL so a successful challenge can return there.
        return redirect()->guest(route('security.2fa.challenge-page'));
    }
}
