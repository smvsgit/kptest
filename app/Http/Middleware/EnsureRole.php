<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        if (!$request->user() || ($request->user()->status ?? 'active') !== 'active' || !in_array($request->user()->role, $roles)) {
            abort(403, __('messages.access.denied'));
        }

        return $next($request);
    }
}
