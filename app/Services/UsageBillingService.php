<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\UsageRate;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class UsageBillingService
{
    /** Reservation allowance for one image input, whatever its resolution. */
    private const IMAGE_TOKENS = 1600;

    /** Reservation allowance for one binary document (PDF or any other non-text file). */
    private const BINARY_DOCUMENT_TOKENS = 12000;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    public function estimateApiMaximum(string $model, int $inputTokens, int $outputTokens): int
    {
        return $this->usageCost($this->apiRates($model), $inputTokens, $outputTokens);
    }

    /**
     * Input tokens a request may bill, estimated before dispatch: 4 per message, about three characters
     * per token for text, a fixed allowance per image and binary document, and the JSON length of tool
     * content and of the tools/response_format/system options.
     */
    public function estimateInputTokens(array $messages, array $options = []): int
    {
        $tokens = 0;
        foreach ($messages as $message) {
            $tokens += 4;
            if (! is_array($message)) {
                $tokens += $this->jsonTokens($message);

                continue;
            }
            $tokens += $this->contentTokens($message['content'] ?? null);
            foreach (['tool_calls', 'function_call'] as $call) {
                if (isset($message[$call])) {
                    $tokens += $this->jsonTokens($message[$call]);
                }
            }
        }
        foreach (['tools', 'response_format', 'system'] as $option) {
            if (isset($options[$option])) {
                $tokens += $this->jsonTokens($options[$option]);
            }
        }

        return max(1, $tokens);
    }

    /**
     * One usage shape for billing: prompt_tokens is the whole input INCLUDING cached tokens, and
     * cache_read_tokens/cache_write_tokens are the cached subsets of it. Accepts OpenAI usage
     * (prompt_tokens_details.cached_tokens), Anthropic usage (input_tokens excludes the
     * cache_read_input_tokens/cache_creation_input_tokens it is summed with) and normalized usage.
     * A missing or invalid prompt/completion count stays missing so settlement refuses it
     * instead of billing zero.
     *
     * @return array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int, cache_read_tokens: int, cache_write_tokens: int}
     */
    public function normalizeUsage(array $raw): array
    {
        if (! array_key_exists('prompt_tokens', $raw) && ! array_key_exists('completion_tokens', $raw)
            && (array_key_exists('input_tokens', $raw) || array_key_exists('output_tokens', $raw))) {
            $input = self::tokenCount($raw['input_tokens'] ?? null);
            $cacheRead = array_key_exists('cache_read_input_tokens', $raw) ? self::tokenCount($raw['cache_read_input_tokens']) : 0;
            $cacheWrite = array_key_exists('cache_creation_input_tokens', $raw) ? self::tokenCount($raw['cache_creation_input_tokens']) : 0;
            $prompt = $input === null || $cacheRead === null || $cacheWrite === null ? null : $input + $cacheRead + $cacheWrite;
            $completion = self::tokenCount($raw['output_tokens'] ?? null);
        } else {
            $details = is_array($raw['prompt_tokens_details'] ?? null) ? $raw['prompt_tokens_details'] : [];
            $prompt = self::tokenCount($raw['prompt_tokens'] ?? null);
            $completion = self::tokenCount($raw['completion_tokens'] ?? null);
            $cacheRead = self::tokenCount($raw['cache_read_tokens'] ?? $details['cached_tokens'] ?? 0);
            $cacheWrite = self::tokenCount($raw['cache_write_tokens'] ?? $details['cache_creation_tokens'] ?? $details['cache_write_tokens'] ?? 0);
        }
        // A sum beyond the integer range is not verifiable billing evidence either.
        if (! is_int($prompt)) {
            $prompt = null;
        }
        if ($prompt !== null && $completion !== null && ! is_int($prompt + $completion)) {
            $prompt = $completion = null;
        }

        $usage = [];
        if ($prompt !== null) {
            $usage['prompt_tokens'] = $prompt;
        }
        if ($completion !== null) {
            $usage['completion_tokens'] = $completion;
        }
        if ($prompt !== null && $completion !== null) {
            $usage['total_tokens'] = $prompt + $completion;
        }
        $usage['cache_read_tokens'] = $prompt === null ? 0 : min($cacheRead ?? 0, $prompt);
        $usage['cache_write_tokens'] = $prompt === null ? 0 : min($cacheWrite ?? 0, $prompt - $usage['cache_read_tokens']);

        return $usage;
    }

    public function actualApiCost(string $model, array $usage): int
    {
        $usage = $this->normalizeUsage($usage);

        return $this->usageCost(
            $this->apiRates($model),
            $usage['prompt_tokens'] ?? 0,
            $usage['completion_tokens'] ?? 0,
            $usage['cache_read_tokens'],
            $usage['cache_write_tokens'],
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

    /** Hold the maximum cost of a request (estimated input at the input rate plus every allowed output token). */
    public function reserveApi(int $userId, string $model, int $estimatedInputTokens, int $maximumOutputTokens, string $referenceId, string $service = 'api'): array
    {
        $rates = $this->apiRates($model);
        $snapshot = $this->rateSnapshot($rates);
        $cost = $this->usageCost($rates, $estimatedInputTokens, $maximumOutputTokens);
        $reservation = Wallet::reserve($userId, $cost, $referenceId, [
            'service' => $service,
            'model' => $model,
            'meter' => 'request',
            'quantity' => $estimatedInputTokens + $maximumOutputTokens,
            'description' => $this->ledgerLabel($service)." reservation: {$model}",
            'metadata' => ['rate_snapshot' => $snapshot],
        ]);

        if (! $reservation) {
            throw InsufficientBalanceException::wallet($cost, Wallet::balance($userId));
        }

        return [...$reservation, 'rate_snapshot' => $snapshot];
    }

    /** Charge actual usage at the reservation's rate snapshot; cache meters without a snapshot rate bill at the input rate. */
    public function settleApi(int $userId, string $model, array $usage, array $reservation, string $service = 'api'): int
    {
        $snapshot = $reservation['rate_snapshot'] ?? null;
        if (! is_array($snapshot) || ! isset($snapshot['input_usd_per_million'], $snapshot['output_usd_per_million'])) {
            throw ValidationException::withMessages(['model' => 'The reserved API pricing is unavailable.']);
        }
        $usage = $this->normalizeUsage($usage);
        if (! isset($usage['prompt_tokens'], $usage['completion_tokens'])) {
            throw ValidationException::withMessages(['usage' => 'The provider did not report valid usage. The reservation remains held.']);
        }
        $actual = $this->usageCost(
            $this->snapshotRates($snapshot),
            $usage['prompt_tokens'],
            $usage['completion_tokens'],
            $usage['cache_read_tokens'],
            $usage['cache_write_tokens'],
        );
        if (! Wallet::settle($userId, $reservation, $actual, [
            'service' => $service,
            'model' => $model,
            'meter' => 'request',
            'quantity' => $usage['total_tokens'],
            'description' => $this->ledgerLabel($service)." settlement: {$model}",
            'metadata' => ['rate_snapshot' => $snapshot],
        ])) {
            throw ValidationException::withMessages(['wallet' => 'Insufficient usage balance for final settlement.']);
        }

        return $actual;
    }

    /**
     * Output tokens the member's Saldo AI covers after the estimated input:
     * min(desired, floor((balance - inputCost) / outputRatePerToken)). Throws when that is below
     * the minimum output budget.
     */
    public function affordableOutputTokens(int $userId, string $model, int $estimatedInputTokens, int $desiredOutputTokens, int $minimumOutputTokens = 256): int
    {
        $rates = $this->apiRates($model);
        $balance = Wallet::balance($userId);
        $budget = $balance - $this->usageCost($rates, $estimatedInputTokens, 0);
        $affordable = match (true) {
            $budget < 0 => -1,
            $rates['output_tokens'] <= 0 => $desiredOutputTokens,
            default => (int) min($desiredOutputTokens, floor(round($budget / $rates['output_tokens'], 8))),
        };
        // A reservation for the returned count must fit the same balance despite float division.
        while ($affordable > 0 && $this->usageCost($rates, $estimatedInputTokens, $affordable) > $balance) {
            $affordable--;
        }
        $required = $minimumOutputTokens;
        if ($affordable < $required) {
            throw InsufficientBalanceException::wallet($this->usageCost($rates, $estimatedInputTokens, max(0, $required)), $balance);
        }

        return $affordable;
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

    /**
     * Active API rates in USD per 1M tokens (= micro-USD per token). Input and output are required;
     * cache meters are optional and null when unpriced.
     *
     * @return array{input_tokens: float, output_tokens: float, cache_read: ?float, cache_write: ?float}
     */
    private function apiRates(string $model): array
    {
        $rates = UsageRate::activeForModel('api', $model)
            ->map(fn (UsageRate $rate): ?float => $rate->price_usd === null || (float) $rate->price_usd < 0 ? null : (float) $rate->price_usd);
        if ($rates->get('input_tokens') === null || $rates->get('output_tokens') === null) {
            throw ValidationException::withMessages([
                'model' => "Active API input and output pricing is unavailable for {$model}.",
            ]);
        }

        return [
            'input_tokens' => $rates->get('input_tokens'),
            'output_tokens' => $rates->get('output_tokens'),
            'cache_read' => $rates->get('cache_read'),
            'cache_write' => $rates->get('cache_write'),
        ];
    }

    private function rateSnapshot(array $rates): array
    {
        return [
            'input_usd_per_million' => $rates['input_tokens'],
            'output_usd_per_million' => $rates['output_tokens'],
            'cache_read_usd_per_million' => $rates['cache_read'],
            'cache_write_usd_per_million' => $rates['cache_write'],
        ];
    }

    /** Rates held by a reservation; snapshots taken before cache pricing bill cache tokens at the input rate. */
    private function snapshotRates(array $snapshot): array
    {
        $optional = static fn (mixed $rate): ?float => is_numeric($rate) && (float) $rate >= 0 ? (float) $rate : null;

        return [
            'input_tokens' => (float) $snapshot['input_usd_per_million'],
            'output_tokens' => (float) $snapshot['output_usd_per_million'],
            'cache_read' => $optional($snapshot['cache_read_usd_per_million'] ?? null),
            'cache_write' => $optional($snapshot['cache_write_usd_per_million'] ?? null),
        ];
    }

    /**
     * Micro-USD for token usage: uncached input at the input rate, cache reads and writes at their own
     * rates (the input rate when unpriced) and output at the output rate, each meter rounded up.
     */
    private function usageCost(array $rates, int $promptTokens, int $completionTokens, int $cacheReadTokens = 0, int $cacheWriteTokens = 0): int
    {
        $input = $rates['input_tokens'];
        $cost = 0;
        foreach ([
            [max(0, $promptTokens - $cacheReadTokens - $cacheWriteTokens), $input],
            [$cacheReadTokens, $rates['cache_read'] ?? $input],
            [$cacheWriteTokens, $rates['cache_write'] ?? $input],
            [$completionTokens, $rates['output_tokens']],
        ] as [$tokens, $rate]) {
            // round() first so float drift such as 6000 x 0.67 = 4020.0000000000005 cannot add a micro-USD.
            $meterCost = ceil(round($rate * max(0, $tokens), 8));
            if (! is_finite($meterCost) || $meterCost < 0 || $meterCost >= PHP_INT_MAX) {
                throw ValidationException::withMessages(['usage' => 'The usage cost exceeds supported billing limits.']);
            }
            $cost += (int) $meterCost;
            if (! is_int($cost)) {
                throw ValidationException::withMessages(['usage' => 'The usage cost exceeds supported billing limits.']);
            }
        }

        return $cost;
    }

    private function ledgerLabel(string $service): string
    {
        return $service === 'chat' ? 'Chat' : 'API';
    }

    private function contentTokens(mixed $content): int
    {
        if ($content === null) {
            return 0;
        }
        if (is_string($content)) {
            return $this->textTokens($content);
        }
        if (! is_array($content) || ! array_is_list($content)) {
            return $this->partTokens($content);
        }

        return array_sum(array_map($this->partTokens(...), $content));
    }

    private function partTokens(mixed $part): int
    {
        if (is_string($part)) {
            return $this->textTokens($part);
        }
        $type = is_array($part) ? ($part['type'] ?? null) : null;
        if (in_array($type, ['text', 'input_text', 'output_text'], true) && is_string($part['text'] ?? null)) {
            return $this->textTokens($part['text']);
        }
        if (in_array($type, ['image_url', 'image', 'input_image'], true)) {
            return self::IMAGE_TOKENS;
        }
        if (in_array($type, ['document', 'file', 'input_file'], true)) {
            $text = $this->documentText($part);

            return $text === null ? self::BINARY_DOCUMENT_TOKENS : $this->textTokens($text);
        }

        // tool_use, tool_result, function content and any other part: its JSON length.
        return $this->jsonTokens($part);
    }

    /** Plain text carried by a document part; null when the document is binary (PDF, base64 data, file id). */
    private function documentText(array $part): ?string
    {
        $source = $part['source'] ?? null;
        if (is_array($source) && ($source['type'] ?? null) === 'text' && is_string($source['data'] ?? null)) {
            return $source['data'];
        }

        return is_string($part['text'] ?? null) ? $part['text'] : null;
    }

    private function textTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 3);
    }

    private function jsonTokens(mixed $value): int
    {
        return $this->textTokens((string) json_encode($value, self::JSON_FLAGS));
    }

    private static function tokenCount(mixed $value): ?int
    {
        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($count) ? $count : null;
    }
}
