<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('key', 64)->unique();
            $table->string('name')->default('Default');
            $table->boolean('is_active')->default(true);
            $table->json('allowed_models')->nullable(); // null = all allowed
            $table->integer('rate_limit')->default(60); // requests per minute
            $table->integer('total_requests')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('key');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
