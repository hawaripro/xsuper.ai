<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-defined, duration-based storage upgrade tiers (like Paket Durasi).
        Schema::create('storage_upgrade_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('label', 120);
            $table->unsignedBigInteger('extra_bytes');
            $table->unsignedInteger('days');
            $table->unsignedBigInteger('price_idr');
            $table->decimal('price_usd', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Purchase orders for a storage upgrade (mirrors duration_orders).
        Schema::create('storage_upgrade_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan_key', 64);
            $table->string('label', 120);
            $table->unsignedBigInteger('extra_bytes');
            $table->unsignedInteger('days');
            $table->unsignedBigInteger('price');
            $table->string('payment_method', 32)->default('qris');
            $table->uuid('payment_reference')->nullable();
            $table->timestamp('payment_expires_at')->nullable();
            $table->timestamp('payment_confirmed_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('note', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        // Granted, time-limited quota bumps; effective quota sums active rows.
        Schema::create('user_storage_upgrades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan_key', 64);
            $table->unsignedBigInteger('extra_bytes');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->foreignId('order_id')->nullable()->constrained('storage_upgrade_orders')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_storage_upgrades');
        Schema::dropIfExists('storage_upgrade_orders');
        Schema::dropIfExists('storage_upgrade_plans');
    }
};
