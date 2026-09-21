<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores versioned MediaCapability definitions per model + operation. One row per
 * revision; revisions referenced by jobs are retained. Legacy models are resolved by
 * derivation at runtime and a derived revision is snapshotted here (status 'tested')
 * only when a job needs a stable reference. Catalog lifecycle statuses (found/imported/
 * needs_handling/tested/published/disabled) live here; publication is F6b's concern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_model_profile_id')->constrained('ai_model_profiles')->cascadeOnDelete();
            $table->string('operation', 40);
            $table->unsignedInteger('contract_version')->default(1);
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 24)->default('imported');
            $table->json('definition');
            $table->json('ui_metadata')->nullable();
            $table->string('source_schema_ref', 255)->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['ai_model_profile_id', 'operation', 'revision'], 'media_cap_model_op_rev_unique');
            $table->index(['ai_model_profile_id', 'operation', 'status'], 'media_cap_model_op_status_idx');
            $table->index(['ai_model_profile_id', 'operation', 'source_hash'], 'media_cap_model_op_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_capabilities');
    }
};
