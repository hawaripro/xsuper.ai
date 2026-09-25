<?php

namespace App\Services\Pricing;

use App\Models\AiModelProfile;
use App\Models\DurationPackagePrice;
use App\Models\PricingRun;
use App\Models\StorageUpgradePlan;
use App\Models\UsageRate;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MediaModelConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PricingApplier
{
    public function __construct(private readonly PricingEngine $engine, private readonly AuditService $audit) {}

    public function preview(array $filters): array
    {
        $query = $this->query();
        if (($filters['kind'] ?? '') !== '') {
            $query->where('category', $filters['kind'] === 'llm' ? '=' : '!=', 'chat');
        }
        if (($filters['status'] ?? '') === 'locked') {
            $query->whereHas('cost', fn ($q) => $q->where('price_locked', true));
        } elseif (($filters['status'] ?? '') === 'unknown') {
            $query->where(fn ($q) => $q->whereDoesntHave('cost')->orWhereHas('cost', fn ($q) => $q->where('status', 'unknown')));
        } elseif (in_array($filters['status'] ?? '', ['ok', 'estimate'], true)) {
            $query->whereHas('cost', fn ($q) => $q->where('status', $filters['status']));
        }
        if (($filters['q'] ?? '') !== '') {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->where('display_name', 'like', $search)->orWhere('model_id', 'like', $search)->orWhereHas('provider', fn ($q) => $q->where('name', 'like', $search)));
        }
        $page = $query->orderBy('id')->paginate(25, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $rates = UsageRate::query()->where('service', 'api')->whereIn('model', $page->getCollection()->pluck('model_id'))->get()->groupBy('model');
        $rows = $page->getCollection()->map(function (AiModelProfile $model) use ($rates): array {
            $error = null;
            try {
                $next = $this->nextPrice($model);
                $error = $this->costUnitError($model);
            } catch (ValidationException $exception) {
                $next = null;
                $error = collect($exception->errors())->flatten()->first();
            }
            // Current prices are shown in the unit they are charged in; new prices and costs use the target unit.
            $current = $model->category === 'chat'
                ? ($rates->get($model->model_id, collect())->mapWithKeys(fn ($rate) => [$rate->meter => ['usd' => $rate->price_usd, 'idr' => $rate->price_idr, 'active' => $rate->is_active]])->all())
                : ['token_cost' => $model->token_cost, 'unit' => MediaModelConfig::appliedPriceUnit($model)];
            return ['id' => $model->id, 'model_id' => $model->model_id, 'display_name' => $model->display_name,
                'provider_name' => $model->provider->name, 'kind' => $model->category === 'chat' ? 'llm' : 'media',
                'status' => $model->cost?->status ?? 'unknown', 'source' => $model->cost?->source,
                'basis_note' => $model->cost?->basis_note, 'cost' => $model->cost, 'currency' => $model->provider->cost_currency,
                'current_price' => $current, 'new_price' => $next, 'locked' => (bool) $model->cost?->price_locked,
                'error' => $error, 'price_unit' => $model->category === 'chat' ? '1M tokens' : MediaModelConfig::catalogPriceUnit($model)];
        });
        return ['data' => $rows, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'summary' => $this->counts()];
    }

    public function apply(User $actor): PricingRun
    {
        $this->engine->refresh();
        // Validate the media revenue floor before any chunk commits.
        if (AiModelProfile::query()->where('category', '!=', 'chat')->whereHas('cost', fn ($q) => $q->whereIn('status', ['ok', 'estimate'])->where('price_locked', false)->where('unit_cost', '>', 0))->exists()) {
            $this->engine->tokenRevenueIdr();
        }
        $summary = $this->emptySummary();
        $this->query()->orderBy('id')->chunkById(200, function ($models) use ($actor, &$summary): void {
            $counts = $this->applyTo($models, $actor);
            foreach ($summary as $key => $_) {
                $summary[$key] += $counts[$key];
            }
        });
        $run = DB::transaction(function () use ($actor, $summary): PricingRun {
            foreach ([DurationPackagePrice::class, StorageUpgradePlan::class] as $class) {
                foreach ($class::query()->orderBy('id')->lockForUpdate()->get() as $package) {
                    $package->price_usd = number_format($this->engine->packageUsd((int) $package->price_idr), 2, '.', '');
                    if ($package->isDirty()) {
                        $package->save();
                    }
                }
            }
            $settings = $this->engine->settings();
            $run = PricingRun::create(['actor_id' => $actor->id, 'settings' => $settings->toArray(), 'summary' => $summary]);
            $settings->update(['last_applied_at' => now(), 'last_applied_by' => $actor->id]);
            $this->audit->record($actor, 'pricing.auto.applied', $run, $summary);
            return $run;
        });
        Cache::forget('public-model-catalog-v3');
        return $run;
    }

    public function applyTo(Collection $models, User $actor): array
    {
        $summary = $this->emptySummary();
        foreach ($models->pluck('id')->chunk(200) as $ids) {
            DB::transaction(function () use ($ids, $actor, &$summary): void {
                $locked = $this->query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
                foreach ($locked as $model) {
                    if ($model->cost?->price_locked) {
                        $summary['locked']++;
                        continue;
                    }
                    $next = $this->nextPrice($model);
                    if ($model->category === 'chat') {
                        $rates = UsageRate::query()->where('service', 'api')->where('model', $model->model_id)->orderBy('id')->lockForUpdate()->get()->keyBy('meter');
                        foreach (UsageRate::API_METERS as $meter) {
                            $rate = $rates->get($meter);
                            $usd = $next[$meter] ?? null;
                            if ($usd === null) {
                                if ($rate) {
                                    $rate->is_active = false;
                                }
                            } else {
                                $rate ??= new UsageRate(['service' => 'api', 'model' => $model->model_id, 'meter' => $meter]);
                                $rate->fill(['label' => mb_substr($model->display_name.' '.str_replace('_', ' ', $meter), 0, 160), 'unit' => '1M tokens',
                                    'price_usd' => number_format($usd, 8, '.', ''), 'price_idr' => number_format($this->engine->idrForUsd($usd), 6, '.', ''),
                                    'is_active' => $model->is_enabled, 'sort_order' => $rate->sort_order ?? $model->sort_order ?? 0]);
                                $rates->put($meter, $rate);
                            }
                        }
                        UsageRate::assertValidState($rates);
                        foreach ($rates as $rate) {
                            if (! $rate->exists || $rate->isDirty()) {
                                $rate->save();
                                $summary['rates_written']++;
                            }
                        }
                        $summary[$next === null ? 'unknown_chat' : 'llm']++;
                    } elseif ($next !== null) {
                        // The tariff and its unit change together, so a converted video price never bills the old amount per second.
                        $changed = $model->token_cost !== $next['token_cost'] || $model->token_cost_unit !== $next['unit'];
                        if ($changed) {
                            $model->update(['token_cost' => $next['token_cost'], 'token_cost_unit' => $next['unit']]);
                        }
                        foreach ($model->capabilityRevisions()->where('contract_version', 2)->where(fn ($q) => $q->whereNotNull('reviewed_at')->orWhere('status', 'published'))->orderBy('id')->lockForUpdate()->get() as $revision) {
                            $overrides = $revision->curation_overrides ?? [];
                            $pricing = $overrides['pricing'] ?? [];
                            if (($pricing['token_cost'] ?? null) !== $next['token_cost'] || ($pricing['unit'] ?? null) !== $next['unit'] || ! $revision->hasReviewedPrice($next['token_cost'])) {
                                $overrides['pricing'] = [...$pricing, 'token_cost' => $next['token_cost'], 'unit' => $next['unit'], 'variable_configuration' => true,
                                    'reviewed_by' => $actor->id, 'reviewed_at' => now()->toISOString()];
                                if ($revision->operation === 'realtime_video') {
                                    $overrides['pricing']['max_session_seconds'] = max((int) ($pricing['max_session_seconds'] ?? 0), $revision->executionMetadata()['max_session_seconds']);
                                }
                                $revision->update(['curation_overrides' => $overrides, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
                                $changed = true;
                            }
                        }
                        $summary['media']++;
                        $summary['media_written'] += (int) $changed;
                    } else {
                        // Unknown costs and costs in another unit than the target leave the tariff and its unit unchanged.
                        $summary[$this->costUnitError($model) === null ? 'unknown_media' : 'unit_mismatch']++;
                    }
                }
            });
        }
        Cache::forget('public-model-catalog-v3');
        return $summary;
    }

    private function nextPrice(AiModelProfile $model): ?array
    {
        if ($model->cost === null || ! in_array($model->cost->status, ['ok', 'estimate'], true)) {
            return null;
        }
        if ($model->category === 'chat') {
            return $this->engine->llmRates($model->cost, $model->provider);
        }
        $unit = MediaModelConfig::catalogPriceUnit($model);
        if ($model->cost->unit !== $unit) {
            return null;
        }
        $tokens = $this->engine->mediaTokensFor($model->cost, $model->provider);
        return $tokens === null ? null : ['token_cost' => $tokens, 'unit' => $unit];
    }

    /** A known media cost stored in another unit than the target unit cannot price the model. */
    private function costUnitError(AiModelProfile $model): ?string
    {
        $cost = $model->cost;
        if ($model->category === 'chat' || $cost === null || ! in_array($cost->status, ['ok', 'estimate'], true)) {
            return null;
        }
        $unit = MediaModelConfig::catalogPriceUnit($model);
        return $cost->unit === $unit ? null
            : 'The stored cost is per '.($cost->unit ?? 'unspecified unit').', but this model is priced per '.$unit.'. Enter the provider cost per '.$unit.'; the current price stays unchanged.';
    }

    private function query(): Builder
    {
        return AiModelProfile::query()->with(['provider', 'cost', 'capabilityRevisions' => fn ($q) => $q
            ->select(['id', 'ai_model_profile_id', 'operation', 'contract_version', 'status', 'curation_overrides', 'reviewed_at', 'published_at',
                'provider_bindings->adapter as adapter', 'source_metadata->pricing->catalog_unit as catalog_unit'])
            ->selectRaw('source_schema IS NOT NULL as source_backed')]);
    }

    private function emptySummary(): array
    {
        return ['llm' => 0, 'media' => 0, 'unknown_chat' => 0, 'unknown_media' => 0, 'unit_mismatch' => 0, 'locked' => 0, 'rates_written' => 0, 'media_written' => 0];
    }

    private function counts(): array
    {
        $counts = ['total' => AiModelProfile::count(), 'locked' => 0, 'ok' => 0, 'estimate' => 0, 'unknown' => 0];
        foreach (AiModelProfile::query()->with('cost')->get(['id']) as $model) {
            $counts[$model->cost?->status ?? 'unknown']++;
            $counts['locked'] += (int) (bool) $model->cost?->price_locked;
        }
        return $counts;
    }
}
