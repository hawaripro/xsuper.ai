<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duration_package_prices', function (Blueprint $table) {
            $table->id();
            $table->string('package', 32)->unique();
            $table->unsignedInteger('price_idr');
            $table->decimal('price_usd', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('usage_rates', function (Blueprint $table) {
            $table->id();
            $table->string('service', 20);
            $table->string('meter', 32);
            $table->string('model', 120);
            $table->string('label', 160);
            $table->string('unit', 40);
            $table->decimal('price_idr', 20, 6)->nullable();
            $table->decimal('price_usd', 20, 8)->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['service', 'meter', 'model']);
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance_microusd')->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->bigInteger('amount_microusd');
            $table->unsignedBigInteger('balance_after_microusd');
            $table->string('service', 20)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('meter', 32)->nullable();
            $table->unsignedBigInteger('quantity')->nullable();
            $table->string('reference_id', 160)->nullable();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('reference_id');
        });

        Schema::table('usage_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_microusd')->default(0)->after('credit');
            $table->foreignId('usage_rate_id')->nullable()->after('cost_microusd')->constrained('usage_rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('usage_rate_id');
            $table->dropColumn('cost_microusd');
        });

        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('usage_rates');
        Schema::dropIfExists('duration_package_prices');
    }
};
