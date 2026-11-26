<?php

namespace App\Console\Commands;

use App\Mcp\ToolRegistry;
use App\Models\AuditLog;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The demonstration F06 gated the rollout on, run against the live portal.
 *
 * The review did not ask for an explanation. It asked to be shown:
 *
 *   "Developer must supply endpoint, transport, authentication, tool inventory
 *    and setup. Demonstrate search/list/path lookup, upload, metadata/tag
 *    update, folder actions and version replacement under actual role limits,
 *    audit and approval rules. Gate rollout on this demonstration."
 *
 * So this command does exactly that, over real HTTP, as a remote MCP client
 * would: it mints a token, speaks JSON-RPC 2.0 to POST /mcp, and prints every
 * request and answer with the clause of that sentence each one settles. It is
 * meant to be run on a screen with the reviewers watching, and it cleans up
 * after itself - the demonstration folder, the file and the tokens all go, and
 * the last thing it shows is the revoked token being refused.
 *
 * Nothing here is a special path. Every call goes through the same endpoint,
 * the same middleware and the same services a connected client would use.
 */
class DemonstrateMcp extends Command
{
    protected $signature = 'karyalay:mcp-demo
        {--url= : The portal base URL to drive. Defaults to APP_URL.}
        {--as= : Email of the account the demonstration acts as. Defaults to a Super Admin.}
        {--viewer= : Email of a second, restricted account, for the role-limit and approval clauses.}
        {--create-viewer : Create a temporary restricted account when --viewer is not given.}
        {--keep : Leave the demonstration folder, file and tokens in place.}
        {--json= : Write the full transcript to this file.}';

    protected $description = 'Run the F06 demonstration against the live portal over MCP.';

    private string $endpoint;
    private array $transcript = [];
    private array $covered = [];
    private int $rpcId = 0;

    /** The clauses of the acceptance sentence, in its own words. */
    public const CLAUSES = [
        'endpoint' => 'endpoint',
        'transport' => 'transport',
        'auth' => 'authentication',
        'inventory' => 'tool inventory',
        'search' => 'search / list / path lookup',
        'upload' => 'upload',
        'metadata' => 'metadata / tag update',
        'folder' => 'folder actions',
        'version' => 'version replacement',
        'role' => 'actual role limits',
        'approval' => 'approval rules',
        'audit' => 'audit',
    ];

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $this->endpoint = $base.'/mcp';

        $actor = $this->option('as')
            ? User::where('email', $this->option('as'))->first()
            : User::where('role', 'super-admin')->orderBy('id')->first();
        if (!$actor) {
            $this->error('No account to act as. Pass --as=<email>.');
            return self::FAILURE;
        }

