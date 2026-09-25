<?php

namespace App\Services\Pricing;

use App\Models\AiProviderProfile;
use App\Models\ModelCost;
use App\Models\PricingSetting;
use App\Models\TokenPackage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PricingEngine
{
    private ?PricingSetting $currentSettings = null;
    private ?float $tokenFloor = null;

    public function settings(): PricingSetting
    {
        return $this->currentSettings ??= PricingSetting::current();
    }

    public function refresh(): void
    {
        $this->currentSettings = null;
        $this->tokenFloor = null;
    }

    public function validateSettings(array $input): array
    {
        $values = Validator::make($input, [
            'margin_pct' => ['sometimes', 'required', 'numeric', 'between:0,95'],
            'llm_margin_pct' => ['sometimes', 'nullable', 'numeric', 'between:0,95'],
            'buffer_pct' => ['sometimes', 'required', 'numeric', 'between:0,100'],
            'payment_fee_pct' => ['sometimes', 'required', 'numeric', 'between:0,20'],
            'wallet_idr_per_usd' => ['sometimes', 'required', 'integer', 'between:1000,100000'],
            'default_cost_idr_per_usd' => ['sometimes', 'required', 'integer', 'between:1000,100000'],
            'round_tokens' => ['sometimes', 'required', 'boolean'],
            'chat_output_cap' => ['sometimes', 'required', 'integer', 'between:256,131072'],
        ])->validate();
        // Match decimal(5,2) storage before checking the combined margin/fee boundary.
        foreach ($values as $key => $value) {
            $values[$key] = match ($key) {
                'round_tokens' => (bool) $value,
                'wallet_idr_per_usd', 'default_cost_idr_per_usd', 'chat_output_cap' => (int) $value,
                default => $value === null ? null : round((float) $value, 2),
            };
        }
        $combined = array_replace($this->settings()->only(['margin_pct', 'llm_margin_pct', 'payment_fee_pct']), $values);
        $errors = [];
        if (round($combined['margin_pct'] + $combined['payment_fee_pct'], 8) > 95) {
            $errors['margin_pct'] = 'Margin + fee terlalu tinggi (maksimal 95%).';
        }
        if ($combined['llm_margin_pct'] !== null && round($combined['llm_margin_pct'] + $combined['payment_fee_pct'], 8) > 95) {
            $errors['llm_margin_pct'] = 'Margin + fee terlalu tinggi (maksimal 95%).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    public function tokenRevenueIdr(): float
    {
        if ($this->tokenFloor !== null) {
            return $this->tokenFloor;
        }
        $floor = null;
        foreach (TokenPackage::where('is_active', true)->get(['price_idr', 'base_tokens', 'bonus_tokens']) as $package) {
            $tokens = $package->base_tokens + $package->bonus_tokens;
            if ($tokens > 0 && $package->price_idr > 0) {
                $value = $package->price_idr / $tokens;
                $floor = $floor === null ? $value : min($floor, $value);
            }
        }
        if ($floor === null) {
            throw ValidationException::withMessages(['token_packages' => 'Aktifkan minimal satu paket token.']);
        }

        return $this->tokenFloor = $floor;
    }

    public function landedIdr(AiProviderProfile $provider): ?float
    {
        if ($provider->cost_idr_per_unit !== null) {
            return (float) $provider->cost_idr_per_unit > 0 ? (float) $provider->cost_idr_per_unit : null;
        }

        return ($provider->cost_currency ?? 'usd') === 'usd' ? (float) $this->settings()->default_cost_idr_per_usd : null;
    }

    public function llmSaleUsd(float $costPerMillion, float $landedIdr): float
    {
        $settings = $this->settings();
        $sale = $costPerMillion * $landedIdr / $settings->wallet_idr_per_usd
            * (1 + $settings->buffer_pct / 100) / $this->denominator(true);
        $scale = $sale >= 0.1 ? 100 : 10000;

        return ceil(round($sale * $scale, 8)) / $scale;
    }

    public function llmRates(ModelCost $cost, AiProviderProfile $provider): ?array
    {
        $landed = $this->knownLandedCost($cost, $provider);
        if ($landed === null || (float) $cost->input_per_million <= 0 || (float) $cost->output_per_million <= 0) {
            return null;
        }

        return [
            'input_tokens' => $this->llmSaleUsd((float) $cost->input_per_million, $landed),
            'output_tokens' => $this->llmSaleUsd((float) $cost->output_per_million, $landed),
            'cache_read' => (float) $cost->cache_read_per_million > 0 ? $this->llmSaleUsd((float) $cost->cache_read_per_million, $landed) : null,
            'cache_write' => (float) $cost->cache_write_per_million > 0 ? $this->llmSaleUsd((float) $cost->cache_write_per_million, $landed) : null,
        ];
    }

    public function mediaTokens(float $unitCost, float $landedIdr): int
    {
        $raw = max(1, (int) ceil(round($unitCost * $landedIdr * (1 + $this->settings()->buffer_pct / 100)
            / ($this->tokenRevenueIdr() * $this->denominator(false)), 8)));
        if (! $this->settings()->round_tokens || $raw <= 20) {
            return $raw;
        }
        $step = $raw <= 100 ? 5 : ($raw <= 1000 ? 10 : 50);

        return (int) (ceil($raw / $step) * $step);
    }

    public function mediaTokensFor(ModelCost $cost, AiProviderProfile $provider): ?int
    {
        $landed = $this->knownLandedCost($cost, $provider);

        return $landed !== null && (float) $cost->unit_cost > 0 ? $this->mediaTokens((float) $cost->unit_cost, $landed) : null;
    }

    public function packageUsd(int $priceIdr): float
    {
        return ceil(round($priceIdr / $this->settings()->wallet_idr_per_usd * 100, 8)) / 100;
    }

    public function idrForUsd(float $usd): float
    {
        return round($usd * $this->settings()->wallet_idr_per_usd, 6);
    }

    private function knownLandedCost(ModelCost $cost, AiProviderProfile $provider): ?float
    {
        return in_array($cost->status, ['ok', 'estimate'], true) && $cost->currency === ($provider->cost_currency ?? 'usd')
            ? $this->landedIdr($provider) : null;
    }

    private function denominator(bool $llm): float
    {
        $settings = $this->settings();
        $margin = $llm ? ($settings->llm_margin_pct ?? $settings->margin_pct) : $settings->margin_pct;
        $remaining = round(1 - ($margin + $settings->payment_fee_pct) / 100, 8);
        if ($remaining < 0.05) {
            throw ValidationException::withMessages([$llm && $settings->llm_margin_pct !== null ? 'llm_margin_pct' : 'margin_pct' => 'Margin + fee terlalu tinggi (maksimal 95%).']);
        }

        return $remaining;
    }
}
