<?php

namespace App\Console\Commands;

use App\Http\Controllers\DashboardController;
use App\Models\Category;
use App\Models\Department;
use App\Models\MasterDataValue;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\MetadataFieldDefinition;
use App\Models\PortalRole;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MediaAccessService;
use App\Support\SettingsSections;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The measurement F10 asks for, before the architecture is chosen.
 *
 * The review did not report a defect here. It reported that the choice between
 * governed administrator configuration (what the portal does: settings, roles,
 * categories, master data and administrator-defined metadata fields all held in
 * the database and edited in Settings) and a text configuration file was being
 * argued without evidence, and asked for three things to be measured first:
 * representative libraries, query latency, and administrator saves.
 *
 * So this command measures exactly those, on whichever database connection it
 * is pointed at, and prints the numbers rather than an opinion:
 *
 *   1. a representative library is seeded at a given size;
 *   2. the real dashboard payload is built and its queries are split into
 *      configuration reads and library reads, which is the only honest way to
 *      say how much of a page the configuration architecture costs;
 *   3. the same configuration values are then read four ways - one query per
 *      key (today), one query for all keys, through the cache store, and from a
 *      generated PHP file (the config-file alternative) - so the difference
 *      between the architectures is a number and not an assertion;
 *   4. library queries are timed at that size, for an unfiltered Super Admin
 *      and for a department-scoped operator, because visibility scoping is
 *      where a large library actually costs something;
 *   5. administrator saves are timed, including a schema change made while the
 *      library is at full size.
 *
 * Usage:
 *   php artisan karyalay:benchmark --seed --files=25000 --json=storage/bench.json
 */
class BenchmarkArchitecture extends Command
{
    protected $signature = 'karyalay:benchmark
        {--files=25000 : How many media files the representative library holds}
        {--folders=400 : How many folders to spread them across}
        {--fields=12 : How many administrator-defined metadata fields to define}
        {--iterations=7 : How many timed runs per measurement}
        {--seed : Rebuild the representative library before measuring}
        {--label= : A name for this run, carried into the JSON}
        {--json= : Write the measurements to this file as JSON}';

    protected $description = 'Benchmark library scale, query latency and administrator saves (review F10).';

    /**
     * The settings keys a configured portal holds. Taken from the call sites of
     * SystemSetting::valueFor(), so the benchmark reads what the portal reads.
     */
    private const CONFIGURED_KEYS = [
        'security.auth', 'security.network', 'security.two_factor', 'notification.channels',
        'notification.escalation', 'metadata.settings', 'metadata.type_required', 'branding.settings',
        'search.settings', 'search.ai', 'tagging.ai', 'appearance.fonts', 'access.maturity',
        'watermark.settings', 'upload.settings', 'storage.quotas', 'lifecycle.settings',
        'audit.retention', 'recycle.retention', 'maintenance.settings', 'backup.settings',
        'go_live.settings', 'go_live.dependencies', 'user_guide.access', 'reports.scheduler',
        'readiness.scheduler_heartbeat',
    ];

    /** @var array<string,mixed> */
    private array $results = [];

    private int $iterations = 7;

    public function handle(): int
    {
        $this->iterations = max(3, (int) $this->option('iterations'));

        $this->results['label'] = (string) ($this->option('label') ?: 'run');
        $this->results['measured_at'] = now()->toIso8601String();
        $this->results['environment'] = $this->environment();
        $this->line('');
        $this->info('Karyalay Portal - architecture benchmark (review F10)');
        $this->table(['Environment', ''], collect($this->results['environment'])->map(
            fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v]
        )->values()->all());

        if ($this->option('seed')) {
            $this->seedLibrary();
        }

        $this->results['library'] = $this->libraryShape();
        $this->table(['Library', 'Rows'], collect($this->results['library'])->map(
            fn ($v, $k) => [$k, number_format((int) $v)]
        )->values()->all());

        $this->measureDashboardPayload();
        $this->measureConfigurationArchitectures();
        $this->measureLibraryQueries();
        $this->measureAdministratorSaves();

