<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video jobs created through the Architecture C coordinator carry the same capability
 * metadata as image jobs: the capability revision used, the server-only routing identity,
 * the token price charged, a submission dedup key (principal + entrypoint + normalized
 * payload over stable ids), and the owned reference asset ids (for image_to_video). All
 * nullable so the legacy video path and historical rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table): void {
            $table->foreignId('capability_revision_id')->nullable()->after('generation_config')
                ->constrained('media_capabilities')->restrictOnDelete();
            $table->string('routing_identity', 160)->nullable()->after('capability_revision_id');
            $table->unsignedInteger('price_tokens')->nullable()->after('routing_identity');
            $table->string('dedup_key', 64)->nullable()->after('price_tokens');
            $table->string('payload_fingerprint', 64)->nullable()->after('dedup_key');
            $table->json('reference_asset_ids')->nullable()->after('payload_fingerprint');
            $table->index(['user_id', 'dedup_key'], 'video_jobs_user_dedup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table): void {
            $table->dropIndex('video_jobs_user_dedup_idx');
            $table->dropForeign(['capability_revision_id']);
            $table->dropColumn(['capability_revision_id', 'routing_identity', 'price_tokens', 'dedup_key', 'payload_fingerprint', 'reference_asset_ids']);
        });
    }
};
