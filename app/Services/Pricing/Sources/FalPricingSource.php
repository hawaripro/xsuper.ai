<?php

namespace App\Services\Pricing\Sources;

use App\Models\AiProviderProfile;
use App\Services\MediaModelConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

final class FalPricingSource
{
    public function collect(AiProviderProfile $provider, Collection $models): array
    {
        $endpoints = [];
        foreach ($models as $model) {
            $native = MediaCostBounds::native($model);
            $ids = MediaCostBounds::revisions($model)->map(fn ($revision) => $revision->source_metadata['endpoint_id'] ?? null)->filter()->values()->all();
            if ($ids === []) {
                $ids[] = $model->upstream_model_id ?: $model->model_id;
            }
            $endpoints[$model->id] = array_values(array_unique(array_filter([...$ids, $native['reference_model'] ?? null])));
        }
        $prices = [];
        foreach (array_chunk(array_values(array_unique(array_merge([], ...array_values($endpoints)))), 50) as $batch) {
            try {
                $response = Http::acceptJson()->withHeaders(['Authorization' => 'Key '.$provider->api_key])->timeout(30)
                    ->get(config('pricing.fal_pricing_url'), ['endpoint_id' => implode(',', $batch)]);
                if ($response->successful()) {
                    foreach ($response->json('prices', []) as $row) {
                        if (is_string($row['endpoint_id'] ?? null)) {
                            $prices[$row['endpoint_id']][] = $row;
                        }
                    }
                }
            } catch (ConnectionException) {
                // An unavailable metadata source never licenses a guessed sale price.
            }
        }
        $result = [];
        foreach ($models as $model) {
            $unit = MediaModelConfig::catalogPriceUnit($model);
            $cost = CostData::unknown('fal_api', 'No safely mappable fal price for every selectable endpoint/configuration.', $endpoints[$model->id][0]);
            $values = [];
            $notes = [];
            $unknown = false;
            foreach ($endpoints[$model->id] as $id) {
                if (empty($prices[$id])) {
                    $unknown = true;
                    break;
                }
                foreach ($prices[$id] as $price) {
                    $providerUnit = strtolower(trim((string) ($price['unit'] ?? '')));
                    $factor = match ($providerUnit) {
                        'second', 'seconds', 'video second', 'video_second' => $unit === 'second' ? 1 : MediaCostBounds::duration($model),
                        'megapixel', 'megapixels' => ($mp = MediaCostBounds::megapixels($model)) === null ? null : ceil($mp),
                        'image', 'images', 'generation', 'request', 'video', 'videos' => $unit === 'second'
                            ? (($seconds = MediaCostBounds::duration($model, true)) > 0 ? 1 / $seconds : null) : 1,
                        default => null,
                    };
                    if ($factor === null || strtolower($price['currency'] ?? '') !== 'usd' || ! is_numeric($price['unit_price'] ?? null) || $price['unit_price'] <= 0) {
                        $unknown = true;
                        break 2;
                    }
                    // Fixed-duration provider tiers cover the shortest selectable output as well.
                    // Explicit Pro prices are divided by the same ×2 charged by native execution.
                    $proDivisor = ($price['pro'] ?? false) === true && (MediaCostBounds::native($model)['supports_pro'] ?? false) ? 2 : 1;
                    $values[] = (float) $price['unit_price'] * $factor / $proDivisor;
                    $notes[] = $providerUnit.' × '.round($factor, 6).($proDivisor > 1 ? ' / Pro ×2' : '');
                }
            }
            if (! $unknown && $values !== []) {
                $cost = [...$cost, 'status' => 'ok', 'unit' => $unit, 'unit_cost' => number_format(max($values), 10, '.', ''),
                    'basis_note' => mb_substr('Worst selectable endpoint/tier per '.$unit.': '.implode('; ', array_unique($notes)).'. Fixed video prices use the shortest selectable duration; megapixels round up. No GPU/compute conversion.', 0, 500)];
            }
            $result[$model->id] = $cost;
        }
        return $result;
    }
}
