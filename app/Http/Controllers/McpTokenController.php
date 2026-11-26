<?php

namespace App\Http\Controllers;

use App\Models\McpAccessToken;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;

/**
 * Tokens for the MCP endpoint. (Review F06.)
 *
 * A person mints their own; a Super Admin may mint one for someone else and
 * may revoke anyone's. The token is shown once and only its hash is kept, so
 * a lost token is replaced rather than recovered.
 */
class McpTokenController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = McpAccessToken::with('user:id,name')->latest();
        if ($user->role !== 'super-admin') $query->where('user_id', $user->id);

        return response()->json([
            'endpoint' => url('/mcp'),
            'tokens' => $query->get()->map(fn (McpAccessToken $t) => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'owner' => $t->user?->name,
                'hint' => $t->hint.'…',
                'last_used_at' => optional($t->last_used_at)->toIso8601String(),
                'last_used_ip' => $t->last_used_ip,
                'expires_at' => optional($t->expires_at)->toIso8601String(),
                'revoked' => (bool) $t->revoked_at,
            ])->values(),
        ]);
    }

    public function store(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'user_id' => 'nullable|integer|exists:users,id',
            'expires_in_days' => 'nullable|integer|min:1|max:730',
        ]);

        $actor = $request->user();
        $owner = $actor;
        if (!empty($data['user_id']) && (int) $data['user_id'] !== (int) $actor->id) {
            abort_unless($actor->role === 'super-admin', 403, __('messages.access.role_denied'));
            $owner = User::findOrFail($data['user_id']);
        }

        $issued = McpAccessToken::issue(
            $owner,
            $data['name'],
            $actor,
            !empty($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
        );

        // The token itself is never written to the audit trail.
        $audit->log($request, 'mcp.token.created', null, 'MCP access token created.', [
            'token_id' => $issued['token']->id,
            'owner' => $owner->name,
            'name' => $data['name'],
            'expires_at' => optional($issued['token']->expires_at)->toIso8601String(),
        ]);

        return response()->json([
            'message' => __('messages.mcp.token_created'),
            // Shown once. There is no way to read it back.
            'token' => $issued['plain'],
            'endpoint' => url('/mcp'),
            'id' => (int) $issued['token']->id,
        ]);
    }

    public function destroy(Request $request, McpAccessToken $mcpToken, AuditService $audit)
    {
        $actor = $request->user();
        abort_unless($actor->role === 'super-admin' || (int) $mcpToken->user_id === (int) $actor->id, 403);

        $mcpToken->forceFill(['revoked_at' => now()])->save();
        $audit->log($request, 'mcp.token.revoked', null, 'MCP access token revoked.', [
            'token_id' => $mcpToken->id, 'owner' => $mcpToken->user?->name,
        ]);

        return response()->json(['message' => __('messages.mcp.token_revoked')]);
    }
}
