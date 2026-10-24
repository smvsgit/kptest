<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based visibility for media.
 *
 * Alongside the Public/Protected/Private policy, an asset can be granted to one
 * or more portal roles. Holders of a granted role may see and open the asset
 * wherever they sit in the organisation, which is the "explicitly authorized
 * users" case the BRD allows next to owner-department access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_file_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['media_file_id', 'portal_role_id']);
            // Browse filters by role first, so lead with it.
            $table->index(['portal_role_id', 'media_file_id'], 'media_role_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_file_role');
    }
};
