<?php

namespace App\Services;

use App\Models\UsageRate;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class UsageBillingService
{
    public function estimateApiMaximum(string $model, int $inputTokens, int $outputTokens): int
    {
        $rates = $this->apiRates($model);
        if ($rates === null) {
            return 0;
        }

        return $this->apiCostFromRates($rates, $inputTokens, $outputTokens);
    }

    public function estimateInputTokens(array $messages): int
    {
        return max(1, strlen(json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    public function actualApiCost(string $model, array $usage): int
    {
        return $this->estimateApiMaximum(
            $model,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
        );
    }

    public function chargeApiUsage(int $userId, string $model, array $usage, ?string $referenceId = null): int
    {
        $cost = $this->actualApiCost($model, $usage);
        if ($cost === 0) {
            return 0;
        }

        if (! Wallet::debit($userId, $cost, [
            'service' => 'api',
            'model' => $model,
            'meter' => 'request',
            'quantity' => (int) ($usage['total_tokens'] ?? 0),
            'reference_id' => $referenceId,
            'description' => "API usage: {$model}",
        ])) {
            throw ValidationException::withMessages(['wallet' => 'Insufficient usage balance.']);
        }

        return $cost;
    }

    public function reserveApi(int $userId, string $model, int $estimatedInputTokens, int $maximumOutputTokens, string $referenceId): array
    {
        $rates = $this->apiRates($model);
        $snapshot = $rates ? [
            'input_usd_per_million' => $rates['input_tokens'],
            'output_usd_per_million' => $rates['output_tokens'],
        ] : null;
        $cost = $snapshot
            ? $this->apiCostFromRates([
                'input_tokens' => $snapshot['input_usd_per_million'],
                'output_tokens' => $snapshot['output_usd_per_million'],
            ], $estimatedInputTokens, $maximumOutputTokens)
            : 0;
        $reservation = Wallet::reserve($userId, $cost, $referenceId, [
            'service' => 'api',
            'model' => $model,
            'meter' => 'request',
            'quantity' => $estimatedInputTokens + $maximumOutputTokens,
            'description' => "API reservation: {$model}",
            'metadata' => ['rate_snapshot' => $snapshot],
        ]);

        if (! $reservation) {
            throw ValidationException::withMessages(['wallet' => 'Insufficient usage balance.']);
        }

        return [...$reservation, 'rate_snapshot' => $snapshot];
    }

    public function settleApi(int $userId, string $model, array $usage, array $reservation): int
    {
        $snapshot = $reservation['rate_snapshot'] ?? null;
        $actual = $snapshot
            ? $this->apiCostFromRates([
                'input_tokens' => $snapshot['input_usd_per_million'],
                'output_tokens' => $snapshot['output_usd_per_million'],
            ], (int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0))
            : 0;
        if (! Wallet::settle($userId, $reservation, $actual, [
            'service' => 'api',
            'model' => $model,
            'meter' => 'request',
            'quantity' => (int) ($usage['total_tokens'] ?? 0),
            'description' => "API settlement: {$model}",
            'metadata' => ['rate_snapshot' => $snapshot],
        ])) {
            throw ValidationException::withMessages(['wallet' => 'Insufficient usage balance for final settlement.']);
        }

        return $actual;
    }

    public function reserveUnit(int $userId, string $service, string $model, int $quantity, string $referenceId): array
    {
        $rate = UsageRate::forMeter($service, 'unit', $model);
        if (! $rate || $rate->price_usd === null) {
            throw ValidationException::withMessages(['pricing' => 'Active unit pricing is unavailable.']);
        }

        $unitPriceUsd = (float) $rate->price_usd;
        $cost = (int) ceil($unitPriceUsd * $quantity * 1_000_000);
        $reservation = Wallet::reserve($userId, $cost, $referenceId, [
            'service' => $service,
            'model' => $model,
            'meter' => 'unit',
            'quantity' => $quantity,
            'description' => ucfirst($service)." reservation: {$model}",
            'metadata' => ['unit_price_usd' => $unitPriceUsd],
        ]);

        if (! $reservation) {
            throw ValidationException::withMessages(['wallet' => 'Insufficient usage balance.']);
        }

        return [...$reservation, 'unit_price_usd' => $unitPriceUsd];
    }

    public function chargeUnit(int $userId, string $service, string $model, int $quantity, ?string $referenceId = null): int
    {
        $reservation = $this->reserveUnit($userId, $service, $model, $quantity, $referenceId ?? uniqid("{$service}:", true));

        return $reservation['amount_microusd'];
    }

    public function settleUnit(int $userId, string $service, string $model, int $quantity, array $reservation): int
    {
        $cost = (int) $reservation['amount_microusd'];
        $settled = Wallet::settle($userId, $reservation, $cost, [
            'service' => $service,
            'model' => $model,
            'meter' => 'unit',
            'quantity' => $quantity,
            'description' => ucfirst($service)." usage: {$model}",
        ]);

        if (! $settled) {
            throw ValidationException::withMessages(['wallet' => 'Unable to settle usage reservation.']);
        }

        return $cost;
    }

    public function releaseUnit(int $userId, array $reservation, string $description): void
    {
        Wallet::release($userId, $reservation, $description);
    }

    private function apiRates(string $model): ?array
    {
        $rates = UsageRate::activeForModel('api', $model);
        if ($rates->isEmpty()) {
            return null;
        }
        if (! $rates->has('input_tokens') || ! $rates->has('output_tokens')) {
            throw ValidationException::withMessages([
                'model' => "The active API rate for {$model} is incomplete.",
            ]);
        }

        return [
            'input_tokens' => (float) $rates['input_tokens']->price_usd,
            'output_tokens' => (float) $rates['output_tokens']->price_usd,
        ];
    }

    private function apiCostFromRates(array $rates, int $inputTokens, int $outputTokens): int
    {
        return (int) ceil($rates['input_tokens'] * max(0, $inputTokens))
            + (int) ceil($rates['output_tokens'] * max(0, $outputTokens));
    }
}
