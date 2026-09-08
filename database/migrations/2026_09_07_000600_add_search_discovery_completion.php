<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('filters');
            $table->timestamps();
            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('media_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'media_file_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('media_recent_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->timestamp('viewed_at')->useCurrent();
            $table->timestamps();
            $table->unique(['user_id', 'media_file_id']);
            $table->index(['user_id', 'viewed_at']);
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->index(['uploaded_by', 'created_at'], 'media_uploader_created_idx');
            $table->index(['department_id', 'year'], 'media_department_year_idx');
            $table->index(['event_id', 'language_id'], 'media_event_language_idx');
            $table->index(['source_type', 'asset_status'], 'media_source_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_uploader_created_idx');
            $table->dropIndex('media_department_year_idx');
            $table->dropIndex('media_event_language_idx');
            $table->dropIndex('media_source_status_idx');
        });
        Schema::dropIfExists('media_recent_views');
        Schema::dropIfExists('media_favorites');
        Schema::dropIfExists('saved_searches');
    }
};
