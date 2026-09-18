<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_tool_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('job_id')->unique();
            $table->string('kind', 16);
            $table->string('status', 16)->default('pending');
            $table->string('stage', 24)->default('queued');
            $table->float('progress')->nullable();
            $table->string('title', 240);
            $table->text('source_url')->nullable();
            $table->string('input_name', 240)->nullable();
            $table->string('format', 8);
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->double('duration')->nullable();
            $table->string('error_message', 240)->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'kind', 'created_at']);
            $table->index(['status', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_tool_jobs');
    }
};
