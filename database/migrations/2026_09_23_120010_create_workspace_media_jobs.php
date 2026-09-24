<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_media_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('request_key', 64);
            $table->string('model', 160);
            $table->string('operation', 100);
            $table->string('conversation_id', 160)->nullable();
            $table->json('capability_snapshot');
            $table->json('execution');
            $table->char('payload_fingerprint', 64);
            $table->json('job_ids');
            $table->timestamps();
            $table->unique(['user_id', 'request_key'], 'workspace_media_request_identity');
        });
        Schema::create('workspace_media_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('job_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('ai_provider_profiles')->restrictOnDelete();
            $table->foreignId('capability_revision_id')->nullable()->constrained('media_capabilities')->restrictOnDelete();
            $table->string('model', 160);
            $table->string('model_label')->nullable();
            $table->string('operation', 100);
            $table->string('output_kind', 30);
            $table->string('conversation_id', 160)->nullable();
            $table->string('upstream_model_id', 160);
            $table->string('upstream_job_id')->nullable();
            $table->char('connection_fingerprint', 64);
            $table->char('capability_hash', 64);
            $table->char('payload_fingerprint', 64);
            $table->json('capability_snapshot');
            $table->json('provider_bindings');
            $table->json('normalized_inputs');
            $table->json('input_assets');
            $table->json('reference_asset_ids');
            $table->unsignedInteger('price_tokens');
            $table->string('price_unit', 40);
            $table->string('billing_mode', 20);
            $table->string('billing_status', 20)->index();
            $table->string('billing_reference_id', 160)->unique();
            $table->unsignedInteger('tokens_reserved');
            $table->string('status', 30)->index();
            $table->string('stage', 40);
            $table->text('error_message')->nullable();
            $table->json('provider_result')->nullable();
            $table->json('provider_result_urls')->nullable();
            $table->json('asset_paths')->nullable();
            $table->json('result_data')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('result_received_at')->nullable();
            $table->timestamp('next_poll_at')->nullable()->index();
            $table->timestamp('processing_started_at')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        foreach (['image_jobs', 'video_jobs', 'audio_jobs', 'three_d_jobs'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('conversation_id', 160)->nullable()->index();
            });
        }
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->json('provider_result_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('image_jobs', fn (Blueprint $table) => $table->dropColumn('provider_result_data'));
        foreach (['image_jobs', 'video_jobs', 'audio_jobs', 'three_d_jobs'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                // SQLite cannot drop an indexed column; the index goes first on every driver.
                $table->dropIndex($name.'_conversation_id_index');
                $table->dropColumn('conversation_id');
            });
        }
        Schema::dropIfExists('workspace_media_jobs');
        Schema::dropIfExists('workspace_media_submissions');
    }
};
