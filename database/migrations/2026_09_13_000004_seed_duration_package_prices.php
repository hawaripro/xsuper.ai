<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('duration_package_prices')->upsert([
            ['package' => '1_day', 'price_idr' => 5000, 'price_usd' => 0.31, 'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['package' => '1_week', 'price_idr' => 20000, 'price_usd' => 1.25, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['package' => '1_month', 'price_idr' => 55000, 'price_usd' => 3.44, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['package' => '3_months', 'price_idr' => 135000, 'price_usd' => 8.44, 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['package' => '6_months', 'price_idr' => 299000, 'price_usd' => 18.69, 'is_active' => true, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['package' => '12_months', 'price_idr' => 499000, 'price_usd' => 31.19, 'is_active' => true, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
        ], ['package'], ['price_idr', 'price_usd', 'is_active', 'sort_order', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('duration_package_prices')->whereIn('package', [
            '1_day',
            '1_week',
            '1_month',
            '3_months',
            '6_months',
            '12_months',
        ])->delete();
    }
};
