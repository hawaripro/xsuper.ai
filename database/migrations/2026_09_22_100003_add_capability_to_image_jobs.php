<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image jobs created through the Architecture C coordinator carry a stable reference to
 * the capability revision they used, the server-only routing identity, the token price
 * charged, and a submission dedup key (bound to principal + entrypoint + normalized
 * payload over stable ids). Nullable for historical/legacy-sync jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->foreignId('capability_revision_id')->nullable()->after('generation_config')
                ->constrained('media_capabilities')->restrictOnDelete();
            $table->string('routing_identity', 160)->nullable()->after('capability_revision_id');
            $table->unsignedInteger('price_tokens')->nullable()->after('routing_identity');
            $table->string('dedup_key', 64)->nullable()->after('price_tokens');
            $table->string('payload_fingerprint', 64)->nullable()->after('dedup_key');
            $table->index(['user_id', 'dedup_key'], 'image_jobs_user_dedup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->dropIndex('image_jobs_user_dedup_idx');
            $table->dropForeign(['capability_revision_id']);
            $table->dropColumn(['capability_revision_id', 'routing_identity', 'price_tokens', 'dedup_key', 'payload_fingerprint']);
        });
    }
};
