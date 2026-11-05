<?php

namespace App\Http\Controllers;

use App\Services\LocaleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The language a visitor picks before signing in.
 *
 * A signed-in account keeps its own preference on the profile; this is for the
 * sign-in, password and two-factor screens, where there is no account yet. The
 * choice rides in a long-lived cookie so a returning visitor lands in the same
 * language, and so it survives the session expiring on the login screen.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['required', 'string', 'in:'.implode(',', LocaleResolver::SUPPORTED)],
        ]);

        // One year: long enough that the office never sees the switch reset,
        // short enough to expire on a shared machine nobody returns to.
        Cookie::queue(Cookie::make(LocaleResolver::COOKIE, $data['language'], 60 * 24 * 365));

        return back();
    }
}
