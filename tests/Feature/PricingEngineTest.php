<?php

namespace Tests\Feature;

use App\Models\AiProviderProfile;
use App\Models\ModelCost;
use App\Models\PricingSetting;
use App\Models\TokenPackage;
use App\Services\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Spec reference settings; the seeded token packages put the revenue floor at Rp399.000 / 4.500 tokens.
        $this->settings([
            'margin_pct' => 40, 'llm_margin_pct' => null, 'buffer_pct' => 10, 'payment_fee_pct' => 1,
            'wallet_idr_per_usd' => 16000, 'default_cost_idr_per_usd' => 19000, 'round_tokens' => true,
        ]);
    }

    public function test_llm_sale_rates_match_the_spec_vectors(): void
    {
        $engine = app(PricingEngine::class);
        $provider = $this->provider('usd');

        $this->assertSame(
            ['input_tokens' => 6.65, 'output_tokens' => 33.21, 'cache_read' => 0.67, 'cache_write' => null],
            $engine->llmRates($this->cost(['input_per_million' => 3, 'output_per_million' => 15, 'cache_read_per_million' => 0.3]), $provider),
        );
        $this->assertSame(
            ['input_tokens' => 0.34, 'output_tokens' => 1.33, 'cache_read' => null, 'cache_write' => null],
            $engine->llmRates($this->cost(['input_per_million' => 0.15, 'output_per_million' => 0.6]), $provider),
        );
    }

    public function test_media_token_prices_match_the_spec_vectors(): void
    {
        $engine = app(PricingEngine::class);

        $this->assertSame(399000 / 4500, $engine->tokenRevenueIdr());
        $this->assertSame(10, $engine->mediaTokensFor($this->cost(['unit' => 'generation', 'unit_cost' => 0.025]), $this->provider('usd', 19000)));
        $this->assertSame(30, $engine->mediaTokensFor(
            $this->cost(['currency' => 'credit', 'unit' => 'generation', 'unit_cost' => 16]),
            $this->provider('credit', 85.814),
        ));

        $this->settings(['round_tokens' => false]);
        $this->assertSame(29, app(PricingEngine::class)->mediaTokens(16, 85.814));
    }

    public function test_llm_margin_override_reprices_llm_rates_only(): void
    {
        $this->settings(['llm_margin_pct' => 20]);
        $engine = app(PricingEngine::class);

        // 3 × 19000 / 16000 × 1.1 / (1 − 0.20 − 0.01) = 4.9604…
        $this->assertSame(4.97, $engine->llmSaleUsd(3, 19000));
        $this->assertSame(10, $engine->mediaTokens(0.025, 19000));
    }

    public function test_unknown_or_free_costs_are_never_sold(): void
    {
        $engine = app(PricingEngine::class);
        $usd = $this->provider('usd');

        $this->assertSame(
            ['input_tokens' => 6.65, 'output_tokens' => 33.21, 'cache_read' => null, 'cache_write' => null],
            $engine->llmRates($this->cost([
                'input_per_million' => 3, 'output_per_million' => 15, 'cache_read_per_million' => null, 'cache_write_per_million' => 0,
            ]), $usd),
        );
        foreach ([
            ['input_per_million' => 0, 'output_per_million' => 15],
            ['input_per_million' => 3, 'output_per_million' => null],
            ['input_per_million' => 3, 'output_per_million' => 15, 'status' => 'unknown'],
        ] as $attributes) {
            $this->assertNull($engine->llmRates($this->cost($attributes), $usd));
        }
        // A USD cost cannot be landed through a credit provider's IDR-per-credit rate.
        $this->assertNull($engine->llmRates($this->cost(['input_per_million' => 3, 'output_per_million' => 15]), $this->provider('credit', 85.814)));
        $this->assertNull($engine->mediaTokensFor($this->cost(['unit_cost' => 0]), $usd));
        $this->assertNull($engine->mediaTokensFor($this->cost(['unit_cost' => null]), $usd));
        $this->assertNull($engine->mediaTokensFor($this->cost(['currency' => 'credit', 'unit_cost' => 16]), $this->provider('credit')));
    }

    public function test_llm_rates_round_up_to_cents_from_ten_cents_and_to_basis_points_below(): void
    {
        // 0.02 × 19000 / 16000 × 1.1 / 0.59 = 0.04428…
        $this->assertSame(0.0443, app(PricingEngine::class)->llmSaleUsd(0.02, 19000));

        // Without margin, buffer or fee and with L = W the sale price equals the cost, isolating the rounding.
        $this->settings(['margin_pct' => 0, 'buffer_pct' => 0, 'payment_fee_pct' => 0]);
        $engine = app(PricingEngine::class);
        $this->assertSame(0.1, $engine->llmSaleUsd(0.1, 16000));
        $this->assertSame(0.11, $engine->llmSaleUsd(0.10001, 16000));
        $this->assertSame(0.0513, $engine->llmSaleUsd(0.05123, 16000));
        $this->assertSame(0.0001, $engine->llmSaleUsd(0.00001, 16000));
    }

    public function test_media_tokens_round_up_to_friendly_steps(): void
    {
        TokenPackage::query()->update(['is_active' => false]);
        TokenPackage::create([
            'code' => 'rupiah_per_token', 'name' => 'Rp1 per token', 'base_tokens' => 1000, 'bonus_tokens' => 0,
            'price_idr' => 1000, 'is_active' => true,
        ]);
        // r = 1 and no margin, buffer or fee: the raw price equals the landed unit cost.
        $this->settings(['margin_pct' => 0, 'buffer_pct' => 0, 'payment_fee_pct' => 0]);
        $engine = app(PricingEngine::class);

        foreach ([[0.2, 1], [20, 20], [21, 25], [100, 100], [101, 110], [1000, 1000], [1001, 1050]] as [$cost, $tokens]) {
            $this->assertSame($tokens, $engine->mediaTokens($cost, 1), "unit cost {$cost}");
        }

        $this->settings(['round_tokens' => false]);
        $this->assertSame(1001, app(PricingEngine::class)->mediaTokens(1001, 1));
    }

    public function test_settings_validation_enforces_ranges_and_the_margin_fee_guard(): void
    {
        $engine = app(PricingEngine::class);

        $this->assertSame([
            'margin_pct' => 35.5, 'llm_margin_pct' => null, 'buffer_pct' => 10.0, 'payment_fee_pct' => 1.0,
            'wallet_idr_per_usd' => 16500, 'default_cost_idr_per_usd' => 19000, 'round_tokens' => false, 'chat_output_cap' => 4096,
        ], $engine->validateSettings([
            'margin_pct' => '35.5', 'llm_margin_pct' => null, 'buffer_pct' => 10, 'payment_fee_pct' => 1,
            'wallet_idr_per_usd' => 16500, 'default_cost_idr_per_usd' => '19000', 'round_tokens' => false, 'chat_output_cap' => 4096,
        ]));
        $this->assertSame(['margin_pct' => 94.0, 'payment_fee_pct' => 1.0], $engine->validateSettings(['margin_pct' => 94, 'payment_fee_pct' => 1]));
        $this->assertSame(['margin_pct' => 90.0, 'payment_fee_pct' => 5.0], $engine->validateSettings(['margin_pct' => 90, 'payment_fee_pct' => 5]));

        $this->assertSame(['margin_pct'], array_keys($this->errors(fn () => $engine->validateSettings(['margin_pct' => 95, 'payment_fee_pct' => 1]))));
        $this->assertSame(['llm_margin_pct'], array_keys($this->errors(fn () => $engine->validateSettings(['llm_margin_pct' => 95]))));
        // Omitted fields are checked against the stored settings.
        $this->settings(['margin_pct' => 90]);
        $this->assertSame(['margin_pct'], array_keys($this->errors(fn () => app(PricingEngine::class)->validateSettings(['payment_fee_pct' => 6]))));

        $this->assertEqualsCanonicalizing(
            ['margin_pct', 'llm_margin_pct', 'buffer_pct', 'payment_fee_pct', 'wallet_idr_per_usd', 'default_cost_idr_per_usd', 'round_tokens', 'chat_output_cap'],
            array_keys($this->errors(fn () => $engine->validateSettings([
                'margin_pct' => 95.5, 'llm_margin_pct' => -1, 'buffer_pct' => 101, 'payment_fee_pct' => 21,
                'wallet_idr_per_usd' => 999, 'default_cost_idr_per_usd' => 100001, 'round_tokens' => 'maybe', 'chat_output_cap' => 255,
            ]))),
        );
    }

    public function test_percentage_precision_cannot_bypass_the_persisted_margin_guard(): void
    {
        foreach (['margin_pct', 'llm_margin_pct'] as $margin) {
            $this->assertSame([$margin], array_keys($this->errors(fn () => app(PricingEngine::class)->validateSettings([
                $margin => 94.995, 'payment_fee_pct' => 0.005,
            ]))));
        }

        $values = app(PricingEngine::class)->validateSettings([
            'margin_pct' => 40.004, 'llm_margin_pct' => 20.005, 'buffer_pct' => 10.005, 'payment_fee_pct' => 1.004,
        ]);
        PricingSetting::current()->update($values);
        $this->assertSame([40.0, 20.01, 10.01, 1.0], array_values(PricingSetting::current()->only(array_keys($values))));
    }

    public function test_media_pricing_requires_an_active_token_package(): void
    {
        TokenPackage::query()->update(['is_active' => false]);
        $engine = app(PricingEngine::class);
        $this->assertSame(['token_packages'], array_keys($this->errors(fn () => $engine->tokenRevenueIdr())));
        $this->assertSame(['token_packages'], array_keys($this->errors(fn () => $engine->mediaTokensFor($this->cost(['unit_cost' => 0.025]), $this->provider('usd', 19000)))));
    }

    public function test_landed_cost_uses_the_explicit_rate_or_the_usd_default_and_never_guesses_credits(): void
    {
        $engine = app(PricingEngine::class);

        $this->assertSame(19000.0, $engine->landedIdr($this->provider('usd')));
        $this->assertSame(19500.0, $engine->landedIdr($this->provider('usd', 19500)));
        $this->assertSame(85.814, $engine->landedIdr($this->provider('credit', 85.814)));
        $this->assertNull($engine->landedIdr($this->provider('credit')));
    }

    public function test_package_usd_and_idr_display_follow_the_wallet_rate_after_refresh(): void
    {
        $engine = app(PricingEngine::class);
        $this->assertSame(16.19, $engine->packageUsd(259000));
        $this->assertSame(3.44, $engine->packageUsd(55000));
        $this->assertSame(106400.0, $engine->idrForUsd(6.65));

        $this->settings(['wallet_idr_per_usd' => 20000]);
        $this->assertSame(16.19, $engine->packageUsd(259000));
        $engine->refresh();
        $this->assertSame(12.95, $engine->packageUsd(259000));
        $this->assertSame(886.0, $engine->idrForUsd(0.0443));
    }

    private function settings(array $values): void
    {
        PricingSetting::current()->update($values);
    }

    private function provider(string $currency, ?float $landedIdr = null): AiProviderProfile
    {
        return new AiProviderProfile(['cost_currency' => $currency, 'cost_idr_per_unit' => $landedIdr]);
    }

    private function cost(array $attributes): ModelCost
    {
        return new ModelCost([...['source' => 'reference', 'currency' => 'usd', 'status' => 'ok'], ...$attributes]);
    }

    private function errors(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('Expected a validation error.');
    }
}
