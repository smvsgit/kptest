<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('font_families', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('css_family', 160);
            $table->string('source', 30)->default('custom');
            $table->json('scripts')->nullable();
            $table->string('package')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('font_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('font_family_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('weight')->default(400);
            $table->string('style', 20)->default('normal');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->timestamps();
            $table->unique(['font_family_id', 'weight', 'style']);
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->string('checksum_sha256', 64)->nullable()->after('thumbnail_path')->index();
            $table->json('technical_metadata')->nullable()->after('checksum_sha256');
            $table->string('processing_status', 30)->default('pending')->after('technical_metadata')->index();
            $table->index(['department_id', 'type', 'created_at'], 'media_dept_type_created_idx');
            $table->index(['category_id', 'subcategory_id', 'created_at'], 'media_category_created_idx');
        });

        $now = now();
        $fonts = [
            ['name' => 'Hind Vadodara', 'slug' => 'hind-vadodara', 'css_family' => 'Hind Vadodara', 'source' => 'fontsource', 'scripts' => json_encode(['gujarati','latin']), 'package' => '@fontsource/hind-vadodara', 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Noto Sans Gujarati', 'slug' => 'noto-sans-gujarati', 'css_family' => 'Noto Sans Gujarati', 'source' => 'fontsource', 'scripts' => json_encode(['gujarati','latin']), 'package' => '@fontsource/noto-sans-gujarati', 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Hind', 'slug' => 'hind', 'css_family' => 'Hind', 'source' => 'fontsource', 'scripts' => json_encode(['devanagari','latin']), 'package' => '@fontsource/hind', 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Noto Sans Devanagari', 'slug' => 'noto-sans-devanagari', 'css_family' => 'Noto Sans Devanagari', 'source' => 'fontsource', 'scripts' => json_encode(['devanagari','latin']), 'package' => '@fontsource/noto-sans-devanagari', 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Inter', 'slug' => 'inter', 'css_family' => 'Inter', 'source' => 'fontsource', 'scripts' => json_encode(['latin']), 'package' => '@fontsource/inter', 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Roboto', 'slug' => 'roboto', 'css_family' => 'Roboto', 'source' => 'fontsource', 'scripts' => json_encode(['latin']), 'package' => '@fontsource/roboto', 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ];
        DB::table('font_families')->insert($fonts);

        $settings = [
            'appearance.fonts' => [
                'global' => 'hind-vadodara',
                'gujarati' => 'hind-vadodara',
                'hindi' => 'noto-sans-devanagari',
                'english' => 'inter',
            ],
            'search.settings' => [
                'enabled' => true,
                'mode' => 'meilisearch',
                'fuzzy' => true,
                'search_as_you_type' => true,
                'search_ui_enabled' => true,
                'best_match_sort' => true,
                'department_filter' => true,
                'engine_pipeline_enabled' => true,
                'synonyms' => true,
                'aliases' => true,
                'transliteration' => true,
                'filters' => true,
                'exact_fields_enabled' => true,
                'exact_fields' => ['id'],
                'synonym_groups' => [
                    ['SMVS', 'Swaminarayan Mandir Vasna'],
                    ['AV', 'Audio Video'],
                    ['Guru Purnima', 'Gurupurnima'],
                ],
                'alias_groups' => [
                    ['અમદાવાદ', 'Ahmedabad', 'Amdavad'],
                    ['ગુરુપૂર્ણિમા', 'Guru Purnima', 'Gurupurnima'],
                ],
                'hybrid_semantic_enabled' => false,
            ],
        ];
        foreach ($settings as $key => $value) {
            DB::table('system_settings')->insert([
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_dept_type_created_idx');
            $table->dropIndex('media_category_created_idx');
            $table->dropColumn(['checksum_sha256', 'technical_metadata', 'processing_status']);
        });
        Schema::dropIfExists('font_files');
        Schema::dropIfExists('font_families');
        Schema::dropIfExists('system_settings');
    }
};