        try {
            [$viewer, $viewerWasCreated] = $this->resolveViewer($actor);
        } catch (DemonstrationStopped $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->line('');
        $this->info('Karyalay Portal - the F06 demonstration');
        $this->table(['', ''], [
            ['Endpoint', $this->endpoint],
            ['Transport', 'JSON-RPC 2.0 over HTTP POST'],
            ['Acting as', $actor->name.' ('.$actor->email.', '.$actor->role.')'],
            ['Restricted account', $viewer ? $viewer->name.' ('.$viewer->email.', '.$viewer->role.')' : 'not supplied'],
            ['Portal version', (string) config('version.current')],
        ]);

        $started = now();
        $mine = McpAccessToken::issue($actor, 'F06 demonstration '.$started->format('Y-m-d H:i'), $actor);
        $theirs = $viewer ? McpAccessToken::issue($viewer, 'F06 demonstration (restricted)', $actor) : null;
        $this->step('auth', 'A token is minted for one person and shown once',
            'Settings -> Security & Access', 'Token for '.$actor->name.': '.$this->mask($mine['plain']));

        $folderId = null;
        $files = [];
        $stopped = null;

        try {
            $this->connect($mine['plain'], $actor);
            $folderId = $this->folderActions($mine['plain']);
            $files[] = $fileId = $this->upload($mine['plain'], $folderId);
            $this->lookups($mine['plain'], $folderId, $fileId);
            $this->metadata($mine['plain'], $fileId);
            $this->versions($mine['plain'], $fileId);
            $this->refusal($mine['plain'], $folderId);

            if ($theirs && $viewer) {
                $this->roleLimits($theirs['plain'], $viewer, $fileId);
                $files[] = $this->approvals($mine['plain'], $theirs['plain'], $viewer, $folderId);
            } else {
                $this->warn('  Skipped: actual role limits and approval rules. '
                    .'Re-run with --viewer=<email> of a restricted account, or --create-viewer.');
            }

            $this->audit($started, $actor, $viewer);
        } catch (DemonstrationStopped $e) {
            $stopped = $e->getMessage();
            $this->error('  The demonstration stopped: '.$stopped);
        } finally {
            $this->cleanUp($mine, $theirs, $folderId, $files, $viewer, $viewerWasCreated);
        }

        $this->verdict();

        if ($path = $this->option('json')) {
            $full = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) ? $path : base_path($path);
            @mkdir(dirname($full), 0775, true);
            file_put_contents($full, json_encode([
                'endpoint' => $this->endpoint,
                'ran_at' => $started->toIso8601String(),
                'portal_version' => (string) config('version.current'),
                'clauses_covered' => array_values(array_unique($this->covered)),
                'transcript' => $this->transcript,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->info('Transcript written to '.$full);
        }

        return !$stopped && count(array_unique($this->covered)) === count(self::CLAUSES)
            ? self::SUCCESS
            : self::FAILURE;
    }

    // ----------------------------------------------------------- the clauses

    private function connect(string $token, User $actor): void
    {
        $init = $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18',
            'clientInfo' => ['name' => 'karyalay-f06-demonstration', 'version' => '1.0']]);
        $this->step('endpoint', 'The portal answers at its own endpoint', 'initialize',
            ($init['result']['serverInfo']['title'] ?? '?').' '.($init['result']['serverInfo']['version'] ?? ''));
        $this->step('transport', 'JSON-RPC 2.0, protocol '.($init['result']['protocolVersion'] ?? '?'),
            'initialize', $init['result']['instructions'] ?? '');

        $this->rpc($token, 'ping', []);

        $list = $this->rpc($token, 'tools/list', []);
        $names = array_column($list['result']['tools'] ?? [], 'name');
        $expected = array_column(ToolRegistry::tools(), 'name');
        $this->step('inventory', count($names).' tools, the same list as the inventory document',
            'tools/list', implode(', ', array_slice($names, 0, 6)).' ...',
            $names === $expected);

        $this->step('auth', 'The token is a person, not a service account', 'initialize',
            'Connected as '.$actor->name.', role '.$actor->role);
    }

    private function folderActions(string $token): int
    {
        $created = $this->tool($token, 'create_folder', [
            'name' => 'MCP demonstration '.now()->format('d M H:i'),
            'description' => 'Created live during the F06 demonstration.',
        ])['folder'];
        $folderId = (int) $created['id'];
        $this->step('folder', 'A folder is created', 'create_folder',
            '#'.$folderId.'  '.$created['path']);

        $renamed = $this->tool($token, 'rename_folder', [
            'folder_id' => $folderId,
            'name' => 'MCP demonstration '.now()->format('d M H:i').' (renamed)',
        ])['folder'];
        $this->step('folder', 'and renamed', 'rename_folder', $renamed['path'] ?? '');

        return $folderId;
    }

    private function upload(string $token, int $folderId): int
    {
        $file = $this->tool($token, 'upload_file', [
            'filename' => 'mcp-demonstration.png',
            'content_base64' => base64_encode($this->image('V1', [42, 109, 99])),
            'folder_id' => $folderId,
            'access_policy' => 'public',
            'tags' => ['mcp', 'demonstration'],
        ])['file'];
        $this->step('upload', 'A file is uploaded into that folder', 'upload_file',
            '#'.$file['id'].'  '.$file['path'].'  ('.$file['size'].' bytes, department '.($file['department'] ?? '?').')');

        return (int) $file['id'];
    }

