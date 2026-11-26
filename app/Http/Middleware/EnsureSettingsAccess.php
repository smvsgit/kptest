<?php

namespace App\Http\Middleware;

use App\Support\SettingsSections;
use Closure;
use Illuminate\Http\Request;

/**
 * Guard one section of the Settings console.
 *
 *   ->middleware('settings:metadata')         // needs edit on metadata
 *   ->middleware('settings:metadata,view')    // reading is enough
 *   ->middleware('settings:completion|guide') // one endpoint serves two
 *                                             // sections; either grant opens it
 *
 * The review asked for the rule to hold on the server as well as in the
 * screen, so this runs on the route and not only on what the UI chooses to
 * draw. (Review F05.)
 */
class EnsureSettingsAccess
{
    public function handle(Request $request, Closure $next, string $sections, string $level = SettingsSections::EDIT): mixed
    {
        $user = $request->user();
        if (!$user || ($user->status ?? 'active') !== 'active') {
            abort(403, __('messages.access.role_denied'));
        }

        foreach (explode('|', $sections) as $section) {
            $section = trim($section);
            if ($section === '') continue;
            if ($level === SettingsSections::VIEW ? $user->canViewSettingsSection($section) : $user->canEditSettingsSection($section)) {
                return $next($request);
            }
        }

        abort(403, __('messages.access.settings_section_denied'));
    }
}
