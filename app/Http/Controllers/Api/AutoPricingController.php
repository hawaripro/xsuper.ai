<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ModelCost;
use App\Models\PricingRun;
use App\Services\AuditService;
use App\Services\MediaModelConfig;
use App\Services\Pricing\CostCollector;
use App\Services\Pricing\PricingApplier;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\Sources\CostData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AutoPricingController extends Controller
{
    public function index(PricingEngine $engine): JsonResponse
    {
        try {
            $revenue = $engine->tokenRevenueIdr();
            $error = null;
        } catch (ValidationException $exception) {
            $revenue = null;
            $error = collect($exception->errors())->flatten()->first();
        }
        return response()->json(['settings' => $engine->settings(), 'token_revenue_idr' => $revenue, 'media_error' => $error,
            'providers' => AiProviderProfile::query()->withCount('models')->with('models.cost')->orderBy('name')->get()->map(fn ($provider) => $this->provider($provider, $engine)),
            'last_run' => PricingRun::latest('id')->first()]);
    }

    public function settings(Request $request, PricingEngine $engine, AuditService $audit): JsonResponse
    {
        $values = $engine->validateSettings($request->all());
        DB::transaction(function () use ($values, $engine, $audit, $request): void {
            $settings = $engine->settings();
            $before = $settings->toArray();
            $settings->update($values);
            $audit->record($request->user(), 'pricing.settings.updated', $settings, ['before' => $before, 'after' => $settings->toArray()]);
        });
        $engine->refresh();
        Cache::forget('public-model-catalog-v3');
        return response()->json(['settings' => $engine->settings()]);
    }

    public function providerCost(Request $request, AiProviderProfile $provider, PricingEngine $engine, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'cost_currency' => ['required', Rule::in(['usd', 'credit'])],
            'cost_idr_per_unit' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999.9999', 'prohibited_with:paid_idr,units_received'],
            'paid_idr' => ['required_with:units_received', 'numeric', 'gt:0', 'max:999999999999'],
            'units_received' => ['required_with:paid_idr', 'numeric', 'gt:0', 'max:999999999999'],
            'cost_note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        if (isset($data['paid_idr'], $data['units_received'])) {
            $value = round((float) $data['paid_idr'] / (float) $data['units_received'], 4);
            if ($value <= 0 || $value > 9999999999.9999) {
                throw ValidationException::withMessages(['units_received' => 'The calculated cost must be positive and fit the supported range.']);
            }
            $data['cost_idr_per_unit'] = number_format($value, 4, '.', '');
        }
        unset($data['paid_idr'], $data['units_received']);
        DB::transaction(function () use ($provider, $data, $request, $audit): void {
            $provider = AiProviderProfile::query()->lockForUpdate()->findOrFail($provider->id);
            $before = $provider->only(['cost_currency', 'cost_idr_per_unit', 'cost_note']);
            $provider->update($data);
            $audit->record($request->user(), 'pricing.provider.updated', $provider, ['before' => $before, 'after' => $data]);
        });
        $engine->refresh();
        Cache::forget('public-model-catalog-v3');
        return response()->json(['provider' => $this->provider($provider->fresh()->loadCount('models')->load('models.cost'), $engine)]);
    }

    public function refresh(Request $request, CostCollector $collector, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['provider_id' => ['sometimes', 'nullable', 'integer', 'exists:ai_provider_profiles,id']]);
        $provider = isset($data['provider_id']) ? AiProviderProfile::findOrFail($data['provider_id']) : null;
        $summary = $collector->refresh($provider);
        $audit->record($request->user(), 'pricing.costs.refreshed', $provider, $summary);
        return response()->json(['summary' => $summary]);
    }

    public function preview(Request $request, PricingApplier $applier): JsonResponse
    {
        return response()->json($applier->preview($request->validate([
            'kind' => ['sometimes', 'nullable', Rule::in(['llm', 'media'])],
            'status' => ['sometimes', 'nullable', Rule::in(['ok', 'estimate', 'unknown', 'locked'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:191'], 'page' => ['sometimes', 'integer', 'min:1'],
        ])));
    }

    public function modelCost(Request $request, AiModelProfile $model, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['sometimes', Rule::in(['usd', 'credit'])], 'price_locked' => ['sometimes', 'boolean'],
            'input_per_million' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'output_per_million' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'cache_read_per_million' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'cache_write_per_million' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'unit' => ['sometimes', Rule::in(['generation', 'second', 'request', 'session'])],
            'unit_cost' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999'],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['cost' => 'Enter a manual cost or change the price lock.']);
        }
        $cost = DB::transaction(function () use ($model, $data, $request, $audit): ModelCost {
            $model = AiModelProfile::query()->with(['provider', 'capabilityRevisions'])->lockForUpdate()->findOrFail($model->id);
            $cost = $model->cost()->lockForUpdate()->first() ?? new ModelCost(['ai_model_profile_id' => $model->id, ...CostData::unknown('reference', 'Not collected yet.')]);
            $before = $cost->toArray();
            $manual = array_diff_key($data, ['price_locked' => true]) !== [];
            $cost->fill($data);
            if ($manual) {
                if ($cost->currency !== $model->provider->cost_currency) {
                    throw ValidationException::withMessages(['currency' => 'The cost currency must match the provider billing currency.']);
                }
                if ($model->category === 'chat' && (! ($cost->input_per_million > 0) || ! ($cost->output_per_million > 0))) {
                    throw ValidationException::withMessages(['input_per_million' => 'Enter positive input and output costs together.']);
                }
                if ($model->category !== 'chat' && (! ($cost->unit_cost > 0) || $cost->unit !== MediaModelConfig::catalogPriceUnit($model))) {
                    throw ValidationException::withMessages(['unit_cost' => 'Enter a positive cost in the model sale unit: '.MediaModelConfig::catalogPriceUnit($model).'.']);
                }
                $cost->fill(['source' => 'manual', 'status' => 'ok', 'basis_note' => 'Verified manual cost entered by an administrator.', 'reference_id' => null, 'updated_by' => $request->user()->id]);
            }
            $cost->save();
            $audit->record($request->user(), 'pricing.model.updated', $model, ['before' => $before, 'after' => $cost->toArray()]);
            return $cost;
        });
        return response()->json(['cost' => $cost]);
    }

    public function resetCost(Request $request, AiModelProfile $model, CostCollector $collector, AuditService $audit): JsonResponse
    {
        DB::transaction(function () use ($model, $request, $audit): void {
            AiModelProfile::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $cost = $model->cost()->lockForUpdate()->first();
            if ($cost?->isManual()) {
                $cost->fill(CostData::unknown('reference', 'Manual cost reset; collecting automatic metadata.'))->save();
                $audit->record($request->user(), 'pricing.model.cost_reset', $model);
            }
        });
        $collector->refreshModels($model->provider, new \Illuminate\Database\Eloquent\Collection([$model->fresh()]));
        return response()->json(['cost' => $model->cost()->first()]);
    }

    public function apply(Request $request, PricingApplier $applier): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        $run = $applier->apply($request->user());
        return response()->json(['run_id' => $run->id, 'summary' => $run->summary]);
    }

    private function provider(AiProviderProfile $provider, PricingEngine $engine): array
    {
        $counts = ['ok' => 0, 'estimate' => 0, 'unknown' => 0];
        foreach ($provider->models as $model) {
            $counts[$model->cost?->status ?? 'unknown']++;
        }
        return ['id' => $provider->id, 'name' => $provider->name, 'protocol' => $provider->protocol,
            'cost_currency' => $provider->cost_currency, 'cost_idr_per_unit' => $provider->cost_idr_per_unit,
            'effective_idr_per_unit' => $engine->landedIdr($provider), 'cost_note' => $provider->cost_note,
            'models_count' => $provider->models_count, 'status_counts' => $counts];
    }
}