    private function lookups(string $token, int $folderId, int $fileId): void
    {
        $folders = $this->tool($token, 'list_folders', []);
        $this->step('search', count($folders['folders'] ?? []).' folders listed with their depth and file counts', 'list_folders');

        $inside = $this->tool($token, 'list_folder_files', ['folder_id' => $folderId]);
        $this->step('search', 'The folder lists what it holds, with its path', 'list_folder_files',
            ($inside['folder']['path'] ?? '').'  -  '.count($inside['files'] ?? []).' file(s)');

        $one = $this->tool($token, 'get_file', ['file_id' => $fileId]);
        $this->step('search', 'One file in full, with its path and what this caller may do with it', 'get_file',
            $one['path'].'  preview='.var_export($one['can_preview'] ?? null, true)
            .' download='.var_export($one['can_download'] ?? null, true));

        $found = $this->tool($token, 'search_files', ['query' => 'mcp-demonstration', 'limit' => 5]);
        $ids = array_column($found['files'] ?? [], 'id');
        $this->step('search', 'Search finds it', 'search_files',
            count($ids).' result(s), the uploaded file '.(in_array($fileId, $ids, true) ? 'among them' : 'NOT among them'),
            in_array($fileId, $ids, true));
    }

    private function metadata(string $token, int $fileId): void
    {
        $this->tool($token, 'update_files', [
            'file_ids' => [$fileId],
            'metadata' => ['description' => 'Described over MCP during the F06 demonstration.', 'year' => (int) date('Y')],
            'tags' => ['annakut'],
            'tags_mode' => 'add',
        ]);
        $after = $this->tool($token, 'get_file', ['file_id' => $fileId]);
        $this->step('metadata', 'Metadata and tags are set, and the existing tags survive', 'update_files',
            'tags: '.implode(', ', $after['tags'] ?? []).'  |  year: '.($after['year'] ?? '-'),
            in_array('mcp', $after['tags'] ?? [], true) && in_array('annakut', $after['tags'] ?? [], true));
    }

    private function versions(string $token, int $fileId): void
    {
        $this->tool($token, 'add_file_version', [
            'file_id' => $fileId,
            'content_base64' => base64_encode($this->image('V2', [176, 92, 60])),
            'filename' => 'mcp-demonstration-v2.png',
            'change_note' => 'Replaced during the F06 demonstration.',
        ]);
        $history = $this->tool($token, 'list_file_versions', ['file_id' => $fileId]);
        $versions = $history['versions'] ?? [];
        $this->step('version', 'The file is replaced and the original is kept', 'add_file_version',
            count($versions).' version(s): '.implode(', ', array_map(
                fn ($v) => 'v'.$v['version_number'].' '.($v['original_name'] ?? '')
                    .' by '.($v['changed_by'] ?? '?').' - "'.($v['change_note'] ?? '').'"', $versions)),
            count($versions) >= 2);

        $file = $this->tool($token, 'get_file', ['file_id' => $fileId]);
        $this->step('version', 'Same asset, same id, same folder - one logical file', 'get_file',
            '#'.$file['id'].'  '.$file['path'].'  current version '.($file['current_version'] ?? '?'),
            (int) $file['id'] === $fileId);
    }

    private function refusal(string $token, int $folderId): void
    {
        $error = $this->callExpectingRefusal($token, 'delete_folder', ['folder_id' => $folderId]);
        $this->step('folder', 'A folder that still holds something is refused, and says why', 'delete_folder',
            $error, str_contains(strtolower($error), 'file'));
    }

    private function roleLimits(string $token, User $viewer, int $fileId): void
    {
        $error = $this->callExpectingRefusal($token, 'create_folder', ['name' => 'Should not exist']);
        $this->step('role', 'The restricted account cannot create a folder - the same rule as on screen',
            'create_folder as '.$viewer->role, $error);

        $error = $this->callExpectingRefusal($token, 'update_files', [
            'file_ids' => [$fileId],
            'metadata' => ['description' => 'Should not be written.'],
        ]);
        $this->step('role', 'nor describe an asset', 'update_files as '.$viewer->role, $error);
    }

