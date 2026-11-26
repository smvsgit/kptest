<?php

namespace Tests\Feature;

use App\Console\Commands\DemonstrateMcp;
use App\Mcp\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F06 gated the rollout on a demonstration:
 *
 *   "Demonstrate search/list/path lookup, upload, metadata/tag update, folder
 *    actions and version replacement under actual role limits, audit and
 *    approval rules."
 *
 * `php artisan karyalay:mcp-demo` is that demonstration, run against the live
 * portal over the real endpoint. This file guards the demonstration itself: a
 * tool renamed in the registry, or a clause quietly dropped from the script,
 * would otherwise be found only in front of the reviewers.
 */
class McpDemonstrationTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> every tool name the demonstration calls */
    private function toolsCalled(): array
    {
        $source = file_get_contents((new \ReflectionClass(DemonstrateMcp::class))->getFileName());
        preg_match_all("/(?:tool|callExpectingRefusal)\(\\\$\w+,\s*'([a-z_]+)'/", $source, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_the_command_is_registered(): void
    {
        $this->assertArrayHasKey('karyalay:mcp-demo', \Illuminate\Support\Facades\Artisan::all());
    }

    /** Every tool the script calls has to exist, or the demonstration dies mid-sentence. */
    public function test_every_tool_the_demonstration_calls_is_registered(): void
    {
        $registered = array_column(ToolRegistry::tools(), 'name');
        $called = $this->toolsCalled();

        $this->assertNotEmpty($called, 'The demonstration appears to call no tools at all.');
        foreach ($called as $name) {
            $this->assertContains($name, $registered, "The demonstration calls {$name}, which is not a registered tool.");
        }
    }

    /**
     * The acceptance sentence names five capability groups by name. Each one
     * has to be exercised by at least the tool that performs it.
     */
    public function test_the_demonstration_covers_every_capability_the_review_named(): void
    {
        $called = $this->toolsCalled();

        $required = [
            'search / list / path lookup' => ['search_files', 'list_folders', 'list_folder_files', 'get_file'],
            'upload' => ['upload_file'],
            'metadata / tag update' => ['update_files'],
            'folder actions' => ['create_folder', 'rename_folder', 'delete_folder'],
            'version replacement' => ['add_file_version', 'list_file_versions'],
            'approval rules' => ['request_access', 'decide_access_request'],
        ];

        foreach ($required as $clause => $tools) {
            foreach ($tools as $tool) {
                $this->assertContains($tool, $called, "F06 asks for {$clause}; the demonstration never calls {$tool}.");
            }
        }
    }

    /** The checklist it prints at the end is the acceptance sentence itself. */
    public function test_the_clause_checklist_matches_the_acceptance_sentence(): void
    {
        $clauses = array_values(DemonstrateMcp::CLAUSES);

        foreach (['endpoint', 'transport', 'authentication', 'tool inventory',
                  'search / list / path lookup', 'upload', 'metadata / tag update',
                  'folder actions', 'version replacement', 'actual role limits',
                  'approval rules', 'audit'] as $clause) {
            $this->assertContains($clause, $clauses);
        }
    }

    /**
     * The demonstration must not reach around the endpoint it is demonstrating.
     * Writes may only happen through tool calls - the one exception is the
     * clean-up, which sends its own files to the Recycle Bin afterwards.
     */
    public function test_the_demonstration_does_its_work_over_mcp(): void
    {
        $source = file_get_contents((new \ReflectionClass(DemonstrateMcp::class))->getFileName());
        $body = substr($source, 0, strpos($source, 'private function cleanUp'));

        $this->assertStringNotContainsString('MediaFile::where', $body,
            'The demonstration writes to the database directly instead of calling a tool.');
        $this->assertStringNotContainsString('MediaFolder::', $body,
            'The demonstration touches folders directly instead of calling a tool.');
    }
}
