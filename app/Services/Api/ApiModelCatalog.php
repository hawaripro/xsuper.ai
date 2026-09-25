<?php

namespace App\Services\Api;

use App\Models\AiModelProfile;
use App\Models\ApiKey;
use App\Models\UsageRate;
use App\Services\AiProxyService;

final class ApiModelCatalog
{
    public function __construct(private readonly AiProxyService $proxy) {}

    public function models(?ApiKey $key = null): array
    {
        $available = collect($this->proxy->getModels())->keyBy('id');
        $ids = array_values(array_intersect(UsageRate::sellableModelIds(), $available->keys()->all()));
        if ($key?->allowed_models) {
            $ids = array_values(array_intersect($ids, $key->allowed_models));
        }
        $rates = UsageRate::active()->where('service', 'api')->whereIn('model', $ids)->get()->groupBy('model');

        return AiModelProfile::query()->whereIn('model_id', $ids)->orderBy('display_name')->get()->map(function (AiModelProfile $model) use ($available, $rates): array {
            $meters = $rates->get($model->model_id)->keyBy('meter');
            $price = static fn (string $meter): ?float => $meters->get($meter)?->price_usd !== null ? (float) $meters->get($meter)->price_usd : null;

            return [
                'id' => $model->model_id, 'object' => 'model', 'created' => $model->created_at->timestamp, 'owned_by' => 'xsuper',
                'name' => $available[$model->model_id]['name'], 'context_length' => $model->context_window,
                'pricing' => [
                    'input_usd_per_million' => $price('input_tokens'), 'output_usd_per_million' => $price('output_tokens'),
                    'cache_read_usd_per_million' => $price('cache_read'), 'cache_write_usd_per_million' => $price('cache_write'),
                ],
            ];
        })->all();
    }
}
