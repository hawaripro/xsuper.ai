<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiProxyException;
use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\UsageRate;
use App\Services\AiProxyService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $providers = AiProviderProfile::query()->orderBy('name')->get();
        $imageRates = UsageRate::query()
            ->active()
            ->where('service', 'image')
            ->where('meter', 'unit')
            ->get()
            ->keyBy('model');
        $models = AiModelProfile::query()
            ->with('provider')
            ->orderBy('category')
            ->orderBy('display_name')
            ->get();

        return response()->json([
            'providers' => $providers->map(fn (AiProviderProfile $provider): array => $this->providerPayload($provider))->all(),
            'models' => $models->map(fn (AiModelProfile $model): array => $this->modelPayload($model, $imageRates->get($model->model_id)))->all(),
        ]);
    }

    public function sync(Request $request, AiProxyService $proxy, AuditService $audit): JsonResponse
    {
        $provider = AiProviderProfile::firstOrCreate(
            ['slug' => 'ai-proxy'],
            [
                'name' => 'AI Proxy',
                'status' => 'unknown',
                'is_enabled' => true,
                'capabilities' => [],
            ],
        );

        try {
            $models = $proxy->fetchCatalog();
        } catch (AiProxyException $exception) {
            DB::transaction(function () use ($audit, $exception, $provider, $request): void {
                $provider->update([
                    'status' => $exception->responseStatus() === 503 ? 'unavailable' : 'error',
                    'last_checked_at' => now(),
                    'last_error' => $exception->getMessage(),
                ]);
                $audit->record($request->user(), 'ai_catalog.sync_failed', $provider, [
                    'status' => $provider->status,
                ]);
            });

            return response()->json([
                'message' => $exception->getMessage(),
                'provider' => $this->providerPayload($provider->fresh()),
            ], $exception->responseStatus());
        }

        DB::transaction(function () use ($audit, $models, $provider, $request): void {
            $provider->update([
                'status' => 'healthy',
                'capabilities' => collect($models)->pluck('category')->unique()->values()->all(),
                'last_checked_at' => now(),
                'last_error' => null,
            ]);
            $seenIds = collect($models)->pluck('id')->all();
            $missingModels = AiModelProfile::query()->where('provider_id', $provider->id);
            if ($seenIds !== []) {
                $missingModels->whereNotIn('model_id', $seenIds);
            }
            $missingModels->update(['is_available' => false]);


            $seenIds = collect($models)->pluck('id')->all();
            AiModelProfile::query()
                ->where('provider_id', $provider->id)
                ->when($seenIds !== [], fn ($query) => $query->whereNotIn('model_id', $seenIds))
                ->update(['is_enabled' => false]);

            foreach ($models as $metadata) {
                $model = AiModelProfile::firstOrNew(['model_id' => $metadata['id']]);
                if (! $model->exists) {
                    $model->display_name = $metadata['name'];
                    $model->is_enabled = true;
                }
                $model->fill([
                    'provider_id' => $provider->id,
                    'is_available' => true,
                    'category' => $metadata['category'],
                    'tier' => $metadata['tier'],
                    'capabilities' => $metadata['capabilities'],
                    'last_seen_at' => now(),
                ]);
                $model->save();
            }

            $audit->record($request->user(), 'ai_catalog.synced', $provider, [
                'models_synced' => count($models),
            ]);
        });

        $catalog = $this->index()->getData(true);

        return response()->json([
            'message' => 'AI catalog synchronized.',
            'synced_models' => count($models),
            'provider' => $this->providerPayload($provider->fresh()),
            'models' => $catalog['models'],
        ]);
    }

    public function updateModel(Request $request, AiModelProfile $model, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'display_name' => 'required_without:is_enabled|string|max:160',
            'is_enabled' => 'required_without:display_name|boolean',
        ]);
        $before = [
            'display_name' => $model->display_name,
            'is_enabled' => $model->is_enabled,
        ];
        DB::transaction(function () use ($audit, $before, $model, $request, $validated): void {
            $model->fill($validated)->save();
            $audit->record($request->user(), 'ai_model.updated', $model, [
                'before' => $before,
                'after' => [
                    'display_name' => $model->display_name,
                    'is_enabled' => $model->is_enabled,
                ],
            ]);
        });

        $rate = UsageRate::forMeter('image', 'unit', $model->model_id);

        return response()->json(['model' => $this->modelPayload($model->fresh('provider'), $rate)]);
    }

    private function providerPayload(AiProviderProfile $provider): array
    {
        return [
            'id' => $provider->id,
            'slug' => $provider->slug,
            'name' => $provider->name,
            'status' => $provider->status,
            'is_enabled' => $provider->is_enabled,
            'capabilities' => $provider->capabilities ?? [],
            'last_checked_at' => $provider->last_checked_at?->toISOString(),
        ];
    }

    private function modelPayload(AiModelProfile $model, ?UsageRate $rate = null): array
    {
        return [
            'id' => $model->id,
            'model_id' => $model->model_id,
            'display_name' => $model->display_name,
            'category' => $model->category,
            'tier' => $model->tier,
            'is_enabled' => $model->is_enabled,
            'is_available' => $model->is_available,
            'capabilities' => $model->capabilities ?? [],
            'last_seen_at' => $model->last_seen_at?->toISOString(),
            'provider' => $model->provider ? $this->providerPayload($model->provider) : null,
            'rate' => $rate ? [
                'price_usd' => (float) $rate->price_usd,
                'price_idr' => (float) $rate->price_idr,
                'unit' => $rate->unit,
            ] : null,
        ];
    }
}
