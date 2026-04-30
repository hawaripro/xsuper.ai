<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Usage logs — track every AI request
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('model', 100);
            $table->string('source', 50)->default('web'); // web, api, plugin
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('credit', 10, 4)->default(0);
            $table->string('device_id')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('model');
        });

        // User devices — track browser/plugin sessions
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('device_hash', 64)->unique(); // hash of user_agent + user_id
            $table->string('device_name'); // parsed name: "Chrome 120", "VSCode OpenCode", etc
            $table->string('device_type', 20); // browser, plugin, cli
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->enum('status', ['active', 'blocked', 'pending'])->default('active');
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('usage_logs');
    }
};
