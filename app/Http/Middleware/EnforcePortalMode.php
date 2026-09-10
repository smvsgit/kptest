<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\NetworkPolicyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePortalMode
{
    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = SystemSetting::valueFor('maintenance.settings', ['enabled' => false]);
        $user = $request->user();

        if (($maintenance['enabled'] ?? false)
            && ! ($user && $user->role === 'super-admin' && ($maintenance['allow_super_admin_bypass'] ?? true))) {
            return response($maintenance['message'] ?? 'Maintenance in progress.', 503, ['Retry-After' => '600']);
        }

        // External Internet entitlements expire at request time; no scheduler is required.
        // Persist the expiry once and append an audit event so expiry is traceable without
        // producing a duplicate audit row on every later request.
        if ($user
            && $user->external_access_allowed
            && $user->external_access_expires_at
            && $user->external_access_expires_at->isPast()) {
            $before = $user->only([
                'external_access_allowed', 'external_access_starts_at', 'external_access_expires_at',
                'external_access_reason', 'external_access_approved_by',
            ]);
            $user->forceFill(['external_access_allowed' => false])->save();
            app(AuditService::class)->log(
                $request,
                'user.external-access.expired',
                $user,
                'External Internet access expired automatically.',
                [
                    'before' => $before,
                    'expired_at' => now()->toIso8601String(),
                    'configured_expiry' => optional($user->external_access_expires_at)->toIso8601String(),
                ],
            );
        }

        if ($user && ! app(NetworkPolicyService::class)->allows($request, $user)) {
            abort(403, 'Access is restricted to the approved internal network or VPN.');
        }

        return $next($request);
    }
}
