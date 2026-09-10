<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePageAccess
{
    public function handle(Request $request, Closure $next, string $page): mixed
    {
        $user = $request->user();

        if (!$user || ($user->status ?? 'active') !== 'active' || !$user->canAccessPage($page)) {
            abort(403, 'Your assigned portal role does not allow access to this page.');
        }

        return $next($request);
    }
}
