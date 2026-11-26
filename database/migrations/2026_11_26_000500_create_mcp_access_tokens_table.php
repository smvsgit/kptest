<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens that let a client act as one person over MCP. (Review F06.)
 *
 * Every limit the portal enforces, and every line of its audit trail, is
 * written against a user. A shared service account would satisfy the tools and
 * empty the audit trail of its meaning, so a token belongs to exactly one
 * person and carries their role, their department and their permissions.
 *
 * Only a hash is kept. The token itself is shown once, when it is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            // The first characters, so a person can tell two tokens apart.
            $table->string('hint', 16);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_access_tokens');
    }
};
