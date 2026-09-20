<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiProxyException;
use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\UsageRate;
use App\Models\VideoJob;
use App\Services\AiProxyService;
use App\Services\AuditService;
use App\Services\FalProtocol;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiCatalogController extends Controller
{
    public function storeModel(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'model_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\/:\-]+$/', 'unique:ai_model_profiles,model_id'],
            ...$this->modelRules(false),
        ]);

        $rates = $validated['rates'] ?? [];
        unset($validated['rates'], $validated['provider_slug']);

        $model = DB::transaction(function () use ($audit, $request, $validated, $rates): AiModelProfile {
            $provider = AiProviderProfile::query()->where('slug', $request->input('provider_slug'))->lockForUpdate()->firstOrFail();
            if ($provider->base_url !== null && empty($validated['upstream_model_id'])) {
                throw ValidationException::withMessages(['upstream_model_id' => 'Enter an upstream model ID for this provider.']);
            }
            $this->assertUpstreamAvailable($provider->id, $validated['upstream_model_id'] ?? $validated['model_id']);

            $model = new AiModelProfile;
            $model->fill(array_merge($validated, [
                'provider_id' => $provider->id,
                'provider_name' => $validated['provider_name'] ?? $provider->name,
                'is_enabled' => (bool) ($validated['is_enabled'] ?? false),
                'sort_order' => $validated['sort_order'] ?? 0,
                // A manual add is the admin asserting the model exists upstream; the
                // next provider sync re-verifies and flips this off if it is missing.
                'is_available' => true,
                'last_seen_at' => now(),
            ]));
            $this->normalizeGenerationConfig($model);
            $this->saveModel($model);

            $this->syncModelRates($model, $rates);
            $modelRates = UsageRate::query()->where('model', $model->model_id)->get();
            UsageRate::assertValidState($modelRates);
            $audit->record($request->user(), 'ai_model.created', $model, [
                'after' => $this->modelPayload($model->fresh('provider'), $modelRates),
            ]);

            return $model;
        });

        Cache::forget('public-model-catalog-v3');

        $modelRates = UsageRate::query()->where('model', $model->model_id)->get();

        return response()->json([
            'model' => $this->modelPayload($model->fresh('provider'), $modelRates),
        ], 201);
    }

    public function index(): JsonResponse
    {
        $providers = AiProviderProfile::query()->withCount('models')->orderBy('name')->get();
        $rates = UsageRate::query()
            ->get()
            ->groupBy('model');
        $models = AiModelProfile::query()
            ->with('provider')
            ->orderBy('category')
            ->orderBy('display_name')
            ->get();

        return response()->json([
            'providers' => $providers->map(fn (AiProviderProfile $provider): array => $this->providerPayload($provider))->all(),
            'models' => $models->map(fn (AiModelProfile $model): array => $this->modelPayload($model, $rates->get($model->model_id, collect())))->all(),
        ]);
    }

    public function sync(Request $request, AiProxyService $proxy, AuditService $audit, AiProviderProfile $provider): JsonResponse
    {
        $connection = Arr::only($provider->getRawOriginal(), ['base_url', 'api_key', 'protocol', 'api_version']);

        try {
            $models = collect($proxy->fetchCatalog($provider))->keyBy('id')->values()->all();
        } catch (AiProxyException $exception) {
            $status = $exception->responseStatus();
            $message = $status === 503 ? 'The provider connection is unavailable.' : 'The provider request failed.';
            $provider = DB::transaction(function () use ($audit, $status, $message, $provider, $connection, $request): AiProviderProfile {
                $provider = $this->lockConnection($provider, $connection);
                $provider->update([
                    'status' => $status === 503 ? 'unavailable' : 'error',
                    'last_checked_at' => now(),
                    'last_error' => $message,
                ]);
                $audit->record($request->user(), 'ai_catalog.sync_failed', $provider, [
                    'status' => $provider->status,
                ]);

                return $provider;
            });

            return response()->json([
                'message' => $message,
                'provider' => $this->providerPayload($provider->fresh()),
            ], $status);
        }

        // Discovered models used to land as unpublished drafts for every
        // admin-added provider (base_url is required there), so a provider could
        // read "Terhubung" while the workspace picker stayed empty. Publish by
        // default and let the caller opt out explicitly.
        $publish = $request->boolean('publish', true);

        $provider = DB::transaction(function () use ($audit, $models, $provider, $connection, $request, $publish): AiProviderProfile {
            $provider = $this->lockConnection($provider, $connection);
            $provider->update([
                'status' => 'healthy',
                'capabilities' => collect($models)->pluck('category')->unique()->values()->all(),
                'last_checked_at' => now(),
                'last_error' => null,
            ]);
            $seenIds = [];
            foreach ($models as $metadata) {
                $model = AiModelProfile::query()->where('provider_id', $provider->id)
                    ->where('upstream_identity', $metadata['id'])->first();
                if ($model) {
                    $model = AiModelProfile::query()->whereKey($model->id)
                        ->where('provider_id', $provider->id)->where('upstream_identity', $metadata['id'])
                        ->lockForUpdate()->first();
                }
                if (! $model) {
                    $model = new AiModelProfile([
                        'provider_id' => $provider->id,
                        'model_id' => $this->publicModelId($provider, $metadata['id']),
                        'upstream_model_id' => $metadata['id'],
                        'display_name' => $metadata['name'],
                        'provider_name' => $metadata['provider'] ?? $metadata['owned_by'] ?? $provider->name,
                        'is_enabled' => $publish,
                        'category' => $metadata['category'],
                        'capabilities' => $metadata['capabilities'],
                        'context_window' => $metadata['context_length'] ?? $metadata['context_window'] ?? null,
                        'max_output_tokens' => $metadata['max_output_tokens'] ?? null,
                        'input_modalities' => $metadata['input_modalities'] ?? ['text'],
                        'output_modalities' => $metadata['output_modalities'] ?? ['text'],
                    ]);
                }
                $model->fill(['is_available' => true, 'last_seen_at' => now()]);
                // Media models are billed per result via token_cost; without one they
                // stay hidden from the workspace even when categorized + available.
                // Seed a default so a synced media model is immediately usable.
                $mediaDefaults = config('media_tools.default_generation_tokens', []);
                if (in_array($model->category, ['image', 'video', 'audio'], true) && empty($model->token_cost) && ! empty($mediaDefaults[$model->category])) {
                    $model->token_cost = (int) $mediaDefaults[$model->category];
                }
                $this->saveSyncedModel($model, $provider);
                $seenIds[] = $model->id;
            }
            $missingModels = AiModelProfile::query()->where('provider_id', $provider->id);
            if ($seenIds !== []) {
                $missingModels->whereNotIn('id', $seenIds);
            }
            $missingModels->update(['is_available' => false]);

            // Price the freshly discovered chat models straight away. Without
            // this they arrive published but unbillable, which is exactly the
            // state that made a connected provider look broken in the workspace.
            $priced = app(\App\Services\ModelAutoPricer::class)->price(
                AiModelProfile::query()->whereIn('id', $seenIds)->get(),
                1.0,
                16000,
                false,
                $request->user(),
                'ai_catalog.rate_seeded',
            );

            $audit->record($request->user(), 'ai_catalog.synced', $provider, [
                'models_synced' => count($models),
                'rates_seeded' => $priced,
            ]);

            return $provider;
        });

        Cache::forget('public-model-catalog-v3');

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
        $validated = $request->validate($this->modelRules());
        abort_if($validated === [], 422, 'No model changes provided.');

        DB::transaction(function () use ($audit, $model, $request, $validated): void {
            $model = AiModelProfile::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $rates = UsageRate::query()->where('model', $model->model_id)->orderBy('id')->lockForUpdate()->get();
            $before = $this->modelPayload($model->load('provider'), $rates);
            $this->applyModelChanges($model, $validated);
            $afterRates = UsageRate::query()->where('model', $model->model_id)->get();
            UsageRate::assertValidState($afterRates);
            $audit->record($request->user(), 'ai_model.updated', $model, [
                'before' => $before,
                'after' => $this->modelPayload($model->fresh('provider'), $afterRates),
            ]);
        });

        Cache::forget('public-model-catalog-v3');

        $modelRates = UsageRate::query()->where('model', $model->model_id)->get();

        return response()->json(['model' => $this->modelPayload($model->fresh('provider'), $modelRates)]);
    }

    public function bulkUpdateModels(Request $request, AuditService $audit): JsonResponse
    {
        $allowed = ['display_name', 'category', 'token_cost', 'generation_config', 'is_enabled', 'sort_order', 'rates'];
        $rules = array_filter($this->modelRules(), fn (string $field): bool => in_array(explode('.', $field)[0], $allowed, true), ARRAY_FILTER_USE_KEY);
        $itemRules = ['id' => ['required', 'integer', 'min:1', 'distinct']];
        if ($request->has('items')) {
            $validation = [
                'items' => ['required', 'array', 'list', 'min:1', 'max:200'],
                'items.*' => ['required', 'array:id,'.implode(',', $allowed)],
                'ids' => ['prohibited'], 'changes' => ['prohibited'],
            ];
            foreach ([...$itemRules, ...$rules] as $field => $rule) {
                $validation['items.*.'.$field] = $rule;
            }
            $items = $request->validate($validation)['items'];
        } else {
            $validation = [
                'ids' => ['required', 'array', 'list', 'min:1', 'max:200'],
                'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
                'changes' => ['required', 'array:'.implode(',', $allowed), 'min:1'],
            ];
            foreach ($rules as $field => $rule) {
                $validation['changes.'.$field] = $rule;
            }
            $validated = $request->validate($validation);
            $items = array_map(fn ($id): array => ['id' => (int) $id, ...$validated['changes']], $validated['ids']);
        }
        foreach ($items as $index => $item) {
            if (count($item) < 2) {
                throw ValidationException::withMessages(["items.{$index}" => 'Provide at least one model change.']);
            }
        }

        $models = DB::transaction(function () use ($request, $audit, $items) {
            $ids = array_column($items, 'id');
            $models = AiModelProfile::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->assertExactCount($models->count(), count($ids));
            $rates = UsageRate::query()->whereIn('model', $models->pluck('model_id'))->orderBy('id')->lockForUpdate()->get()->groupBy('model');
            $before = [];
            foreach ($items as $index => $item) {
                $model = $models->get($item['id']);
                $before[$model->id] = $this->modelPayload($model->load('provider'), $rates->get($model->model_id, []));
                $this->applyModelChanges($model, $item, "items.{$index}.rates");
            }
            $afterRates = UsageRate::query()->whereIn('model', $models->pluck('model_id'))->get()->groupBy('model');
            foreach ($items as $index => $item) {
                $model = $models->get($item['id']);
                UsageRate::assertValidState($afterRates->get($model->model_id, []), "items.{$index}.rates");
            }

            return $models->values()->map(function (AiModelProfile $model) use ($request, $audit, $before, $afterRates): array {
                $payload = $this->modelPayload($model, $afterRates->get($model->model_id, []));
                $audit->record($request->user(), 'ai_model.updated', $model, ['before' => $before[$model->id], 'after' => $payload, 'bulk' => true]);

                return $payload;
            });
        });
        Cache::forget('public-model-catalog-v3');

        return response()->json(['models' => $models, 'updated_count' => $models->count()]);
    }

    public function bulkDestroyModels(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'expected_count' => ['required', 'integer', 'min:1', 'max:200'],
            'delete_usage_rates' => ['required', 'accepted'],
        ]);
        $this->assertExactCount(count($validated['ids']), (int) $validated['expected_count']);
        $result = DB::transaction(function () use ($request, $audit, $validated): array {
            $models = AiModelProfile::query()->whereKey($validated['ids'])->orderBy('id')->lockForUpdate()->get();
            $this->assertExactCount($models->count(), (int) $validated['expected_count']);
            $modelIds = $models->pluck('model_id');
            $rates = UsageRate::query()->whereIn('model', $modelIds)->orderBy('id')->lockForUpdate()->get();
            foreach ([ImageJob::class, VideoJob::class, AudioJob::class] as $jobClass) {
                $busy = $jobClass::query()->whereIn('model', $modelIds)
                    ->where(fn ($query) => $query->whereIn('status', ['pending', 'processing'])->orWhere('billing_status', 'reserved'))
                    ->orderBy('id')->lockForUpdate()->first();
                if ($busy) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Selected models have active or unreconciled media jobs. Finish or refund those jobs before deleting.',
                    ], 409));
                }
            }
            foreach ($models as $model) {
                $audit->record($request->user(), 'ai_model.deleted', $model, [
                    'model_id' => $model->model_id,
                    'deleted_usage_rate_ids' => $rates->where('model', $model->model_id)->pluck('id')->values()->all(),
                    'bulk' => true,
                ]);
            }
            UsageRate::query()->whereKey($rates->pluck('id'))->delete();
            AiModelProfile::query()->whereKey($models->pluck('id'))->delete();

            return ['deleted_count' => $models->count(), 'deleted_usage_rates' => $rates->count(), 'ids' => $models->pluck('id')->all()];
        });
        Cache::forget('public-model-catalog-v3');

        return response()->json($result);
    }

    private function assertExactCount(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new HttpResponseException(response()->json([
                'message' => 'The selected rows changed. Refresh and confirm the exact selection again.',
            ], 409));
        }
    }

    private function lockConnection(AiProviderProfile $provider, array $connection): AiProviderProfile
    {
        $provider = AiProviderProfile::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();
        if (Arr::only($provider->getRawOriginal(), array_keys($connection)) !== $connection) {
            throw new HttpResponseException(response()->json(['message' => 'The provider connection changed. Sync it again.'], 409));
        }

        return $provider;
    }

    private function publicModelId(AiProviderProfile $provider, string $upstreamId, int $attempt = 0): string
    {
        if ($attempt === 0 && strlen($upstreamId) <= 120) {
            return $upstreamId;
        }
        $base = $provider->slug.'/'.$upstreamId;
        if ($attempt === 1 && strlen($base) <= 120) {
            return $base;
        }
        $suffix = substr(hash('sha256', $provider->slug."\0".$upstreamId."\0".$attempt), 0, 12);

        return substr($base, 0, 107).'-'.$suffix;
    }

    private function saveSyncedModel(AiModelProfile $model, AiProviderProfile $provider): void
    {
        if ($model->exists) {
            $this->saveModel($model);

            return;
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $model->model_id = $this->publicModelId($provider, $model->upstream_model_id, $attempt);
            try {
                // A failed PostgreSQL insert must roll back to a savepoint before retrying.
                DB::transaction(fn () => $model->save());

                return;
            } catch (UniqueConstraintViolationException $exception) {
                $field = $this->uniqueModelField($exception);
                if ($field === 'upstream_model_id') {
                    throw ValidationException::withMessages(['upstream_model_id' => 'This upstream model is already assigned to this provider.']);
                }
                if ($field !== 'model_id') {
                    throw $exception;
                }
            }
        }
        throw new HttpResponseException(response()->json(['message' => 'The catalog changed during synchronization. Sync it again.'], 409));
    }

    private function assertUpstreamAvailable(?int $providerId, string $upstreamId, ?int $exceptId = null): void
    {
        if ($providerId === null) {
            return;
        }
        $query = AiModelProfile::query()->where('provider_id', $providerId)->where('upstream_identity', $upstreamId);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['upstream_model_id' => 'This upstream model is already assigned to this provider.']);
        }
    }

    private function saveModel(AiModelProfile $model): void
    {
        try {
            $model->save();
        } catch (UniqueConstraintViolationException $exception) {
            $field = $this->uniqueModelField($exception);
            if ($field === 'upstream_model_id') {
                throw ValidationException::withMessages(['upstream_model_id' => 'This upstream model is already assigned to this provider.']);
            }
            if ($field === 'model_id') {
                throw ValidationException::withMessages(['model_id' => 'This public model ID is already in use.']);
            }
            throw $exception;
        }
    }

    private function uniqueModelField(UniqueConstraintViolationException $exception): ?string
    {
        $constraints = [
            'ai_models_provider_upstream_unique' => 'upstream_model_id',
            'ai_model_profiles_model_id_unique' => 'model_id',
        ];
        if ($exception->index !== null) {
            return $constraints[$exception->index] ?? null;
        }
        if ($exception->columns !== []) {
            return match ($exception->columns) {
                ['provider_id', 'upstream_identity'] => 'upstream_model_id',
                ['model_id'] => 'model_id',
                default => null,
            };
        }
        if (($exception->errorInfo[0] ?? null) !== '23505') {
            return null;
        }

        // PostgreSQL translates prose and quotation marks, but not constraint names.
        // Exclude DETAIL values and Laravel's appended SQL bindings from classification.
        $message = strtok((string) ($exception->errorInfo[2] ?? ''), "\r\n") ?: '';
        foreach ($constraints as $constraint => $field) {
            if (preg_match('/["\p{Pi}\p{Pf}]\h*'.preg_quote($constraint, '/').'\h*["\p{Pi}\p{Pf}]/u', $message) === 1) {
                return $field;
            }
        }

        return null;
    }

    private function modelRules(bool $partial = true): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'display_name' => [$required, 'required', 'string', 'max:160'],
            'provider_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'provider_slug' => [$required, 'required', 'string', 'max:64', 'exists:ai_provider_profiles,slug'],
            'upstream_model_id' => ['sometimes', $partial ? 'required' : 'nullable', 'string', 'max:160', 'regex:/^(?!.*:\/\/)[A-Za-z0-9._\/:\-]+$/D'],
            'category' => [$required, 'required', 'string', 'max:32'],
            'description_id' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^(\/|https:\/\/)/'],
            'context_window' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'max_output_tokens' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'token_cost' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'generation_config' => ['sometimes', 'nullable', 'array:'.implode(',', self::CONFIG_KEYS)],
            'generation_config.image_path' => ['sometimes', 'string', 'max:200', $this->relativePathRule()],
            'generation_config.video_path' => ['sometimes', 'string', 'max:200', $this->relativePathRule()],
            'generation_config.video_status_path' => ['sometimes', 'string', 'max:200', $this->relativePathRule(true)],
            'generation_config.sizes' => ['sometimes', 'array', 'list', 'max:20', $this->uniqueOptionsRule()],
            'generation_config.sizes.*' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\-]*$/D'],
            'generation_config.durations' => ['sometimes', 'array', 'list', 'max:20', $this->uniqueOptionsRule()],
            'generation_config.durations.*' => ['required', 'integer', 'min:1', 'max:600'],
            'generation_config.aspect_ratios' => ['sometimes', 'array', 'list', 'max:20', $this->uniqueOptionsRule()],
            'generation_config.aspect_ratios.*' => ['required', 'string', 'regex:/^[1-9][0-9]{0,3}:[1-9][0-9]{0,3}$/D'],
            'generation_config.max_quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'generation_config.supports_size' => ['sometimes', 'boolean'],
            'generation_config.supports_n' => ['sometimes', 'boolean'],
            'generation_config.supports_duration' => ['sometimes', 'boolean'],
            'generation_config.supports_aspect_ratio' => ['sometimes', 'boolean'],
            'generation_config.supports_pro' => ['sometimes', 'boolean'],
            'generation_config.supports_reference_image' => ['sometimes', 'boolean'],
            'generation_config.reference_required' => ['sometimes', 'boolean'],
            'generation_config.reference_model' => ['sometimes', 'nullable', 'string', 'max:160'],
            'generation_config.audio_path' => ['sometimes', 'nullable', 'string', 'max:200', $this->relativePathRule()],
            'generation_config.audio_status_path' => ['sometimes', 'nullable', 'string', 'max:200', $this->relativePathRule(true)],
            'generation_config.audio_kind' => ['sometimes', 'nullable', 'in:speech,music'],
            'generation_config.voices' => ['sometimes', 'array', 'list', 'max:20', $this->uniqueOptionsRule()],
            'generation_config.voices.*' => ['required', 'array:id,label,language'],
            'generation_config.voices.*.id' => ['required', 'string', 'max:40'],
            'generation_config.voices.*.label' => ['required', 'string', 'max:80'],
            'generation_config.voices.*.language' => ['required', 'string', 'max:16'],
            'generation_config.speed_min' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:5'],
            'generation_config.speed_max' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:5'],
            'generation_config.speed_default' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:5'],
            'generation_config.duration_min' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:190'],
            'generation_config.duration_max' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:190'],
            'generation_config.duration_default' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:190'],
            'generation_config.max_characters' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4000'],
            'capabilities' => ['sometimes', 'array', 'list', 'max:20'],
            'capabilities.*' => ['string', 'max:40'],
            'input_modalities' => ['sometimes', 'array', 'list', 'max:10'],
            'input_modalities.*' => ['string', 'max:40'],
            'output_modalities' => ['sometimes', 'array', 'list', 'max:10'],
            'output_modalities.*' => ['string', 'max:40'],
            'badges' => ['sometimes', 'array', 'list', 'max:10'],
            'badges.*' => ['string', 'max:40'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_enabled' => ['sometimes', 'boolean'],
            'rates' => ['sometimes', 'array:'.implode(',', UsageRate::METERS)],
            'rates.*' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }

    private const CONFIG_KEYS = [
        'image_path', 'video_path', 'video_status_path', 'sizes', 'durations', 'aspect_ratios',
        'max_quantity', 'supports_size', 'supports_n', 'supports_duration', 'supports_aspect_ratio',
        'supports_pro', 'supports_reference_image', 'reference_required', 'reference_model',
        'audio_path', 'audio_status_path', 'audio_kind', 'voices',
        'speed_min', 'speed_max', 'speed_default', 'duration_min', 'duration_max', 'duration_default', 'max_characters',
    ];

    private function relativePathRule(bool $status = false): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($status): void {
            if (! is_string($value)) {
                return;
            }
            if ($status && substr_count($value, '{id}') !== 1) {
                $fail('The status path must contain exactly one {id} placeholder.');

                return;
            }
            $path = $status ? str_replace('{id}', 'job-id', $value) : $value;
            if (! preg_match('#^[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#D', $path)
                || array_intersect(explode('/', $path), ['.', '..']) !== []) {
                $fail('Use a relative endpoint path without a host, query, fragment, or traversal.');
            }
        };
    }

    private function uniqueOptionsRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_array($value) && count(array_unique($value, SORT_REGULAR)) !== count($value)) {
                $fail('Each option must be unique within this model.');
            }
        };
    }

    private function normalizeGenerationConfig(AiModelProfile $model): void
    {
        if ($model->isDirty('provider_id')) {
            $model->unsetRelation('provider');
        }
        $falConfig = null;
        if ($model->provider?->protocol === 'fal' && in_array($model->category, ['image', 'video', 'audio'], true)) {
            $upstream = $model->upstream_model_id ?: $model->model_id;
            $falConfig = FalProtocol::mediaConfig($upstream);
            if ($falConfig === null || FalProtocol::MEDIA_MODELS[$upstream] !== $model->category) {
                throw ValidationException::withMessages(['upstream_model_id' => 'This fal media model is not supported.']);
            }
        }
        $config = $model->generation_config;
        if (! is_array($config)) {
            return;
        }
        foreach (['supports_size', 'supports_n', 'supports_duration', 'supports_aspect_ratio', 'supports_pro', 'supports_reference_image', 'reference_required'] as $option) {
            if (array_key_exists($option, $config)) {
                $config[$option] = (bool) $config[$option];
            }
        }
        foreach (['max_quantity', 'duration_min', 'duration_max', 'duration_default', 'max_characters'] as $option) {
            if (isset($config[$option])) {
                $config[$option] = (int) $config[$option];
            }
        }
        if (isset($config['durations'])) {
            $config['durations'] = array_map('intval', $config['durations']);
        }
        foreach (['speed_min', 'speed_max', 'speed_default'] as $option) {
            if (isset($config[$option])) {
                $config[$option] = (float) $config[$option];
            }
        }
        if ($falConfig !== null) {
            foreach ($config as $field => $value) {
                $expected = $falConfig[$field];
                if (in_array($field, ['speed_min', 'speed_max', 'speed_default'], true) && $expected !== null) {
                    $expected = (float) $expected;
                }
                if ($value !== $expected) {
                    throw ValidationException::withMessages(['generation_config' => 'Fal generation settings follow the supported model schema and cannot be overridden.']);
                }
            }
        } else {
            foreach ([
                'supports_pro' => false, 'supports_reference_image' => false, 'reference_required' => false,
                'reference_model' => null, 'audio_path' => null, 'audio_status_path' => null,
                'audio_kind' => null, 'voices' => [], 'speed_min' => null, 'speed_max' => null,
                'speed_default' => null, 'duration_min' => null, 'duration_max' => null,
                'duration_default' => null, 'max_characters' => null,
            ] as $field => $unsupported) {
                if (array_key_exists($field, $config) && $config[$field] !== $unsupported) {
                    throw ValidationException::withMessages(['generation_config' => 'These media capabilities require a supported provider adapter.']);
                }
            }
        }
        $model->generation_config = $config;
    }

    private function applyModelChanges(AiModelProfile $model, array $changes, string $errorKey = 'rates'): void
    {
        $rates = $changes['rates'] ?? [];
        unset($changes['rates'], $changes['id']);
        $oldCategory = $model->category;
        if (array_key_exists('provider_slug', $changes)) {
            $provider = AiProviderProfile::query()->where('slug', $changes['provider_slug'])->firstOrFail();
            if ($model->provider_id !== $provider->id) {
                if (! isset($changes['upstream_model_id'])) {
                    throw ValidationException::withMessages(['upstream_model_id' => 'Enter an upstream model ID when changing providers.']);
                }
                $provider = AiProviderProfile::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();
                $changes['provider_id'] = $provider->id;
                $changes['is_available'] = false;
            }
            unset($changes['provider_slug']);
        }
        if (array_key_exists('upstream_model_id', $changes)
            && $changes['upstream_model_id'] !== ($model->upstream_model_id ?? $model->model_id)) {
            $changes['is_available'] = false;
        }
        if (isset($changes['provider_id']) || array_key_exists('upstream_model_id', $changes)) {
            $this->assertUpstreamAvailable(
                $changes['provider_id'] ?? $model->provider_id,
                $changes['upstream_model_id'] ?? $model->upstream_model_id ?? $model->model_id,
                $model->id,
            );
        }
        if (! array_key_exists('generation_config', $changes)
            && (isset($changes['provider_id']) || array_key_exists('upstream_model_id', $changes))
            && ($provider ?? $model->provider)?->protocol === 'fal') {
            $changes['generation_config'] = null;
        }
        $model->fill($changes);
        $this->normalizeGenerationConfig($model);
        $this->saveModel($model);
        $this->syncModelRates($model, $rates, array_key_exists('is_enabled', $changes), $oldCategory !== $model->category, $errorKey);
    }

    private function rateService(AiModelProfile $model): string
    {
        return in_array($model->category, ['image', 'video', 'audio'], true) ? $model->category : 'api';
    }

    private function syncModelRates(AiModelProfile $model, array $prices, bool $publicationChanged = false, bool $categoryChanged = false, string $errorKey = 'rates'): void
    {
        $service = $this->rateService($model);
        $meters = $service === 'api' ? UsageRate::API_METERS : ['unit'];
        if (array_diff(array_keys($prices), $meters) !== []) {
            throw ValidationException::withMessages([$errorKey => 'Use API token meters for API models and USD unit pricing for image, video or audio models. Generator-token prices are configured separately.']);
        }
        if ($categoryChanged || ($publicationChanged && ! $model->is_enabled)) {
            UsageRate::query()->where('model', $model->model_id)->update(['is_active' => false]);
        }
        foreach ($prices as $meter => $price) {
            $identity = ['service' => $service, 'meter' => $meter, 'model' => $model->model_id];
            if ($price === null) {
                UsageRate::query()->where($identity)->delete();

                continue;
            }
            UsageRate::updateOrCreate($identity, [
                'label' => mb_substr($model->display_name.' '.str_replace('_', ' ', $meter), 0, 160),
                'unit' => $service === 'api' ? '1M tokens' : $service,
                'price_usd' => $price,
                'price_idr' => round((float) $price * 16000, 6),
                'is_active' => $model->is_enabled,
                'sort_order' => $model->sort_order,
            ]);
        }
        if ($publicationChanged || $categoryChanged) {
            UsageRate::query()->where('model', $model->model_id)->where('service', $service)
                ->update(['is_active' => $model->is_enabled]);
        }
    }

    private function providerPayload(AiProviderProfile $provider): array
    {
        return $provider->adminPayload();
    }

    private function modelPayload(AiModelProfile $model, iterable $rates = []): array
    {
        $rateMap = collect($rates)->where('service', $this->rateService($model))->keyBy('meter')->map(fn (UsageRate $rate): array => [
            'id' => $rate->id,
            'price_usd' => $rate->price_usd === null ? null : (float) $rate->price_usd,
            'price_idr' => $rate->price_idr === null ? null : (float) $rate->price_idr,
            'unit' => $rate->unit,
            'is_active' => $rate->is_active,
        ])->all();

        return [
            'id' => $model->id,
            'model_id' => $model->model_id,
            'upstream_model_id' => $model->upstream_model_id ?? $model->model_id,
            'provider_id' => $model->provider_id,
            'provider_slug' => $model->provider?->slug,
            'display_name' => $model->display_name,
            'provider_name' => $model->provider_name ?: $model->provider?->name,
            'category' => $model->category,
            'description_id' => $model->description_id,
            'description_en' => $model->description_en,
            'logo_url' => $model->logo_url,
            'context_window' => $model->context_window,
            'max_output_tokens' => $model->max_output_tokens,
            'token_cost' => $model->token_cost,
            'generation_config' => $model->provider?->protocol === 'fal' && in_array($model->category, ['image', 'video', 'audio'], true)
                ? FalProtocol::mediaConfig($model->upstream_model_id ?: $model->model_id)
                : ($model->generation_config === null ? null : Arr::only($model->generation_config, self::CONFIG_KEYS)),
            'generation_config_readonly' => $model->provider?->protocol === 'fal',
            'is_enabled' => $model->is_enabled,
            'is_available' => $model->is_available,
            'capabilities' => $model->capabilities ?? [],
            'input_modalities' => $model->input_modalities ?? [],
            'output_modalities' => $model->output_modalities ?? [],
            'badges' => $model->badges ?? [],
            'sort_order' => $model->sort_order,
            'last_seen_at' => $model->last_seen_at?->toISOString(),
            'provider' => $model->provider ? [
                'id' => $model->provider->id,
                'slug' => $model->provider->slug,
                'name' => $model->provider->name,
                'status' => $model->provider->status,
                'is_enabled' => $model->provider->is_enabled,
                'capabilities' => $model->provider->capabilities ?? [],
                'last_checked_at' => $model->provider->last_checked_at?->toISOString(),
            ] : null,
            'rates' => $rateMap,
            'rate' => $rateMap['unit'] ?? $rateMap['input_tokens'] ?? null,
        ];
    }
}
