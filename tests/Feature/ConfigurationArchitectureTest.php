<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * F10: "Prefer governed admin configuration ... Benchmark representative
 * libraries, query latency and admin saves before choosing architecture."
 *
 * The benchmark (php artisan karyalay:benchmark) answered the architecture
 * question in favour of what the portal already does, and found two things to
 * fix on the way: configuration was read one row at a time, thirty times a
 * page, and the column the folder workspace navigates by had no index.
 *
 * This file is the guard on both, so neither can come back quietly.
 */
class ConfigurationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The whole point of the change: however many keys a request asks for, the
     * table is read once.
     */
    public function test_configuration_is_read_once_however_many_keys_are_asked_for(): void
    {
        SystemSetting::put('search.settings', ['enabled' => true]);
        SystemSetting::put('upload.settings', ['max_file_size_mb' => 25]);
        SystemSetting::put('metadata.settings', ['required_fields' => []]);

        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            if (str_contains(strtolower($query->sql), 'system_settings')) $reads++;
        });

        for ($i = 0; $i < 5; $i++) {
            SystemSetting::valueFor('search.settings', []);
            SystemSetting::valueFor('upload.settings', []);
            SystemSetting::valueFor('metadata.settings', []);
            SystemSetting::valueFor('never.stored', []);
        }

        $this->assertSame(1, $reads, 'Twenty reads of four keys should cost one query.');
    }

    /** A missing key still falls back to the caller's default. */
    public function test_a_key_that_was_never_stored_returns_the_default(): void
    {
        $this->assertSame(['a' => 1], SystemSetting::valueFor('nothing.here', ['a' => 1]));
    }

    /**
     * Reading configuration once must not mean reading it stale: a screen that
     * saves a section and then renders it has to see its own change.
     */
    public function test_a_save_is_visible_to_the_next_read_in_the_same_request(): void
    {
        SystemSetting::put('search.settings', ['enabled' => false]);
        $this->assertFalse(SystemSetting::valueFor('search.settings', [])['enabled']);

        SystemSetting::put('search.settings', ['enabled' => true]);
        $this->assertTrue(SystemSetting::valueFor('search.settings', [])['enabled']);
    }

    /** Deleting a row through the model is a write like any other. */
    public function test_deleting_a_setting_is_visible_immediately(): void
    {
        SystemSetting::put('branding.settings', ['portal_name' => 'Karyalay']);
        $this->assertSame('Karyalay', SystemSetting::valueFor('branding.settings', [])['portal_name']);

        SystemSetting::where('key', 'branding.settings')->first()->delete();

        $this->assertSame(['portal_name' => 'Fallback'], SystemSetting::valueFor('branding.settings', ['portal_name' => 'Fallback']));
    }

    /**
     * A write made with the query builder skips the model's events, so the
     * caller has to say so. The guarantee is that saying so works.
     */
    public function test_a_query_builder_write_is_seen_after_an_explicit_flush(): void
    {
        SystemSetting::put('audit.retention', ['days' => 30]);
        $this->assertSame(30, SystemSetting::valueFor('audit.retention', [])['days']);

        DB::table('system_settings')->where('key', 'audit.retention')->update(['value' => json_encode(['days' => 90])]);
        SystemSetting::flush();

        $this->assertSame(90, SystemSetting::valueFor('audit.retention', [])['days']);
    }

    /**
     * The folder workspace is the portal's home screen and it counts the files
     * in every folder. Without an index on the column it counts by, every one
     * of those counts is a scan of the whole library.
     */
    public function test_media_files_are_indexed_by_folder(): void
    {
        $covered = collect(Schema::getIndexes('media_files'))
            ->contains(fn ($index) => ($index['columns'][0] ?? null) === 'folder_id');

        $this->assertTrue($covered, 'media_files needs an index whose first column is folder_id.');
    }

    /** And the counts it feeds still have to be right. */
    public function test_the_folder_tree_still_counts_its_files(): void
    {
        $department = Department::firstOrCreate(['name' => 'Multimedia'], ['is_active' => true, 'is_system' => false]);
        $user = User::factory()->create(['role' => 'super-admin', 'department_id' => $department->id]);
        $folder = MediaFolder::create(['department_id' => $department->id, 'name' => 'Counted', 'is_active' => true]);

        foreach (range(1, 3) as $i) {
            MediaFile::create([
                'name' => "counted-{$i}.jpg", 'type' => 'image', 'size' => 1024,
                'department_id' => $department->id, 'folder_id' => $folder->id,
                'uploaded_by' => $user->id, 'file_path' => "media/counted-{$i}.jpg",
            ]);
        }
        MediaFile::where('name', 'counted-3.jpg')->first()->delete();

        $this->assertSame(2, MediaFolder::withCount('files')->find($folder->id)->files_count);
    }
}
