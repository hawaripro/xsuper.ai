<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDuplicateReferences = DB::table('token_transactions')
            ->select(['user_id', 'type', 'reference_id'])
            ->whereNotNull('reference_id')
            ->groupBy('user_id', 'type', 'reference_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($hasDuplicateReferences) {
            throw new RuntimeException(
                'Token transaction reference uniqueness preflight failed; resolve duplicate legacy references before retrying.',
            );
        }
        Schema::create('token_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 80);
            $table->unsignedInteger('base_tokens');
            $table->unsignedInteger('bonus_tokens')->default(0);
            $table->unsignedInteger('price_idr');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        $now = now();
        DB::table('token_packages')->insertOrIgnore([
            ['code' => 'tokens_100', 'name' => '100 token', 'base_tokens' => 100, 'bonus_tokens' => 0, 'price_idr' => 12000, 'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'tokens_320', 'name' => '320 token', 'base_tokens' => 300, 'bonus_tokens' => 20, 'price_idr' => 29000, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'tokens_750', 'name' => '750 token', 'base_tokens' => 700, 'bonus_tokens' => 50, 'price_idr' => 69000, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'tokens_1650', 'name' => '1.650 token', 'base_tokens' => 1500, 'bonus_tokens' => 150, 'price_idr' => 149000, 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'tokens_4500', 'name' => '4.500 token', 'base_tokens' => 4000, 'bonus_tokens' => 500, 'price_idr' => 399000, 'is_active' => true, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('deposit_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('payment_reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('package_code', 32)->nullable();
            $table->string('package_name', 80)->nullable();
            $table->unsignedInteger('base_tokens')->default(0);
            $table->unsignedInteger('bonus_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('amount_idr');
            $table->unsignedBigInteger('credit_microusd')->default(0);
            $table->unsignedInteger('idr_per_usd');
            $table->string('payment_method', 24)->default('qris');
            $table->string('status', 16)->default('checkout');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('token_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference_id', 160);
            $table->string('service', 24);
            $table->string('model', 160);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_tokens');
            $table->unsignedBigInteger('amount_tokens');
            $table->string('billing_mode', 16);
            $table->string('status', 16)->default('reserved');
            $table->json('settlement_details')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_description')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'reference_id']);
            $table->index(['status', 'created_at']);
        });

        try {
            Schema::table('token_transactions', function (Blueprint $table): void {
                $table->unique(
                    ['user_id', 'type', 'reference_id'],
                    'token_transactions_user_type_reference_unique',
                );
            });
        } catch (Throwable $exception) {
            Schema::dropIfExists('token_reservations');
            Schema::dropIfExists('deposit_orders');
            Schema::dropIfExists('token_packages');

            throw $exception;
        }
    }

    public function down(): void
    {
        Schema::table('token_transactions', function (Blueprint $table): void {
            $table->dropUnique('token_transactions_user_type_reference_unique');
        });
        Schema::dropIfExists('token_reservations');
        Schema::dropIfExists('deposit_orders');
        Schema::dropIfExists('token_packages');
    }
};
