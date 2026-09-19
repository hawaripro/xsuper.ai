<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side registry for the cross-subdomain admin (dash) hand-off token.
 * Replaces the old deterministic sha256(user|APP_KEY) cookie with a random,
 * hashed, expiring, per-login token that logout and admin action can revoke.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dash_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dash_tokens');
    }
};