    /**
     * The approval clause needs a protected asset, so one is uploaded as
     * protected - over MCP, like everything else here. Nothing in this
     * demonstration reaches around the endpoint.
     *
     * @return int the protected file, so cleanup can remove it
     */
    private function approvals(string $ownerToken, string $viewerToken, User $viewer, int $folderId): int
    {
        $protected = $this->tool($ownerToken, 'upload_file', [
            'filename' => 'mcp-demonstration-protected.png',
            'content_base64' => base64_encode($this->image('P', [88, 70, 140])),
            'folder_id' => $folderId,
            'access_policy' => 'protected',
        ])['file'];
        $fileId = (int) $protected['id'];
        $this->step('approval', 'A protected asset is uploaded', 'upload_file',
            '#'.$fileId.'  policy '.($protected['access_policy'] ?? '?'));

        $before = $this->tool($viewerToken, 'get_file', ['file_id' => $fileId]);
        $this->step('approval', 'The restricted account sees a protected asset but cannot take it',
            'get_file as '.$viewer->role,
            'download='.var_export($before['can_download'] ?? null, true),
            ($before['can_download'] ?? true) === false);

        $request = $this->tool($viewerToken, 'request_access', [
            'file_id' => $fileId, 'reason' => 'Needed for the F06 demonstration.', 'level' => 'download',
        ]);
        $requestId = (int) $request['request_id'];
        $this->step('approval', 'so it asks', 'request_access', 'request #'.$requestId.' '.($request['status'] ?? ''));

        $error = $this->callExpectingRefusal($viewerToken, 'decide_access_request', [
            'request_id' => $requestId, 'status' => 'approved',
        ]);
        $this->step('approval', 'and cannot approve its own request', 'decide_access_request as '.$viewer->role, $error);

        $decided = $this->tool($ownerToken, 'decide_access_request', [
            'request_id' => $requestId, 'status' => 'approved', 'note' => 'Approved during the F06 demonstration.',
        ]);
        $this->step('approval', 'The owning department approves', 'decide_access_request',
            'request #'.$requestId.' is now '.($decided['status'] ?? '?'));

        $after = $this->tool($viewerToken, 'get_file', ['file_id' => $fileId]);
        $this->step('approval', 'and only now may the restricted account download it', 'get_file as '.$viewer->role,
            'download='.var_export($after['can_download'] ?? null, true),
            ($after['can_download'] ?? false) === true);

        return $fileId;
    }

    private function audit(\Illuminate\Support\Carbon $since, User $actor, ?User $viewer): void
    {
        $rows = AuditLog::with('user:id,name')
            ->where('created_at', '>=', $since)
            ->whereIn('user_id', array_filter([$actor->id, $viewer?->id]))
            ->orderBy('id')->get();

        $this->line('');
        $this->info('  Audit - everything above, in the name of the person whose token it was');
        $this->table(['When', 'Who', 'Event', 'What'], $rows->take(20)->map(fn (AuditLog $row) => [
            $row->created_at?->format('H:i:s'),
            $row->user?->name ?? '-',
            $row->event,
            Str::limit((string) $row->description, 58),
        ])->all());

        $byViewer = $viewer ? $rows->where('user_id', $viewer->id)->count() : 0;
        $this->step('audit', $rows->count().' audit entries written'
            .($viewer ? ', '.$byViewer.' of them in '.$viewer->name."'s name" : ''),
            'audit_logs', 'append-only, hash-chained', $rows->count() > 0);
    }

    // ------------------------------------------------------------- plumbing

    /** @param array<int,int> $fileIds */
    private function cleanUp(array $mine, ?array $theirs, ?int $folderId, array $fileIds, ?User $viewer, bool $created): void
    {
        if ($this->option('keep')) {
            $this->warn('  --keep: the demonstration folder, files and tokens were left in place.');
            return;
        }

        $this->line('');
        $this->info('  Cleaning up');

        if ($fileIds) {
            // A soft delete, the same one the screen performs: the files go to
            // the Recycle Bin rather than off the disk, so a demonstration run
            // on a live portal destroys nothing.
            \App\Models\MediaFile::whereIn('id', $fileIds)->delete();
            $this->line('   '.count($fileIds).' demonstration file(s) sent to the Recycle Bin');
        }
        if ($folderId) {
            $deleted = $this->tool($mine['plain'], 'delete_folder', ['folder_id' => $folderId], false);
            $this->line('   the demonstration folder removed over MCP'.($deleted ? '' : ' (refused; remove it by hand)'));
        }

        $mine['token']->forceFill(['revoked_at' => now()])->save();
        $theirs && $theirs['token']->forceFill(['revoked_at' => now()])->save();

        $answer = Http::withToken($mine['plain'])->acceptJson()
            ->post($this->endpoint, ['jsonrpc' => '2.0', 'id' => ++$this->rpcId, 'method' => 'ping']);
        $this->step('auth', 'A revoked token stops working at once', 'ping with the revoked token',
            'HTTP '.$answer->status(), $answer->status() === 401);

        if ($created && $viewer) {
            $viewer->forceFill(['status' => 'disabled', 'status_reason' => 'F06 demonstration account.'])->save();
            $this->warn('   the temporary account '.$viewer->email.' was disabled, not deleted, '
                .'so the audit entries above keep their name. Remove it in Settings when the evidence is filed.');
        }
    }