        if ($path = $this->option('json')) {
            $full = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) ? $path : base_path($path);
            @mkdir(dirname($full), 0775, true);
            file_put_contents($full, json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('');
            $this->info('Measurements written to '.$full);
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ setup

    private function environment(): array
    {
        $connection = DB::connection();

        return [
            'php' => PHP_VERSION,
            'opcache' => function_exists('opcache_get_status') && @opcache_get_status(false) !== false,
            'driver' => $connection->getDriverName(),
            'server' => $this->serverVersion($connection->getDriverName()),
            'cache_store' => config('cache.default'),
            'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
            'app_version' => (string) config('version.current'),
        ];
    }

    private function serverVersion(string $driver): string
    {
        try {
            return (string) (DB::selectOne(
                $driver === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v'
            )->v ?? 'unknown');
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function libraryShape(): array
    {
        return [
            'media_files' => MediaFile::count(),
            'media_folders' => MediaFolder::count(),
            'categories' => Category::count(),
            'master_data_values' => MasterDataValue::count(),
            'metadata_field_definitions' => MetadataFieldDefinition::count(),
            'system_settings' => SystemSetting::count(),
            'users' => User::count(),
            'departments' => Department::count(),
        ];
    }

    /**
     * A representative library. Rows are inserted directly, without model
     * events: the point of the exercise is how the portal behaves against a
     * library of this size, not how long an artificial import takes.
     */
    private function seedLibrary(): void
    {
        $files = (int) $this->option('files');
        $folders = max(1, (int) $this->option('folders'));
        $fields = max(0, (int) $this->option('fields'));

        $this->line('');
        $this->info("Seeding a representative library: {$files} files across {$folders} folders.");

        $departments = Department::pluck('id')->all();
        if (count($departments) < 4) {
            for ($i = count($departments); $i < 4; $i++) {
                $departments[] = Department::create(['name' => 'Benchmark Department '.($i + 1)])->id;
            }
        }
        $users = User::pluck('id')->all();
        if (!$users) {
            $this->error('No users exist. Run the seeders first.');
            return;
        }

        // A department-scoped account, because visibility scoping is the part of
        // a large library that actually costs something and a Super Admin never
        // exercises it.
        if (!User::where('role', 'department-operator')->exists()) {
            $users[] = User::create([
                'name' => 'Benchmark Operator',
                'email' => 'benchmark-operator@example.test',
                'password' => bcrypt(Str::random(32)),
                'role' => 'department-operator',
                'department_id' => $departments[0],
                'email_verified_at' => now(),
            ])->id;
        }

        // Master data and categories, at the size an organisation this size has.
        foreach (['city' => 60, 'event' => 120, 'person' => 40, 'language' => 12, 'mandir' => 80, 'country' => 6, 'state' => 30, 'media_type' => 10] as $type => $want) {
            $have = MasterDataValue::where('type', $type)->count();
            for ($i = $have; $i < $want; $i++) {
                MasterDataValue::create(['type' => $type, 'name' => ucfirst($type).' '.($i + 1), 'sort_order' => $i]);
            }
        }
        for ($i = Category::count(); $i < 24; $i++) {
            Category::create(['name' => 'Category '.($i + 1)]);
        }

        // Administrator-defined metadata fields - the schema that a config file
        // would otherwise hold.
        for ($i = MetadataFieldDefinition::count(); $i < $fields; $i++) {
            MetadataFieldDefinition::create([
                'key' => 'bench_field_'.($i + 1),
                'label' => 'Benchmark field '.($i + 1),
                'input_type' => $i % 4 === 0 ? 'select' : 'text',
                'applies_to' => ['image', 'video', 'audio', 'document'],
                'options' => $i % 4 === 0 ? ['alpha', 'beta', 'gamma'] : null,
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }

        // The configuration a working portal carries. A fresh install has none
        // of these rows and still issues the same queries, so measuring against
        // an empty table would flatter the database and prove nothing.
        foreach (self::CONFIGURED_KEYS as $key) {
            if (!SystemSetting::where('key', $key)->exists()) {
                SystemSetting::put($key, ['enabled' => true, 'seeded_for' => 'benchmark', 'value' => Str::random(24)]);
            }
        }

        // Folders: a tree three deep, as the folder workspace actually holds.
        DB::table('media_files')->where('name', 'like', 'Benchmark asset %')->delete();
        MediaFolder::where('name', 'like', 'Bench %')->delete();

        $folderIds = [];
        $roots = [];
        foreach ($departments as $index => $departmentId) {
            $roots[] = MediaFolder::create([
                'department_id' => $departmentId, 'parent_id' => null,
                'name' => 'Bench root '.($index + 1), 'is_active' => true,
            ])->id;
        }
        $folderIds = $roots;
        $level = $roots;
        while (count($folderIds) < $folders) {
            $next = [];
            foreach ($level as $parentId) {
                $parent = MediaFolder::find($parentId);
                for ($i = 0; $i < 6 && count($folderIds) < $folders; $i++) {
                    $child = MediaFolder::create([
                        'department_id' => $parent->department_id, 'parent_id' => $parentId,
                        'name' => 'Bench '.Str::random(6), 'is_active' => true,
                    ]);
                    $folderIds[] = $child->id;
                    $next[] = $child->id;
                }
            }
            if (!$next) break;
            $level = $next;
        }

        $folderDepartments = MediaFolder::whereIn('id', $folderIds)->pluck('department_id', 'id')->all();
        $categoryIds = Category::pluck('id')->all();
        $master = [];
        foreach (['city', 'event', 'person', 'language', 'mandir', 'country', 'state', 'media_type'] as $type) {
            $master[$type] = MasterDataValue::where('type', $type)->pluck('id')->all();
        }
        $fieldKeys = MetadataFieldDefinition::pluck('key')->all();

        $types = ['image', 'video', 'audio', 'document'];
        $policies = ['public', 'protected', 'private'];
        $statuses = ['draft', 'review', 'approved', 'published', 'active', 'archived'];
        $now = now()->toDateTimeString();

        $bar = $this->output->createProgressBar($files);
        $bar->start();
        $chunk = [];
        for ($i = 1; $i <= $files; $i++) {
            $folderId = $folderIds[array_rand($folderIds)];
            $custom = [];
            foreach ($fieldKeys as $position => $key) {
                if ($position % 2 === 0) $custom[$key] = 'value '.(($i + $position) % 50);
            }
            $chunk[] = [
                'name' => 'Benchmark asset '.$i.' '.Str::random(5).'.jpg',
                'type' => $types[$i % 4],
                'size' => random_int(80_000, 400_000_000),
                'category_id' => $categoryIds[array_rand($categoryIds)],
                'department_id' => $folderDepartments[$folderId],
                'folder_id' => $folderId,
                'year' => 2015 + ($i % 12),
                'country_id' => $master['country'][array_rand($master['country'])],
                'state_id' => $master['state'][array_rand($master['state'])],
                'city_id' => $master['city'][array_rand($master['city'])],
                'mandir_id' => $master['mandir'][array_rand($master['mandir'])],
                'event_id' => $master['event'][array_rand($master['event'])],
                'person_id' => $master['person'][array_rand($master['person'])],
                'language_id' => $master['language'][array_rand($master['language'])],
                'media_type_id' => $master['media_type'][array_rand($master['media_type'])],
                'description' => 'Seeded asset for the F10 benchmark, number '.$i.'.',
                'asset_status' => $statuses[$i % 6],
                'access_policy' => $policies[$i % 3],
                'download_allowed' => true,
                'current_version' => 1,
                'tags' => json_encode(['bench', 'tag'.($i % 40), $types[$i % 4]]),
                'custom_metadata' => $custom ? json_encode($custom) : null,
                'file_path' => 'media/bench/'.$i.'.jpg',
                'uploaded_by' => $users[array_rand($users)],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($chunk) >= 500) {
                DB::table('media_files')->insert($chunk);
                $chunk = [];
                $bar->advance(500);
            }
        }
        if ($chunk) {
            DB::table('media_files')->insert($chunk);
            $bar->advance(count($chunk));
        }
        $bar->finish();
        $this->line('');
    }

    // ------------------------------------------------- the dashboard payload

    /**
     * Build the real dashboard payload and split its queries into configuration
     * and library. This is the measurement the argument actually turns on: if
     * governed administrator configuration were inherently slow, it would show
     * here, on the most configuration-heavy page the portal has.
     */
    private function measureDashboardPayload(): void
    {
        $this->line('');
        $this->info('1. The dashboard payload, and what configuration costs inside it');

        $user = User::where('role', 'super-admin')->first() ?: User::first();
        if (!$user) { $this->error('No user to measure with.'); return; }

        $capture = ['total' => 0, 'ms' => 0.0, 'settings' => 0, 'settings_ms' => 0.0, 'schema' => 0, 'by_table' => []];
        $listen = function ($query) use (&$capture) {
            $capture['total']++;
            $capture['ms'] += $query->time;
            $sql = strtolower($query->sql);

            // Attribute the time to the table the query reads from, so the page
            // can be explained rather than merely timed.
            preg_match('/from\s+[`"]?([a-z_]+)[`"]?/', $sql, $m);
            $table = $m[1] ?? 'other';
            $capture['by_table'][$table] ??= ['queries' => 0, 'ms' => 0.0];
            $capture['by_table'][$table]['queries']++;
            $capture['by_table'][$table]['ms'] += $query->time;

            if (str_contains($sql, 'system_settings')) {
                $capture['settings']++;
                $capture['settings_ms'] += $query->time;
            }
            if (str_contains($sql, 'metadata_field_definitions') || str_contains($sql, 'categories')
                || str_contains($sql, 'master_data_values') || str_contains($sql, 'portal_roles')) {
                $capture['schema']++;
            }
        };

        $build = function () use ($user) {
            // A real request starts with no configuration in hand, so each
            // render here does too.
            SystemSetting::flush();
            $request = Request::create('/', 'GET');
            $request->setUserResolver(fn () => $user);
            app()->instance('request', $request);
            auth()->setUser($user);
            $response = app()->call([app(DashboardController::class), 'index'], ['request' => $request]);
            return $response->toResponse($request);
        };

        // Warmed twice before the queries are captured, so the attribution
        // describes a steady-state render and not a cold one.
        $build();
        $build();
        DB::listen($listen);
        $build();
        $queries = $capture;

        $timing = $this->measure('dashboard payload', $build);

        $this->results['dashboard'] = $timing + [
            'queries' => $queries['total'],
            'query_ms' => round($queries['ms'], 2),
            'settings_queries' => $queries['settings'],
            'settings_ms' => round($queries['settings_ms'], 2),
            'schema_queries' => $queries['schema'],
            'settings_share_of_queries' => $queries['total'] ? round(100 * $queries['settings'] / $queries['total'], 1) : 0,
            'settings_share_of_page' => $timing['median'] > 0 ? round(100 * $queries['settings_ms'] / $timing['median'], 1) : 0,
        ];

        $this->table(['Dashboard payload', 'Value'], [
            ['median', $this->ms($timing['median'])],
            ['p95', $this->ms($timing['p95'])],
            ['queries in one render', (string) $queries['total']],
            ['total query time', $this->ms($queries['ms'])],
            ['of those, configuration (system_settings)', (string) $queries['settings']],
            ['configuration query time', $this->ms($queries['settings_ms'])],
            ['configuration as a share of the page', $this->results['dashboard']['settings_share_of_page'].' %'],
        ]);

        $byTable = $queries['by_table'];
        uasort($byTable, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $this->results['dashboard']['by_table'] = $byTable;
        $this->table(['What the page spends its query time on', 'queries', 'time'],
            collect($byTable)->take(8)->map(fn ($row, $table) => [$table, (string) $row['queries'], $this->ms($row['ms'])])->values()->all());
    }

    // ------------------------------------------- the architectures, head to head

    /**
     * The same configuration values, read four ways. The fourth is the
     * config-file alternative itself: a generated PHP file, included the way
     * Laravel includes config, so the comparison is measured and not asserted.
     */
    private function measureConfigurationArchitectures(): void
    {
        $this->line('');
        $this->info('2. The same configuration, read four ways');

        $keys = SystemSetting::pluck('key')->all();
        if (!$keys) { $this->warn('No settings stored; skipping.'); return; }

        $map = SystemSetting::pluck('value', 'key')->all();
        $file = storage_path('framework/bench-config.php');
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, '<?php return '.var_export(array_map(
            fn ($v) => is_string($v) ? json_decode($v, true) : $v, $map
        ), true).';');
        // Included once, as a config file is at boot, before it is timed.
        $included = require $file;

        // One query per key is what the portal did before this review, and what
        // every argument for a configuration file was really arguing against.
        $perKey = $this->measure('one query per key', function () use ($keys) {
            foreach ($keys as $key) SystemSetting::query()->where('key', $key)->first();
        });
        // What it does now: one map, built once and then asked.
        $perRequest = $this->measure('one map per request', function () use ($keys) {
            SystemSetting::flush();
            foreach ($keys as $key) SystemSetting::valueFor($key, []);
        });
        $oneQuery = $this->measure('one query for all keys', function () use ($keys) {
            $all = SystemSetting::pluck('value', 'key')->all();
            foreach ($keys as $key) $all[$key] ?? [];
        });
        $cached = $this->measure('through the cache store', function () use ($keys) {
            $all = cache()->remember('bench.settings', 60, fn () => SystemSetting::pluck('value', 'key')->all());
            foreach ($keys as $key) $all[$key] ?? [];
        });
        $fromFile = $this->measure('from a PHP config file', function () use ($keys, $included) {
            foreach ($keys as $key) $included[$key] ?? [];
        });
        $fileCold = $this->measure('a PHP config file, included each time', function () use ($keys, $file) {
            $values = require $file;
            foreach ($keys as $key) $values[$key] ?? [];
        });

        $this->results['configuration'] = [
            'keys' => count($keys),
            'per_key_query' => $perKey,
            'per_request_map' => $perRequest,
            'single_query' => $oneQuery,
            'cache_store' => $cached,
            'php_file' => $fromFile,
            'php_file_included' => $fileCold,
            'file_advantage_ms' => round($perKey['median'] - $fromFile['median'], 3),
        ];

        $this->table(['Reading '.count($keys).' configuration keys', 'median', 'p95'], [
            ['one query per key (before this review)', $this->ms($perKey['median']), $this->ms($perKey['p95'])],
            ['one map per request (the portal now)', $this->ms($perRequest['median']), $this->ms($perRequest['p95'])],
            ['one query for all of them', $this->ms($oneQuery['median']), $this->ms($oneQuery['p95'])],
            ['through the cache store', $this->ms($cached['median']), $this->ms($cached['p95'])],
            ['a PHP config file, re-included', $this->ms($fileCold['median']), $this->ms($fileCold['p95'])],
            ['a PHP config file, already loaded', $this->ms($fromFile['median']), $this->ms($fromFile['p95'])],
        ]);

        @unlink($file);
        cache()->forget('bench.settings');
    }

    // ------------------------------------------------------ library queries

    private function measureLibraryQueries(): void
    {
        $this->line('');
        $this->info('3. Library queries at this size');

        $access = app(MediaAccessService::class);
        $admin = User::where('role', 'super-admin')->first();
        $scoped = User::where('role', '!=', 'super-admin')->first() ?: $admin;

        $rows = [];
        $out = [];

        foreach (['super admin' => $admin, 'department-scoped' => $scoped] as $label => $user) {
            if (!$user) continue;

            $grid = $this->measure("grid page, {$label}", function () use ($user, $access) {
                $q = MediaFile::with(['uploader.department', 'department', 'category', 'subcategory',
                    'country', 'state', 'city', 'mandir', 'event', 'person', 'language', 'mediaType',
                    'sources.connection', 'visibleToRoles:id,name']);
                $access->applyVisibility($q, $user);
                $q->latest('media_files.created_at')->paginate(24);
            });

            $filtered = $this->measure("filtered grid, {$label}", function () use ($user, $access) {
                $q = MediaFile::query();
                $access->applyVisibility($q, $user);
                $q->where('type', 'image')->where('year', 2019)->where('access_policy', 'public')
                    ->latest('media_files.created_at')->paginate(24);
            });

            $stats = $this->measure("stats aggregate, {$label}", function () use ($user, $access) {
                $base = MediaFile::query();
                $access->applyVisibility($base, $user);
                (clone $base)->count();
                (clone $base)->sum('size');
                (clone $base)->selectRaw('type, SUM(size) as total')->groupBy('type')->pluck('total', 'type');
            });

            $search = $this->measure("database fallback search, {$label}", function () use ($user, $access) {
                $q = MediaFile::query();
                $access->applyVisibility($q, $user);
                $q->where(function ($inner) {
                    $inner->where('name', 'like', '%asset 12%')
                        ->orWhere('description', 'like', '%asset 12%')
                        ->orWhere('tags', 'like', '%asset 12%');
                })->paginate(24);
            });

            foreach ([$grid, $filtered, $stats, $search] as $m) {
                $rows[] = [$m['label'], $this->ms($m['median']), $this->ms($m['p95'])];
                $out[$m['label']] = $m;
            }
        }

        // The administrator-defined field path: a filter on the JSON column that
        // holds what a config file would otherwise have declared as a schema.
        $driver = DB::connection()->getDriverName();
        $key = MetadataFieldDefinition::value('key');
        if ($key) {
            $custom = $this->measure('filter on an administrator-defined field', function () use ($key, $driver) {
                $q = MediaFile::query();
                $driver === 'sqlite'
                    ? $q->whereRaw("json_extract(custom_metadata, '$.\"{$key}\"') = ?", ['value 10'])
                    : $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(custom_metadata, '$.\"{$key}\"')) = ?", ['value 10']);
                $q->paginate(24);
            });
            $rows[] = [$custom['label'], $this->ms($custom['median']), $this->ms($custom['p95'])];
            $out[$custom['label']] = $custom;
        }

        $tree = $this->measure('folder tree with file counts', function () {
            MediaFolder::withCount('files')->orderBy('department_id')->orderBy('name')->get();
        });
        $rows[] = [$tree['label'], $this->ms($tree['median']), $this->ms($tree['p95'])];
        $out[$tree['label']] = $tree;

        $this->results['library_queries'] = $out;
        $this->table(['Query', 'median', 'p95'], $rows);
    }

    // --------------------------------------------------- administrator saves

    private function measureAdministratorSaves(): void
    {
        $this->line('');
        $this->info('4. Administrator saves, with the library at this size');

        $rows = [];
        $out = [];

        $settingSave = $this->measure('save a settings section', function () {
            SystemSetting::put('bench.section', ['value' => random_int(1, 1_000_000)]);
        });

        $fieldCreate = $this->measure('add an administrator-defined field', function () {
            $field = MetadataFieldDefinition::create([
                'key' => 'bench_tmp_'.Str::lower(Str::random(8)),
                'label' => 'Temporary benchmark field',
                'input_type' => 'text',
                'applies_to' => ['image', 'video', 'audio', 'document'],
                'is_active' => true,
            ]);
            $field->delete();
        });

        $fieldEdit = $this->measure('edit an administrator-defined field', function () {
            $field = MetadataFieldDefinition::orderBy('id')->first();
            if ($field) $field->update(['label' => 'Benchmark field '.random_int(1, 1_000_000)]);
        });

        $categorySave = $this->measure('add a category', function () {
            $category = Category::create(['name' => 'Bench category '.Str::random(6)]);
            $category->delete();
        });

        $roleSave = $this->measure('save a role settings-access map', function () {
            $role = PortalRole::orderBy('id')->first();
            if ($role) {
                $map = [];
                foreach (SettingsSections::keys() as $section) {
                    $map[$section] = random_int(0, 1) ? SettingsSections::VIEW : SettingsSections::NONE;
                }
                $role->forceFill(['settings_access' => $map])->save();
            }
        });

        // What a schema change costs against the whole library: the question a
        // config file is usually claimed to answer better.
        $fieldScan = $this->measure('count assets already carrying a field', function () {
            $key = MetadataFieldDefinition::value('key');
            $driver = DB::connection()->getDriverName();
            if (!$key) return;
            MediaFile::query()->whereRaw(
                $driver === 'sqlite'
                    ? "json_extract(custom_metadata, '$.\"{$key}\"') is not null"
                    : "JSON_EXTRACT(custom_metadata, '$.\"{$key}\"') is not null"
            )->count();
        });

        foreach ([$settingSave, $fieldCreate, $fieldEdit, $categorySave, $roleSave, $fieldScan] as $m) {
            $rows[] = [$m['label'], $this->ms($m['median']), $this->ms($m['p95'])];
            $out[$m['label']] = $m;
        }

        SystemSetting::where('key', 'bench.section')->delete();

        $this->results['admin_saves'] = $out;
        $this->table(['Administrator save', 'median', 'p95'], $rows);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{label:string,median:float,p95:float,min:float,max:float,runs:int} */
    private function measure(string $label, callable $fn): array
    {
        $fn(); // warm, so a first-call cost is not reported as the steady state
        $times = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = hrtime(true);
            $fn();
            $times[] = (hrtime(true) - $start) / 1_000_000;
        }
        sort($times);
        $count = count($times);

        return [
            'label' => $label,
            'median' => round($times[intdiv($count, 2)], 3),
            'p95' => round($times[max(0, (int) ceil($count * 0.95) - 1)], 3),
            'min' => round($times[0], 3),
            'max' => round($times[$count - 1], 3),
            'runs' => $count,
        ];
    }

    private function ms(float $value): string
    {
        return number_format($value, $value < 10 ? 3 : 1).' ms';
    }
}
