<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duration_orders', function (Blueprint $table): void {
            $table->string('payment_method', 24)->nullable()->after('price');
            $table->uuid('payment_reference')->nullable()->unique()->after('payment_method');
            $table->timestamp('payment_expires_at')->nullable()->after('payment_reference');
            $table->timestamp('payment_confirmed_at')->nullable()->after('payment_expires_at');
        });

        Schema::create('payment_checkouts', function (Blueprint $table): void {
            $table->uuid('reference')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('package', 32);
            $table->string('payment_method', 24)->default('qris');
            $table->unsignedInteger('amount_idr');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_checkouts');
        Schema::table('duration_orders', function (Blueprint $table): void {
            $table->dropUnique(['payment_reference']);
            $table->dropColumn(['payment_method', 'payment_reference', 'payment_expires_at', 'payment_confirmed_at']);
        });
    }
};