    private function resolveViewer(User $actor): array
    {
        if ($email = $this->option('viewer')) {
            $viewer = User::where('email', $email)->first();
            if (!$viewer) throw new DemonstrationStopped('No account '.$email.'.');

            return [$viewer, false];
        }

        if (!$this->option('create-viewer')) return [null, false];

        $viewer = User::create([
            'name' => 'MCP demonstration viewer',
            'email' => 'mcp-demo-'.Str::lower(Str::random(6)).'@demonstration.invalid',
            'password' => Str::random(40),
            'role' => 'viewer',
            'department_id' => $actor->department_id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->warn('  Created a temporary restricted account: '.$viewer->email);

        return [$viewer, true];
    }

    /** A tiny PNG, so the demonstration uploads a real image and not a placeholder. */
    private function image(string $label, array $rgb): string
    {
        $canvas = imagecreatetruecolor(240, 160);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$rgb));
        imagestring($canvas, 5, 90, 70, $label, imagecolorallocate($canvas, 255, 255, 255));
        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    private function rpc(string $token, string $method, array $params): array
    {
        $body = ['jsonrpc' => '2.0', 'id' => ++$this->rpcId, 'method' => $method];
        if ($params) $body['params'] = $params;

        $answer = Http::withToken($token)->acceptJson()->timeout(60)->post($this->endpoint, $body);
        $decoded = $answer->json() ?: [];
        $this->transcript[] = ['request' => $body, 'status' => $answer->status(), 'response' => $decoded];

        if ($answer->status() === 401) {
            throw new DemonstrationStopped('The portal refused the token (401). Is '.$this->endpoint.' the right address?');
        }

        return $decoded;
    }

    /** @return array the tool's structured result */
    private function tool(string $token, string $name, array $arguments, bool $required = true): array
    {
        $answer = $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
        $result = $answer['result'] ?? [];

        if ($result['isError'] ?? false) {
            $message = $result['content'][0]['text'] ?? 'refused';
            if (!$required) return [];
            // Thrown rather than exited, so the clean-up in handle()'s finally
            // still runs: a half-finished demonstration must not leave a folder,
            // a file or a live token behind on someone's portal.
            throw new DemonstrationStopped($name.' was refused: '.$message);
        }

        return $result['structuredContent'] ?? [];
    }

    private function callExpectingRefusal(string $token, string $tool, array $arguments): string
    {
        $answer = $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
        $result = $answer['result'] ?? [];

        return ($result['isError'] ?? false)
            ? (string) ($result['content'][0]['text'] ?? 'refused')
            : 'NOT REFUSED - the portal allowed it';
    }

    private function step(string $clause, string $says, string $how, string $shows = '', ?bool $ok = null): void
    {
        $ok ??= !str_starts_with($shows, 'NOT REFUSED');
        if ($ok) $this->covered[] = $clause;

        $this->line(sprintf('  %s  <fg=gray>[%s]</> %s', $ok ? '<info>OK</info>' : '<error>!!</error>',
            self::CLAUSES[$clause], $says));
        $this->line(sprintf('      <fg=gray>%s</> %s', $how, $shows));
    }

    private function verdict(): void
    {
        $covered = array_unique($this->covered);
        $missing = array_diff(array_keys(self::CLAUSES), $covered);

        $this->line('');
        $this->table(['Clause of the F06 acceptance sentence', 'Shown'],
            array_map(fn ($key) => [self::CLAUSES[$key], in_array($key, $covered, true) ? 'yes' : 'NO'],
                array_keys(self::CLAUSES)));

        $missing
            ? $this->error('Not demonstrated: '.implode(', ', array_map(fn ($k) => self::CLAUSES[$k], $missing)))
            : $this->info('Every clause of the F06 acceptance sentence was demonstrated against the live portal.');
    }

    private function mask(string $token): string
    {
        return substr($token, 0, 12).str_repeat('.', 8).' (shown once, stored only as a hash)';
    }
}

/**
 * The demonstration cannot go on. Thrown rather than exited so that whatever
 * the run has already created on a live portal is still cleaned up.
 */
class DemonstrationStopped extends \RuntimeException
{
}
