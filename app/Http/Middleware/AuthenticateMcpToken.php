<?php

namespace App\Http\Middleware;

use App\Models\McpAccessToken;
use Closure;
use Illuminate\Http\Request;

/**
 * Turn a bearer token into the person it belongs to. (Review F06.)
 *
 * After this the request carries a real user, so every check the portal
 * already makes - role, permission, department, access policy, approval - and
 * every audit entry behave exactly as they do for that person on the screen.
 * There is no separate permission model for MCP, by design.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): mixed
    {
        $plain = $request->bearerToken() ?: (string) $request->header('X-Karyalay-Token');
        if (!$plain) {
            return $this->refuse('A bearer token is required.');
        }

        $token = McpAccessToken::with('user')
            ->where('token_hash', McpAccessToken::hashFor($plain))
            ->first();

        if (!$token || !$token->isUsable()) {
            return $this->refuse('This token is not valid, or has been revoked or has expired.');
        }

        $user = $token->user;
        if (!$user || ($user->status ?? 'active') !== 'active') {
            return $this->refuse('The account this token belongs to is not active.');
        }

        // Seen, with when and from where, so a token nobody recognises can be
        // found and revoked.
        $token->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => substr((string) $request->ip(), 0, 45),
        ])->saveQuietly();

        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('mcp_token', $token);

        return $next($request);
    }

    private function refuse(string $message)
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32001, 'message' => $message],
            'id' => null,
        ], 401);
    }
}
