<?php

namespace App\Services\Pricing;

use App\Models\AiProviderProfile;
use App\Models\ModelCost;
use App\Services\Pricing\Sources\CostData;
use App\Services\Pricing\Sources\FalPricingSource;
use App\Services\Pricing\Sources\KinoviDocsSource;
use App\Services\Pricing\Sources\ReferenceCatalogSource;
use App\Services\Pricing\Sources\RunwareDocsSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CostCollector
{
    public function __construct(
        private readonly ReferenceCatalogSource $reference,
        private readonly RunwareDocsSource $runware,
        private readonly FalPricingSource $fal,
        private readonly KinoviDocsSource $kinovi,
    ) {}

    public function refresh(?AiProviderProfile $provider = null): array
    {
        $summary = ['ok' => 0, 'estimate' => 0, 'unknown' => 0, 'manual_skipped' => 0];
        $providers = AiProviderProfile::query()->when($provider, fn ($q) => $q->whereKey($provider->id))->get();
        foreach ($providers as $connection) {
            $counts = $this->refreshModels($connection, $connection->models()->with(['cost', 'provider', 'capabilityRevisions'])->get());
            foreach ($summary as $key => $_) {
                $summary[$key] += $counts[$key];
            }
        }
        return $summary;
    }

    /** Sync refreshes new models only; existing manual and automatically curated prices stay intact. */
    public function refreshModels(AiProviderProfile $provider, Collection $models): array
    {
        $models->loadMissing(['cost', 'provider', 'capabilityRevisions']);
        $summary = ['ok' => 0, 'estimate' => 0, 'unknown' => 0, 'manual_skipped' => $models->filter(fn ($model) => $model->cost?->isManual())->count()];
        $models = $models->reject(fn ($model) => $model->cost?->isManual());
        $chat = $models->where('category', 'chat');
        $media = $models->where('category', '!=', 'chat');
        $runwareChat = $provider->protocol === 'openai' && strtolower((string) parse_url($provider->base_url, PHP_URL_HOST)) === 'api.runware.ai';
        $costs = $runwareChat ? $this->runware->collect($provider, $chat) : $this->reference->collect($provider, $chat);
        $costs += match ($provider->protocol) {
            'fal' => $this->fal->collect($provider, $media),
            'runware' => $this->runware->collect($provider, $media),
            'kinovi' => $this->kinovi->collect($provider, $media),
            default => $media->mapWithKeys(fn ($model) => [$model->id => CostData::unknown('reference', 'No automatic media price source; enter a verified manual cost.', null, $provider->cost_currency ?? 'usd')])->all(),
        };
        foreach ($costs as $id => $attributes) {
            DB::transaction(function () use ($id, $attributes, &$summary): void {
                // Lock the model before its unique cost row, including the missing-row case.
                \App\Models\AiModelProfile::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $cost = ModelCost::query()->where('ai_model_profile_id', $id)->lockForUpdate()->first();
                if ($cost?->isManual()) {
                    $summary['manual_skipped']++;
                    return;
                }
                $cost ??= new ModelCost(['ai_model_profile_id' => $id]);
                $cost->fill($attributes)->save();
                $summary[$attributes['status']]++;
            });
        }
        return $summary;
    }
}
