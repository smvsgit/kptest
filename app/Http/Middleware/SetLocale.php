<?php

namespace App\Http\Middleware;

use App\Services\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the application locale for the rest of the request.
 *
 * This has to run after the session is started, so the signed-in user and
 * their preference are known, and before anything that can produce a message:
 * the portal-mode, session-policy and two-factor middleware, every route-level
 * role/permission guard, and the controllers themselves. Its position in the
 * web group's append list is what guarantees that.
 */
class SetLocale
{
    public function __construct(private readonly LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->locales->resolve($request->user()));

        return $next($request);
    }
}
