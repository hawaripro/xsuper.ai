<?php

namespace Tests\Feature;

use App\Models\AiProviderProfile;
use App\Models\DurationOrder;
use App\Models\DurationPackagePrice;
use App\Models\PricingSetting;
use App\Models\StorageUpgradeOrder;
use App\Models\User;
use App\Models\UserStorageUpgrade;
use App\Services\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const GIB = 1024 ** 3;

    /** Spec §7 membership benefits: bonus tokens, bonus Saldo AI (micro-USD), extra storage bytes. */
    private const BENEFITS = [
        '1_day' => [75, 250_000, 0],
        '1_week' => [150, 500_000, self::GIB],
        '1_month' => [400, 1_500_000, 2 * self::GIB],
        '3_months' => [1000, 3_500_000, 3 * self::GIB],
        '6_months' => [1900, 7_000_000, 5 * self::GIB],
        '12_months' => [3700, 13_500_000, 10 * self::GIB],
    ];

    public function test_pricing_settings_seed_the_deposit_rate_configured_at_migration_time(): void
    {
        $migration = require database_path('migrations/2026_09_26_000001_create_pricing_settings_table.php');
        $migration->down();
        config(['deposits.idr_per_usd' => 15500]);
        $migration->up();

        $settings = PricingSetting::query()->sole();
        $this->assertSame(15500, $settings->wallet_idr_per_usd);
    }

    public function test_recreated_settings_keep_the_saved_wallet_rate(): void
    {
        PricingSetting::query()->delete();
        // Advance the generated ID on both SQLite and PostgreSQL before recreating the singleton.
        PricingSetting::create(['wallet_idr_per_usd' => 18000])->delete();
        PricingSetting::current()->update(['wallet_idr_per_usd' => 18500]);

        $this->assertSame(18500, PricingSetting::current()->wallet_idr_per_usd);
        $this->assertSame(1, PricingSetting::query()->count());
    }

    public function test_provider_acquisition_defaults_feed_pricing_without_leaking_to_members(): void
    {
        foreach (['kinovi', 'fal', 'openai'] as $protocol) {
            AiProviderProfile::query()->create(['slug' => "{$protocol}-costs", 'name' => ucfirst($protocol), 'protocol' => $protocol]);
        }

        (require database_path('migrations/2026_09_26_000003_apply_provider_cost_defaults.php'))->up();

        $providers = AiProviderProfile::query()->get()->keyBy('protocol');
        $costs = $providers->map(fn (AiProviderProfile $provider): array => [
            $provider->cost_currency, $provider->cost_idr_per_unit,
        ]);
        $this->assertSame(['credit', '85.8140'], $costs['kinovi']);
        $this->assertSame(['usd', '19000.0000'], $costs['fal']);
        $this->assertSame(['usd', null], $costs['openai']);
        $engine = app(PricingEngine::class);
        $this->assertSame([85.814, 19000.0, 19000.0], [
            $engine->landedIdr($providers['kinovi']), $engine->landedIdr($providers['fal']), $engine->landedIdr($providers['openai']),
        ]);

        // Provider costs are admin data: model serialization never carries them, the admin payload does.
        $this->assertSame([], array_intersect(['cost_currency', 'cost_idr_per_unit', 'cost_note'], array_keys($providers['kinovi']->toArray())));
        $this->assertSame(
            ['cost_currency' => 'credit', 'cost_idr_per_unit' => 85.814],
            array_intersect_key($providers['kinovi']->adminPayload(), array_flip(['cost_currency', 'cost_idr_per_unit'])),
        );
    }

    public function test_benefit_upsert_keeps_existing_prices_and_lowers_only_the_default_six_month_price(): void
    {
        DurationPackagePrice::query()->update(['bonus_tokens' => 0, 'bonus_wallet_microusd' => 0, 'storage_bytes' => 0]);
        DurationPackagePrice::query()->where('package', '6_months')->update(['price_idr' => 299000]);
        DurationPackagePrice::query()->where('package', '1_week')->update(['price_usd' => 1.5, 'is_active' => false, 'sort_order' => 9]);
        $before = $this->packages();

        $this->benefitMigration()->up();

        $after = $this->packages();
        $this->assertSame(self::BENEFITS, array_map(fn (array $package): array => $package['benefits'], $after));
        $before['6_months']['price']['price_idr'] = 259000;
        $this->assertSame(array_column($before, 'price'), array_column($after, 'price'));
    }

    public function test_benefit_upsert_keeps_a_customized_six_month_price_and_restores_missing_packages(): void
    {
        // Restored rows derive their USD display from the configured wallet rate.
        PricingSetting::current()->update(['wallet_idr_per_usd' => 20000]);
        DurationPackagePrice::query()->where('package', '6_months')->update(['price_idr' => 279000]);
        DurationPackagePrice::query()->where('package', '3_months')->delete();

        $this->benefitMigration()->up();

        $packages = $this->packages();
        $this->assertSame(279000, $packages['6_months']['price']['price_idr']);
        $this->assertSame(
            ['price' => ['price_idr' => 135000, 'price_usd' => '6.75', 'is_active' => true, 'sort_order' => 3], 'benefits' => self::BENEFITS['3_months']],
            $packages['3_months'],
        );

        DurationPackagePrice::query()->where('package', '6_months')->delete();
        $this->benefitMigration()->up();

        $this->assertSame(
            ['price' => ['price_idr' => 259000, 'price_usd' => '12.95', 'is_active' => true, 'sort_order' => 4], 'benefits' => self::BENEFITS['6_months']],
            $this->packages()['6_months'],
        );
    }

    public function test_storage_upgrades_outlive_the_order_that_granted_them(): void
    {
        $user = User::factory()->create();
        $membership = DurationOrder::create([
            'user_id' => $user->id, 'package' => '1_month', 'days' => 30, 'price' => 55000, 'status' => 'approved',
        ]);
        $purchase = StorageUpgradeOrder::create([
            'user_id' => $user->id, 'plan_key' => 'extra-5gb', 'label' => 'Extra 5 GB', 'extra_bytes' => 5 * self::GIB,
            'days' => 30, 'price' => 25000, 'status' => 'approved',
        ]);
        $grant = ['user_id' => $user->id, 'starts_at' => now(), 'expires_at' => now()->addDays(30)];
        $membershipStorage = UserStorageUpgrade::create([
            ...$grant, 'plan_key' => 'membership:1_month', 'extra_bytes' => 2 * self::GIB, 'duration_order_id' => $membership->id,
        ]);
        $purchasedStorage = UserStorageUpgrade::create([
            ...$grant, 'plan_key' => 'extra-5gb', 'extra_bytes' => 5 * self::GIB, 'order_id' => $purchase->id,
        ]);
        $this->assertNull($membershipStorage->fresh()->order_id);

        $membership->delete();
        $purchase->delete();

        $this->assertSame([null, null], [$membershipStorage->fresh()->duration_order_id, $purchasedStorage->fresh()->order_id]);
        $this->assertSame(7 * self::GIB, (int) UserStorageUpgrade::query()->where('user_id', $user->id)->sum('extra_bytes'));
    }

    private function benefitMigration(): object
    {
        return require database_path('migrations/2026_09_26_000008_apply_membership_benefit_defaults.php');
    }

    /** @return array<string, array{price: array, benefits: array}> keyed in catalog order */
    private function packages(): array
    {
        $rows = DurationPackagePrice::query()->get()->keyBy('package');

        return collect(array_keys(DurationOrder::PACKAGES))->mapWithKeys(fn (string $package): array => [$package => [
            'price' => $rows[$package]->only(['price_idr', 'price_usd', 'is_active', 'sort_order']),
            'benefits' => [$rows[$package]->bonus_tokens, $rows[$package]->bonus_wallet_microusd, $rows[$package]->storage_bytes],
        ]])->all();
    }
}
