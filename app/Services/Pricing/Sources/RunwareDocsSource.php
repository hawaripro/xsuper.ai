<?php

namespace App\Services\Pricing\Sources;

use App\Models\AiProviderProfile;
use App\Services\MediaModelConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

final class RunwareDocsSource
{
    public function __construct(private readonly ReferenceCatalogSource $reference) {}

    public function collect(AiProviderProfile $provider, Collection $models): array
    {
        $result = [];
        $chat = $models->where('category', 'chat');
        if ($chat->isNotEmpty()) {
            $base = rtrim(config('pricing.runware_docs_base'), '/');
            $index = $this->json($base.'/index.json');
            $entries = $index['models'] ?? $index;
            $ids = collect($entries)->filter(fn ($row) => is_array($row) && is_string($row['id'] ?? null))->pluck('id')->flip();
            $fallback = collect();
            foreach ($chat as $model) {
                $id = $model->upstream_model_id ?: $model->model_id;
                if (! $ids->has($id)) {
                    $fallback->push($model);
                    continue;
                }
                $document = $this->json($base.'/'.implode('/', array_map('rawurlencode', explode('/', $id))).'/schema.json');
                $prices = [];
                foreach ($document['info']['x-pricing']['rates'] ?? [] as $rate) {
                    $meter = ['inputToken' => 'input', 'outputToken' => 'output', 'cachedInputToken' => 'cache_read', 'cacheWriteToken' => 'cache_write'][$rate['unit'] ?? ''] ?? null;
                    if ($meter !== null && is_numeric($rate['amount'] ?? null)) {
                        $prices[$meter] = max((float) ($prices[$meter] ?? 0), (float) $rate['amount']);
                    }
                }
                $result[$model->id] = CostData::llm('runware_docs', $id, $prices);
            }
            $result += $this->reference->collect($provider, $fallback);
        }
        foreach ($models->where('category', '!=', 'chat') as $model) {
            $revision = $model->capabilityRevisions->sortByDesc('id')->first();
            $pricing = $revision?->source_metadata['pricing'] ?? [];
            $unit = MediaModelConfig::catalogPriceUnit($model);
            $cost = CostData::unknown('runware_docs', 'No safely mappable published media cost.', $model->upstream_model_id);
            $rates = $pricing['rates'] ?? [];
            $values = [];
            $unmappable = false;
            // Distinct units are additive; alternative rates of one unit use their worst case.
            foreach ($rates as $rate) {
                $factor = match ($rate['unit'] ?? '') {
                    'output' => $unit === 'second' ? (($seconds = MediaCostBounds::duration($model, true)) > 0 ? 1 / $seconds : null) : 1,
                    'durationSecond' => $unit === 'second' ? 1 : MediaCostBounds::duration($model),
                    'outputMegapixel' => MediaCostBounds::megapixels($model),
                    'step' => MediaCostBounds::maximum(MediaCostBounds::properties($model)['steps'] ?? []),
                    'character' => MediaCostBounds::properties($model)['speech']['properties']['text']['maxLength'] ?? MediaCostBounds::properties($model)['prompt']['maxLength'] ?? null,
                    default => null,
                };
                if ($factor === null || ! is_numeric($rate['amount'] ?? null) || $rate['amount'] <= 0) {
                    $unmappable = true;
                    break;
                }
                $values[$rate['unit']] = max($values[$rate['unit']] ?? 0, (float) $rate['amount'] * $factor);
            }
            if ($values !== [] && ! $unmappable) {
                $cost = [...$cost, 'status' => 'ok', 'unit' => $unit, 'unit_cost' => number_format(array_sum($values), 10, '.', ''),
                    'basis_note' => 'Maximum published rate for each provider unit, scaled to the largest selectable configuration; per '.$unit.'.'];
            } elseif ($rates === [] && ($measured = array_filter(array_column($pricing['measured'] ?? [], 'price'), fn ($p) => is_numeric($p) && $p > 0)) !== []) {
                $cost = [...$cost, 'status' => 'estimate', 'unit' => $unit, 'unit_cost' => number_format(max($measured), 10, '.', ''),
                    'basis_note' => 'Maximum measured Runware run; an estimate, not a guaranteed bound for all configurations.'];
            }
            $result[$model->id] = $cost;
        }
        return $result;
    }

    private function json(string $url): array
    {
        try {
            $response = Http::acceptJson()->timeout(30)->get($url);
            return $response->successful() && is_array($response->json()) ? $response->json() : [];
        } catch (ConnectionException) {
            return [];
        }
    }
}
