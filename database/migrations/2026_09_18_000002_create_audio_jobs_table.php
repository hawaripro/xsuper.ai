<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('job_id')->unique();
            $table->string('model', 160);
            $table->string('mode', 16);
            $table->text('prompt');
            $table->text('provider_prompt');
            $table->string('voice', 80)->nullable();
            $table->double('speed')->nullable();
            $table->unsignedSmallInteger('duration')->nullable();
            $table->unsignedSmallInteger('tempo')->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('ai_provider_profiles')->restrictOnDelete();
            $table->string('upstream_model_id', 160);
            $table->string('upstream_job_id', 255)->nullable();
            $table->string('connection_fingerprint', 64);
            $table->json('generation_config');
            $table->string('status', 24)->default('pending');
            $table->string('stage', 24)->default('queued');
            $table->text('audio_url')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->string('billing_mode', 20)->default('tokens');
            $table->string('billing_status', 20)->default('reserved');
            $table->string('billing_reference_id', 160)->unique();
            $table->unsignedInteger('tokens_reserved');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('next_poll_at')->nullable()->index();
            $table->timestamp('processing_started_at')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->timestamps();
            $table->unique(['provider_id', 'upstream_job_id'], 'audio_provider_task_unique');
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_jobs');
    }
};
