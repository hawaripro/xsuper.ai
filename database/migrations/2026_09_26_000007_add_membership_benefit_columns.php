<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['duration_package_prices', 'duration_orders'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedInteger('bonus_tokens')->default(0);
                $table->unsignedBigInteger('bonus_wallet_microusd')->default(0);
                $table->unsignedBigInteger('storage_bytes')->default(0);
            });
        }
        // order_id is already nullable with nullOnDelete in the original storage migration.
        Schema::table('user_storage_upgrades', function (Blueprint $table): void {
            $table->foreignId('duration_order_id')->nullable()->constrained('duration_orders')->nullOnDelete();
            $table->index('duration_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_storage_upgrades', function (Blueprint $table): void {
            $table->dropIndex(['duration_order_id']);
            $table->dropConstrainedForeignId('duration_order_id');
        });
        foreach (['duration_package_prices', 'duration_orders'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['bonus_tokens', 'bonus_wallet_microusd', 'storage_bytes']));
        }
    }
};
