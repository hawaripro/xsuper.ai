<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_media_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('ai_provider_profiles')->restrictOnDelete();
            $table->foreignId('capability_revision_id')->constrained('media_capabilities')->restrictOnDelete();
            $table->string('model', 160);
            $table->string('model_label');
            $table->char('request_key', 64);
            $table->char('payload_fingerprint', 64);
            $table->char('connection_fingerprint', 64);
            // SHA-256 of the complete non-trickle offer; a replayed key must carry the identical offer.
            $table->char('offer_fingerprint', 64)->nullable();
            $table->char('session_token_hash', 64);
            $table->char('capability_hash', 64);
            $table->json('capability_snapshot');
            $table->json('provider_bindings');
            $table->json('normalized_inputs');
            $table->json('asset_ids');
            $table->json('recording_asset_ids');
            $table->json('control_updates');
            $table->json('billing_reservation');
            $table->unsignedInteger('price_tokens');
            $table->string('billing_status', 20);
            $table->string('status', 24)->index();
            $table->string('upstream_session_id')->nullable();
            // Encrypted, replay-only transport data for the owner's in-flight tab; cleared once the session ends.
            $table->longText('answer_sdp')->nullable();
            $table->longText('configure_message')->nullable();
            $table->unsignedSmallInteger('max_session_seconds');
            $table->unsignedBigInteger('next_prompt_version')->default(2);
            $table->unsignedSmallInteger('heartbeat_failures')->default(0);
            $table->string('terminal_reason')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('configured_observed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('close_requested_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_media_sessions');
    }
};
