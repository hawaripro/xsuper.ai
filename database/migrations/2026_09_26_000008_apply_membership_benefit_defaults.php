<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PACKAGES = [
        '1_day' => [10000, 75, 250000, 0],
        '1_week' => [20000, 150, 500000, 1],
        '1_month' => [55000, 400, 1500000, 2],
        '3_months' => [135000, 1000, 3500000, 3],
        '6_months' => [259000, 1900, 7000000, 5],
        '12_months' => [499000, 3700, 13500000, 10],
    ];

    public function up(): void
    {
        $rate = (int) DB::table('pricing_settings')->where('id', 1)->value('wallet_idr_per_usd');
        foreach (self::PACKAGES as $key => [$price, $tokens, $wallet, $gib]) {
            $row = DB::table('duration_package_prices')->where('package', $key)->first();
            $benefits = ['bonus_tokens' => $tokens, 'bonus_wallet_microusd' => $wallet, 'storage_bytes' => $gib * 1024 ** 3, 'updated_at' => now()];
            if ($row === null) {
                DB::table('duration_package_prices')->insert([
                    'package' => $key, 'price_idr' => $price, 'price_usd' => ceil(round($price / $rate * 100, 8)) / 100,
                    'is_active' => true, 'sort_order' => array_search($key, array_keys(self::PACKAGES), true),
                    'created_at' => now(), ...$benefits,
                ]);
            } else {
                if ($key === '6_months' && (int) $row->price_idr === 299000) {
                    $benefits['price_idr'] = 259000;
                }
                DB::table('duration_package_prices')->where('id', $row->id)->update($benefits);
            }
        }
    }

    public function down(): void
    {
        // Rolling back the bundle removes defaults, never raises a customized selling price.
        foreach (self::PACKAGES as $key => [, $tokens, $wallet, $gib]) {
            DB::table('duration_package_prices')->where('package', $key)
                ->where('bonus_tokens', $tokens)->where('bonus_wallet_microusd', $wallet)->where('storage_bytes', $gib * 1024 ** 3)
                ->update(['bonus_tokens' => 0, 'bonus_wallet_microusd' => 0, 'storage_bytes' => 0]);
        }
    }
};
