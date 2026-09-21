<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image generation gains the same asynchronous submit/poll lifecycle the video and
 * audio pipelines already use. Fast providers (OpenAI, fal) keep generating inside the
 * request; slow task-based providers (Kinovi, ~60-90s) are queued and polled so they
 * never hit the HTTP read timeout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->foreignId('provider_id')->nullable()->after('model')->constrained('ai_provider_profiles')->restrictOnDelete();
            $table->string('upstream_model_id', 160)->nullable()->after('provider_id');
            $table->string('upstream_job_id', 255)->nullable()->after('upstream_model_id');
            $table->string('connection_fingerprint', 64)->nullable()->after('upstream_job_id');
            $table->json('generation_config')->nullable()->after('connection_fingerprint');
            $table->timestamp('submitted_at')->nullable()->after('billing_status');
            $table->timestamp('next_poll_at')->nullable()->index()->after('submitted_at');
            $table->timestamp('processing_started_at')->nullable()->after('next_poll_at');
            $table->uuid('processing_token')->nullable()->after('processing_started_at');
            $table->unsignedInteger('poll_attempts')->default(0)->after('processing_token');
            $table->timestamp('completed_at')->nullable()->after('poll_attempts');
            $table->unique(['provider_id', 'upstream_job_id'], 'image_provider_task_unique');
        });
    }

    public function down(): void
    {
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->dropUnique('image_provider_task_unique');
            $table->dropForeign(['provider_id']);
            $table->dropIndex(['next_poll_at']);
            $table->dropColumn([
                'provider_id', 'upstream_model_id', 'upstream_job_id', 'connection_fingerprint',
                'generation_config', 'submitted_at', 'next_poll_at', 'processing_started_at',
                'processing_token', 'poll_attempts', 'completed_at',
            ]);
        });
    }
};
