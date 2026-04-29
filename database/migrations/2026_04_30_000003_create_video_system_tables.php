<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // User token balance
        Schema::create('user_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('balance')->default(0);
            $table->timestamps();
            $table->unique('user_id');
        });

        // Token transaction history
        Schema::create('token_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['topup', 'deduct', 'refund']);
            $table->integer('amount');
            $table->integer('balance_after');
            $table->string('description')->nullable();
            $table->string('reference_id')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        // Video generation jobs
        Schema::create('video_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('job_id')->unique()->nullable();
            $table->enum('mode', ['prompt', 'ab_testing']);
            $table->text('prompt');
            $table->string('model');
            $table->string('aspect_ratio')->default('16:9');
            $table->integer('duration')->default(10);
            $table->integer('tokens_used')->default(0);
            $table->json('settings')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('video_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_jobs');
        Schema::dropIfExists('token_transactions');
        Schema::dropIfExists('user_tokens');
    }
};
