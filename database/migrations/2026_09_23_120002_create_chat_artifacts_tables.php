<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 100);
            $table->string('title', 200);
            $table->string('kind', 24);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('current_revision_id')->nullable();
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->uuid('source_operation_id')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'conversation_id']);
        });

        Schema::create('chat_artifact_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('artifact_id')->constrained('chat_artifacts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('filename', 180);
            $table->string('mime', 100);
            $table->string('storage_disk', 16)->default('local');
            $table->string('storage_path', 500)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('content_bytes')->default(0);
            $table->string('generated_job_id', 160)->nullable()->index();
            $table->string('generated_output_id', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['artifact_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_artifact_revisions');
        Schema::dropIfExists('chat_artifacts');
    }
};
