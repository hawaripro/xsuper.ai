<?php

namespace App\Services\Pricing\Sources;

final class CostData
{
    public static function unknown(string $source, string $note, ?string $reference = null, string $currency = 'usd'): array
    {
        return [
            'source' => $source, 'currency' => $currency, 'status' => 'unknown',
            'input_per_million' => null, 'output_per_million' => null,
            'cache_read_per_million' => null, 'cache_write_per_million' => null,
            'unit' => null, 'unit_cost' => null, 'basis_note' => mb_substr($note, 0, 500),
            'reference_id' => $reference === null ? null : mb_substr($reference, 0, 191), 'fetched_at' => now(),
        ];
    }

    public static function llm(string $source, string $id, array $prices, string $status = 'ok'): array
    {
        $cost = self::unknown($source, $status === 'estimate' ? 'Reference list prices; verify the provider discount or surcharge.' : 'Published Runware price per token × 1,000,000.', $id);
        foreach (['input', 'output', 'cache_read', 'cache_write'] as $meter) {
            $price = $prices[$meter] ?? null;
            $cost[$meter.'_per_million'] = is_numeric($price) && (float) $price > 0 ? number_format((float) $price * 1_000_000, 10, '.', '') : null;
        }
        if ($cost['input_per_million'] !== null && $cost['output_per_million'] !== null) {
            $cost['status'] = $status;
        } else {
            $cost['basis_note'] = 'A positive input and output reference price is required; free or missing prices are not sellable.';
        }

        return $cost;
    }
}
