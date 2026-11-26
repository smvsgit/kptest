<?php

namespace Tests\Feature;

use App\Mcp\ToolRegistry;
use App\Models\Department;
use App\Models\McpAccessToken;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F06: the portal as an MCP server.
 *
 * The review gates rollout on a demonstration "under actual role limits, audit
 * and approval rules". That sentence is the whole design: a token is one
 * person, a tool call is that person acting, and nothing a token can do is
 * anything its owner could not do by signing in. Most of this file is spent
 * proving the second half - that the limits really are still there.
 */
class McpServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
    }

    private function department(string $name = 'Multimedia'): Department
    {
        return Department::firstOrCreate(['name' => $name], ['is_active' => true, 'is_system' => false]);
    }

    private function operator(?Department $department = null): User
    {
        return User::factory()->create([
            'role' => 'department-operator',
            'department_id' => ($department ?: $this->department())->id,
            'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'department-admin', 'department_id' => $this->department()->id, 'status' => 'active',
        ]);
    }

    private function tokenFor(User $user): string
    {
        return McpAccessToken::issue($user, 'Test client')['plain'];
    }

    /** One JSON-RPC call over the endpoint, exactly as a client makes it. */
    private function rpc(?string $token, string $method, array $params = [], $id = 1)
    {
        return $this->postJson('/mcp', array_filter([
            'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ?: null,
        ], fn ($v) => $v !== null), $token ? ['Authorization' => 'Bearer '.$token] : []);
    }

    /** `call` is taken by the framework's own TestCase, so this one is `tool`. */
    private function tool(string $token, string $tool, array $arguments = [])
    {
        return $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
    }

    private function folder(?Department $department = null, string $name = 'Annakut 2026'): MediaFolder
    {
        $department ??= $this->department();
        return MediaFolder::create([
            'department_id' => $department->id, 'parent_id' => null, 'name' => $name,
            'is_active' => true, 'created_by' => User::query()->value('id'),
        ]);
    }

    private function file(array $overrides = []): MediaFile
    {
        Storage::fake('media');
        Storage::disk('media')->put('uploads/images/one.png', 'the-original-bytes');

        return MediaFile::create(array_replace([
            'name' => 'Poster.png', 'type' => 'image', 'size' => 18,
            'department_id' => $this->department()->id,
            'access_policy' => 'public', 'download_allowed' => true, 'tags' => [],
            'file_path' => 'uploads/images/one.png', 'asset_status' => 'active',
        ], $overrides));
    }

    /** A real 8x8 PNG, since the upload policy decodes images. */
    private function pngBase64(): string
    {
        $gd = imagecreatetruecolor(8, 8);
        $tmp = tempnam(sys_get_temp_dir(), 'png').'.png';
        imagepng($gd, $tmp);
        imagedestroy($gd);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return base64_encode($bytes);
    }

    // --------------------------------------------------------- the token

    public function test_the_endpoint_refuses_a_call_with_no_token(): void
    {
        $this->rpc(null, 'tools/list')->assertStatus(401);
    }

    public function test_the_endpoint_refuses_a_token_that_was_revoked(): void
    {
        $user = $this->operator();
        $issued = McpAccessToken::issue($user, 'Old client');
        $issued['token']->forceFill(['revoked_at' => now()])->save();

        $this->rpc($issued['plain'], 'tools/list')->assertStatus(401);
    }

    public function test_the_endpoint_refuses_a_token_that_expired(): void
    {
        $issued = McpAccessToken::issue($this->operator(), 'Old client', null, now()->subDay());

        $this->rpc($issued['plain'], 'tools/list')->assertStatus(401);
    }

    public function test_a_token_whose_owner_is_disabled_stops_working(): void
    {
        $user = $this->operator();
        $token = $this->tokenFor($user);
        $user->forceFill(['status' => 'disabled'])->save();

        $this->rpc($token, 'tools/list')->assertStatus(401);
    }

    public function test_only_the_hash_of_a_token_is_stored(): void
    {
        $issued = McpAccessToken::issue($this->operator(), 'Client');

        $this->assertDatabaseMissing('mcp_access_tokens', ['token_hash' => $issued['plain']]);
        $this->assertDatabaseHas('mcp_access_tokens', ['token_hash' => hash('sha256', $issued['plain'])]);
    }

    public function test_using_a_token_records_when_and_from_where(): void
    {
        $issued = McpAccessToken::issue($this->operator(), 'Client');
        $this->assertNull($issued['token']->last_used_at);

        $this->rpc($issued['plain'], 'ping')->assertSuccessful();

        $this->assertNotNull($issued['token']->fresh()->last_used_at);
    }

    // ------------------------------------------------------- the protocol

    public function test_initialize_answers_with_the_protocol_and_who_is_connected(): void
    {
        $user = $this->admin();

        $response = $this->rpc($this->tokenFor($user), 'initialize')->assertSuccessful();

        $this->assertSame('2.0', $response->json('jsonrpc'));
        $this->assertNotEmpty($response->json('result.protocolVersion'));
        $this->assertSame('karyalay-portal', $response->json('result.serverInfo.name'));
        $this->assertStringContainsString($user->name, (string) $response->json('result.instructions'));
    }

    public function test_tools_list_describes_every_tool_with_a_schema(): void
    {
        $response = $this->rpc($this->tokenFor($this->admin()), 'tools/list')->assertSuccessful();

        $tools = $response->json('result.tools');
        $this->assertCount(count(ToolRegistry::tools()), $tools);
        foreach ($tools as $tool) {
            $this->assertNotEmpty($tool['name']);
            $this->assertNotEmpty($tool['description']);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }
    }

    public function test_a_read_only_tool_says_so(): void
    {
        $response = $this->rpc($this->tokenFor($this->admin()), 'tools/list');
        $tools = collect($response->json('result.tools'))->keyBy('name');

        $this->assertTrue($tools['search_files']['annotations']['readOnlyHint']);
        $this->assertFalse($tools['upload_file']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['delete_folder']['annotations']['destructiveHint']);
    }

    public function test_an_unknown_method_is_a_protocol_error(): void
    {
        $response = $this->rpc($this->tokenFor($this->admin()), 'does/not/exist')->assertSuccessful();

        $this->assertSame(-32601, $response->json('error.code'));
    }

    public function test_a_notification_is_answered_with_nothing(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], [
            'Authorization' => 'Bearer '.$this->tokenFor($this->admin()),
        ])->assertNoContent();
    }

    // ----------------------------------------------- the demonstration itself

    public function test_search_returns_files_with_their_folder_path(): void
    {
        $folder = $this->folder();
        $this->file(['folder_id' => $folder->id, 'name' => 'Annakut.png']);

        $response = $this->tool($this->tokenFor($this->admin()), 'search_files', ['query' => 'Annakut']);

        $this->assertFalse($response->json('result.isError'));
        $this->assertSame(1, $response->json('result.structuredContent.count'));
        $this->assertSame('Annakut 2026', $response->json('result.structuredContent.files.0.path'));
    }

    public function test_folders_and_their_files_can_be_listed(): void
    {
        $folder = $this->folder();
        $this->file(['folder_id' => $folder->id]);
        $token = $this->tokenFor($this->admin());

        $tree = $this->tool($token, 'list_folders');
        $this->assertSame('Annakut 2026', $tree->json('result.structuredContent.folders.0.name'));

        $inside = $this->tool($token, 'list_folder_files', ['folder_id' => $folder->id]);
        $this->assertSame(1, $inside->json('result.structuredContent.count'));
    }

    public function test_a_file_can_be_uploaded_into_a_folder(): void
    {
        Storage::fake('media');
        $folder = $this->folder();

        $response = $this->tool($this->tokenFor($this->admin()), 'upload_file', [
            'filename' => 'FromMcp.png',
            'content_base64' => $this->pngBase64(),
            'folder_id' => $folder->id,
        ]);

        $this->assertFalse($response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $file = MediaFile::firstOrFail();
        $this->assertSame($folder->id, $file->folder_id);
        $this->assertSame($this->department()->id, $file->department_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'media.uploaded']);
    }

    public function test_metadata_and_tags_can_be_updated(): void
    {
        $file = $this->file();

        $response = $this->tool($this->tokenFor($this->admin()), 'update_files', [
            'file_ids' => [$file->id],
            'metadata' => ['year' => 2026, 'description' => 'From MCP'],
            'tags' => ['annakut'],
        ]);

        $this->assertFalse($response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $fresh = $file->fresh();
        $this->assertSame(2026, (int) $fresh->year);
        $this->assertContains('annakut', $fresh->tags);
        $this->assertDatabaseHas('audit_logs', ['event' => 'media.bulk.updated']);
    }

    public function test_folders_can_be_created_moved_into_and_then_not_deleted(): void
    {
        $file = $this->file();
        $token = $this->tokenFor($this->admin());

        $created = $this->tool($token, 'create_folder', ['name' => 'Gurupurnima 2026']);
        $folderId = $created->json('result.structuredContent.folder.id');
        $this->assertNotNull($folderId);

        $this->tool($token, 'move_files', ['file_ids' => [$file->id], 'folder_id' => $folderId]);
        $this->assertSame($folderId, (int) $file->fresh()->folder_id);

        $refused = $this->tool($token, 'delete_folder', ['folder_id' => $folderId]);
        $this->assertTrue($refused->json('result.isError'));
        $this->assertStringContainsString('1', (string) $refused->json('result.content.0.text'));

        $this->tool($token, 'move_files', ['file_ids' => [$file->id]]);
        $gone = $this->tool($token, 'delete_folder', ['folder_id' => $folderId]);
        $this->assertFalse($gone->json('result.isError'));
    }

    public function test_a_version_can_be_replaced_and_the_history_kept(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('uploads/images/one.png', 'v1-bytes');
        $file = MediaFile::create([
            'name' => 'Poster.png', 'type' => 'image', 'size' => 8,
            'department_id' => $this->department()->id, 'access_policy' => 'public',
            'download_allowed' => true, 'tags' => [], 'file_path' => 'uploads/images/one.png',
            'asset_status' => 'active',
        ]);
        $token = $this->tokenFor($this->admin());

        $added = $this->tool($token, 'add_file_version', [
            'file_id' => $file->id,
            'filename' => 'Poster-v2.png',
            'content_base64' => $this->pngBase64(),
            'change_note' => 'Replaced over MCP',
        ]);

        $this->assertFalse($added->json('result.isError'), (string) $added->json('result.content.0.text'));
        $this->assertSame(2, $added->json('result.structuredContent.version_number'));

        $history = $this->tool($token, 'list_file_versions', ['file_id' => $file->id]);
        $this->assertCount(2, $history->json('result.structuredContent.versions'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'media.version.created']);
    }

    public function test_a_replacement_without_a_change_note_is_refused(): void
    {
        $file = $this->file();

        $response = $this->tool($this->tokenFor($this->admin()), 'add_file_version', [
            'file_id' => $file->id, 'content_base64' => $this->pngBase64(),
        ]);

        $this->assertTrue($response->json('result.isError'));
        $this->assertSame(1, (int) $file->fresh()->current_version);
    }

    // ------------------------------------------------- the limits still hold

    public function test_a_viewer_cannot_upload_through_a_token(): void
    {
        Storage::fake('media');
        $viewer = User::factory()->create([
            'role' => 'viewer', 'department_id' => $this->department()->id, 'status' => 'active',
        ]);

        $response = $this->tool($this->tokenFor($viewer), 'upload_file', [
            'filename' => 'FromMcp.png', 'content_base64' => $this->pngBase64(),
        ]);

        $this->assertTrue($response->json('result.isError'));
        $this->assertSame(0, MediaFile::count());
    }

    public function test_an_operator_cannot_create_a_folder(): void
    {
        $response = $this->tool($this->tokenFor($this->operator()), 'create_folder', ['name' => 'Nope']);

        $this->assertTrue($response->json('result.isError'));
        $this->assertSame(0, MediaFolder::count());
    }

    public function test_a_token_cannot_reach_another_departments_folder(): void
    {
        $other = Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);
        $theirs = $this->folder($other, 'Theirs');

        $response = $this->tool($this->tokenFor($this->admin()), 'list_folder_files', ['folder_id' => $theirs->id]);

        $this->assertTrue($response->json('result.isError'));
    }

    public function test_a_token_cannot_update_another_departments_file(): void
    {
        $other = Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);
        $file = $this->file(['department_id' => $other->id, 'access_policy' => 'public']);

        $response = $this->tool($this->tokenFor($this->admin()), 'update_files', [
            'file_ids' => [$file->id], 'metadata' => ['year' => 1999],
        ]);

        $this->assertTrue($response->json('result.isError'));
        $this->assertNull($file->fresh()->year);
    }

    public function test_a_private_file_from_another_department_is_not_even_searchable(): void
    {
        $other = Department::create(['name' => 'Publications', 'is_active' => true, 'is_system' => false]);
        $this->file(['department_id' => $other->id, 'access_policy' => 'private', 'name' => 'Secret.png']);

        $response = $this->tool($this->tokenFor($this->admin()), 'search_files', ['query' => 'Secret']);

        $this->assertSame(0, $response->json('result.structuredContent.count'));
    }

    // ------------------------------------------------------ approval rules

    public function test_a_protected_file_needs_an_approval_before_it_can_be_downloaded(): void
    {
        $file = $this->file(['access_policy' => 'protected']);
        $operator = $this->operator();
        $token = $this->tokenFor($operator);

        $before = $this->tool($token, 'get_file', ['file_id' => $file->id]);
        $this->assertFalse($before->json('result.structuredContent.can_download'));

        $asked = $this->tool($token, 'request_access', [
            'file_id' => $file->id, 'reason' => 'Needed for the printed booklet', 'level' => 'download',
        ]);
        $this->assertFalse($asked->json('result.isError'), (string) $asked->json('result.content.0.text'));
        $requestId = $asked->json('result.structuredContent.request_id');

        // The person who asked cannot approve their own request.
        $self = $this->tool($token, 'decide_access_request', ['request_id' => $requestId, 'status' => 'approved']);
        $this->assertTrue($self->json('result.isError'));

        $decided = $this->tool($this->tokenFor($this->admin()), 'decide_access_request', [
            'request_id' => $requestId, 'status' => 'approved',
        ]);
        $this->assertFalse($decided->json('result.isError'), (string) $decided->json('result.content.0.text'));

        $after = $this->tool($token, 'get_file', ['file_id' => $file->id]);
        $this->assertTrue($after->json('result.structuredContent.can_download'),
            'Once the owning department approves it, the same token may download it.');
    }

    // ------------------------------------------------------ token management

    public function test_a_person_can_mint_and_revoke_their_own_token(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin)->postJson('/settings/mcp/tokens', ['name' => 'My laptop'])
            ->assertSuccessful();
        $plain = $created->json('token');
        $this->assertStringStartsWith(McpAccessToken::PREFIX, $plain);

        $listed = $this->actingAs($admin)->getJson('/settings/mcp/tokens')->assertSuccessful();
        $this->assertSame(url('/mcp'), $listed->json('endpoint'));
        $this->assertStringNotContainsString($plain, $listed->getContent(), 'A token is shown once and never again.');

        $this->actingAs($admin)->deleteJson('/settings/mcp/tokens/'.$created->json('id'))->assertSuccessful();
        $this->rpc($plain, 'ping')->assertStatus(401);
    }

    public function test_one_person_cannot_mint_a_token_for_another(): void
    {
        $admin = $this->admin();
        $someone = $this->operator();

        $this->actingAs($admin)->postJson('/settings/mcp/tokens', [
            'name' => 'Theirs', 'user_id' => $someone->id,
        ])->assertStatus(403);
    }

    public function test_a_super_admin_may_mint_one_for_someone_else(): void
    {
        $super = User::factory()->create(['role' => 'super-admin', 'department_id' => null, 'status' => 'active']);
        $someone = $this->operator();

        $this->actingAs($super)->postJson('/settings/mcp/tokens', [
            'name' => 'Shared client', 'user_id' => $someone->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('mcp_access_tokens', ['user_id' => $someone->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'mcp.token.created']);
    }
}
