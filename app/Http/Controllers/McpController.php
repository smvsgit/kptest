<?php

namespace App\Http\Controllers;

use App\Mcp\PortalTools;
use App\Mcp\ToolRegistry;
use Illuminate\Http\Request;
use Throwable;

/**
 * The portal as an MCP server. (Review F06.)
 *
 * One endpoint, JSON-RPC 2.0 over HTTP, which is what a remote client - Claude,
 * or anything else that speaks MCP - connects to. The bearer token has already
 * been turned into a user by the time a request arrives here, so every tool
 * runs as that person, under their role, inside their department, and writes
 * their name into the audit trail.
 *
 * This is deliberately a thin layer. It decides nothing: it looks a tool up,
 * hands it its arguments, and turns what comes back into a JSON-RPC result.
 */
class McpController extends Controller
{
    private const PROTOCOL = '2025-06-18';

    public function handle(Request $request, PortalTools $tools)
    {
        $payload = $request->json()->all();

        // A client may batch; a notification has no id and expects no answer.
        if (array_is_list($payload) && $payload) {
            $answers = array_values(array_filter(array_map(
                fn ($one) => $this->dispatch(is_array($one) ? $one : [], $tools), $payload
            )));
            return $answers ? response()->json($answers) : response()->noContent();
        }

        $answer = $this->dispatch($payload, $tools);
        return $answer ? response()->json($answer) : response()->noContent();
    }

    private function dispatch(array $message, PortalTools $tools): ?array
    {
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = (array) ($message['params'] ?? []);

        // Notifications are acknowledged by answering nothing at all.
        if ($id === null && str_starts_with($method, 'notifications/')) return null;

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'ping' => (object) [],
                'tools/list' => $this->toolList(),
                'tools/call' => $this->callTool($params, $tools),
                default => throw new JsonRpcError('Method not found: '.$method, -32601),
            };

            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (JsonRpcError $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->rpcCode, 'message' => $e->getMessage()]];
        } catch (Throwable $e) {
            report($e);
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'The portal could not complete that call.']];
        }
    }

    private function initialize(): array
    {
        $user = request()->user();

        return [
            'protocolVersion' => self::PROTOCOL,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'karyalay-portal',
                // The portal's own name as the administrator set it, not the
                // framework's APP_NAME, which a client would show verbatim.
                'title' => trim((string) (app(\App\Services\LocaleResolver::class)->branding()['portal_name'] ?? '')) ?: 'Karyalay Portal',
                'version' => (string) config('version.current', 'dev'),
            ],
            // Not part of the specification, but it is the first thing anyone
            // setting this up wants to know: who am I connected as?
            'instructions' => sprintf(
                'Connected as %s (%s%s). Every tool runs under this person\'s role and department, '
                .'and every change is written to the portal\'s audit trail in their name.',
                $user?->name ?? 'unknown',
                $user?->roleDisplayName() ?? 'no role',
                $user?->department?->name ? ', '.$user->department->name : ''
            ),
        ];
    }

    private function toolList(): array
    {
        return ['tools' => array_map(fn ($tool) => [
            'name' => $tool['name'],
            'title' => $tool['title'],
            'description' => $tool['description'],
            'inputSchema' => $tool['schema'],
            'annotations' => [
                'readOnlyHint' => !$tool['writes'],
                'destructiveHint' => in_array($tool['name'], ['delete_folder'], true),
            ],
        ], ToolRegistry::tools())];
    }

    private function callTool(array $params, PortalTools $tools): array
    {
        $name = (string) ($params['name'] ?? '');
        $tool = ToolRegistry::find($name);
        if (!$tool) throw new JsonRpcError('No such tool: '.$name, -32602);

        try {
            $result = $tools->{$tool['method']}((array) ($params['arguments'] ?? []));
        } catch (Throwable $e) {
            // A refusal is the portal answering, not the server failing, so it
            // comes back as a tool error the model can read and act on.
            return [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
            ];
        }

        return [
            'isError' => false,
            'content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $result,
        ];
    }
}

/** A fault in the protocol itself, as opposed to a refusal by the portal. */
class JsonRpcError extends \RuntimeException
{
    public function __construct(string $message, public int $rpcCode = -32600)
    {
        parent::__construct($message);
    }
}
