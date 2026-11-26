<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields an administrator defines, rather than fields we shipped. (Review F09b.)
 *
 * The built-in metadata - year, event, person, place, language, media type -
 * stays exactly where it is, in its own columns with its own master data. This
 * is for everything a department needs that nobody could have anticipated:
 * a reel number, a donor's name, a broadcast date.
 *
 * Values live in `media_files.custom_metadata`, keyed by the definition's key,
 * so adding a field costs no migration and retiring one destroys no data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metadata_field_definitions', function (Blueprint $table) {
            $table->id();
            // Stable once created: it is what the stored values are keyed by.
            $table->string('key', 64)->unique();
            $table->string('label', 120);
            $table->string('help', 300)->nullable();
            $table->string('input_type', 20)->default('text');
            // 'all', or a JSON list of media types this field applies to.
            $table->json('applies_to')->nullable();
            $table->json('options')->nullable();
            $table->string('default_value', 300)->nullable();
            $table->boolean('is_required')->default(false);
            // A locked field may be set once and not changed afterwards - the
            // place for something that is a matter of record.
            $table->boolean('is_locked')->default(false);
            // Retired, not deleted: it leaves the forms and keeps its values.
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (!Schema::hasColumn('media_files', 'custom_metadata')) {
            Schema::table('media_files', function (Blueprint $table) {
                $table->json('custom_metadata')->nullable()->after('tags');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_field_definitions');
        if (Schema::hasColumn('media_files', 'custom_metadata')) {
            Schema::table('media_files', function (Blueprint $table) {
                $table->dropColumn('custom_metadata');
            });
        }
    }
};
