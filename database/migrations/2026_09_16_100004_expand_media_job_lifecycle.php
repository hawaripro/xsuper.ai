<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->string('size', 32)->default('1024x1024')->change();
            $table->string('billing_mode', 20)->default('wallet');
            $table->string('stage', 24)->default('generating');
            $table->unsignedInteger('tokens_reserved')->default(0);
            $table->json('asset_paths')->nullable();
        });
        foreach (['completed', 'failed'] as $status) {
            Schema::getConnection()->table('image_jobs')->where('status', $status)->update(['stage' => $status]);
        }
        Schema::table('video_jobs', function (Blueprint $table): void {
            $table->foreignId('provider_id')->nullable()->constrained('ai_provider_profiles')->restrictOnDelete();
            $table->string('upstream_model_id', 160)->nullable();
            $table->string('upstream_job_id', 255)->nullable();
            $table->string('connection_fingerprint', 64)->nullable();
            $table->string('stage', 24)->default('queued');
            $table->text('improved_prompt')->nullable();
            $table->string('moderation_reason_code', 48)->nullable();
            $table->string('billing_mode', 20)->default('wallet');
            $table->unsignedInteger('tokens_reserved')->default(0);
            $table->json('generation_config')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('next_poll_at')->nullable()->index();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->text('video_url')->nullable()->change();
            $table->text('thumbnail_url')->nullable()->change();
            $table->unique(['provider_id', 'upstream_job_id'], 'video_provider_task_unique');
        });
        Schema::getConnection()->table('video_jobs')->whereIn('status', ['pending', 'processing'])->update(['stage' => 'legacy']);
        foreach (['completed', 'failed'] as $status) {
            Schema::getConnection()->table('video_jobs')->where('status', $status)->update(['stage' => $status]);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->table('image_jobs')->whereRaw('LENGTH(size) > 24')->exists()) {
            throw new RuntimeException('Cannot roll back media lifecycle while image_jobs contains sizes longer than 24 characters.');
        }

        Schema::table('video_jobs', function (Blueprint $table): void {
            $table->dropForeign(['provider_id']);
            $table->dropUnique('video_provider_task_unique');
            $table->dropIndex(['next_poll_at']);
            $table->dropColumn(['provider_id', 'upstream_model_id', 'upstream_job_id', 'connection_fingerprint', 'stage', 'improved_prompt', 'moderation_reason_code', 'billing_mode', 'tokens_reserved', 'generation_config', 'submitted_at', 'next_poll_at', 'processing_started_at', 'completed_at', 'poll_attempts']);
        });
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->string('size', 24)->default('1024x1024')->change();
            $table->dropColumn(['billing_mode', 'stage', 'tokens_reserved', 'asset_paths']);
        });
    }
};
