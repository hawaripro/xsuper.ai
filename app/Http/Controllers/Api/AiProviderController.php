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
use App\Services\AiProviderEndpoint;
use App\Services\AiProxyService;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AiProviderController extends Controller
{
    public function store(Request $request, AiProviderEndpoint $endpoint, AuditService $audit): JsonResponse
    {
        $validated = $this->validateConnection($request, $endpoint);
        if (empty($validated['slug'])) {
            $root = substr(Str::slug($validated['name']) ?: 'provider', 0, 64);
            if ($root === 'ai-proxy') {
                $root = 'ai-proxy-connection';
            }
            $slug = $root;
            $suffix = 2;
            while (AiProviderProfile::query()->where('slug', $slug)->exists()) {
                $ending = '-'.$suffix++;
                $slug = substr($root, 0, 64 - strlen($ending)).$ending;
            }
            $validated['slug'] = $slug;
        }

        try {
            $provider = DB::transaction(function () use ($request, $validated, $audit): AiProviderProfile {
                $provider = AiProviderProfile::create(array_merge($validated, [
                    'is_enabled' => $validated['is_enabled'] ?? true,
                    'status' => 'unknown',
                    'capabilities' => [],
                ]));
                $audit->record($request->user(), 'ai_provider.created', $provider, [
                    'name' => $provider->name,
                    'protocol' => $provider->protocol,
                    'is_enabled' => $provider->is_enabled,
                    'credential_changed' => true,
                ]);

                return $provider;
            });
        } catch (UniqueConstraintViolationException) {
            $this->invalid(['slug' => ['A provider with this slug already exists.']]);
        } catch (QueryException) {
            throw new HttpResponseException(response()->json(['message' => 'The provider connection could not be saved.'], 503));
        }

        Cache::forget('public-model-catalog-v3');

        return response()->json(['provider' => $provider->fresh()->adminPayload()], 201);
    }

    public function update(Request $request, AiProviderProfile $provider, AiProviderEndpoint $endpoint, AuditService $audit): JsonResponse
    {
        try {
            $provider = DB::transaction(function () use ($request, $provider, $endpoint, $audit): AiProviderProfile {
                $provider = AiProviderProfile::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();
                $validated = $this->validateConnection($request, $endpoint, $provider);
                if ($validated === []) {
                    return $provider;
                }
                $connectionChanged = false;
                foreach (['base_url', 'protocol', 'api_version'] as $field) {
                    if (array_key_exists($field, $validated) && $validated[$field] !== $provider->{$field}) {
                        $connectionChanged = true;
                    }
                }
                $requiresKey = (isset($validated['base_url']) && $validated['base_url'] !== $provider->base_url)
                    || (isset($validated['protocol']) && $validated['protocol'] !== $provider->protocol);
                if ($requiresKey && ! isset($validated['api_key'])) {
                    $this->invalid(['api_key' => ['Enter a new API key when changing the provider connection.']]);
                }
                $credentialChanged = isset($validated['api_key']);
                $provider->fill($validated);
                if ($connectionChanged || $credentialChanged) {
                    $provider->status = 'unknown';
                    $provider->last_checked_at = null;
                    $provider->last_error = null;
                    $provider->models()->update(['is_available' => false]);
                }
                $provider->save();
                $audit->record($request->user(), 'ai_provider.updated', $provider, [
                    'name' => $provider->name,
                    'protocol' => $provider->protocol,
                    'is_enabled' => $provider->is_enabled,
                    'credential_changed' => $credentialChanged,
                ]);

                return $provider;
            });
        } catch (QueryException) {
            throw new HttpResponseException(response()->json(['message' => 'The provider connection could not be saved.'], 503));
        }

        Cache::forget('public-model-catalog-v3');

        return response()->json(['provider' => $provider->fresh()->adminPayload()]);
    }

    public function destroy(Request $request, AiProviderProfile $provider, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'delete_models' => ['required', 'accepted'],
            'expected_model_count' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $result = DB::transaction(function () use ($request, $provider, $audit, $validated): array {
                // Allow admitted media jobs to finish their FK check while their model lock blocks deletion.
                $provider = AiProviderProfile::query()->whereKey($provider->id)->lock('for no key update')->firstOrFail();
                $models = $provider->models()->orderBy('id')->lockForUpdate()->get(['id', 'model_id']);
                if ($models->count() !== (int) $validated['expected_model_count']) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'The provider models changed. Refresh and confirm the current model count again.',
                        'code' => 'provider_models_changed',
                    ], 409));
                }

                $modelIds = $models->pluck('model_id');
                $rates = UsageRate::query()->whereIn('model', $modelIds)->orderBy('id')->lockForUpdate()->get(['id']);
                foreach ([ImageJob::class, VideoJob::class, AudioJob::class] as $jobClass) {
                    $busy = $jobClass::query()
                        ->where(function ($query) use ($modelIds, $provider, $jobClass): void {
                            $query->whereIn('model', $modelIds);
                            if (in_array($jobClass, [VideoJob::class, AudioJob::class], true)) {
                                $query->orWhere('provider_id', $provider->id);
                            }
                        })
                        ->where(fn ($query) => $query->whereIn('status', ['pending', 'processing'])->orWhere('billing_status', 'reserved'))
                        ->orderBy('id')->lockForUpdate()->first(['id']);
                    if ($busy) {
                        throw new HttpResponseException(response()->json([
                            'message' => 'This provider has active or unreconciled media jobs. Finish or refund those jobs before deleting.',
                            'code' => 'provider_media_busy',
                        ], 409));
                    }
                }

                // Detach historical provider links without changing media timestamps or snapshots.
                DB::table('video_jobs')->where('provider_id', $provider->id)->update(['provider_id' => null]);
                DB::table('audio_jobs')->where('provider_id', $provider->id)->update(['provider_id' => null]);
                UsageRate::query()->whereKey($rates->modelKeys())->delete();
                AiModelProfile::query()->whereKey($models->modelKeys())->delete();
                $provider->delete();
                $audit->record($request->user(), 'ai_provider.deleted', $provider, [
                    'deleted_model_ids' => $models->modelKeys(),
                    'deleted_usage_rate_ids' => $rates->modelKeys(),
                    'deleted_models' => $models->count(),
                    'deleted_usage_rates' => $rates->count(),
                ]);

                return [
                    'deleted_provider_id' => $provider->id,
                    'deleted_models' => $models->count(),
                    'deleted_usage_rates' => $rates->count(),
                ];
            });
        } catch (QueryException) {
            throw new HttpResponseException(response()->json(['message' => 'The provider connection could not be deleted.'], 503));
        }

        Cache::forget('public-model-catalog-v3');

        return response()->json($result);
    }

    public function check(Request $request, AiProviderProfile $provider, AiProxyService $proxy, AuditService $audit): JsonResponse
    {
        $connection = Arr::only($provider->getRawOriginal(), ['base_url', 'api_key', 'protocol', 'api_version']);
        $status = 200;
        $models = [];
        $message = 'The provider connection is ready.';
        try {
            $models = $proxy->fetchCatalog($provider);
        } catch (AiProxyException $exception) {
            $status = $exception->responseStatus();
            $message = $status === 503 ? 'The provider connection is unavailable.' : ($exception->getMessage() ?: 'The provider request failed.');
        }

        $provider = DB::transaction(function () use ($provider, $connection, $status, $message, $models, $request, $audit): AiProviderProfile {
            $provider = AiProviderProfile::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();
            if (Arr::only($provider->getRawOriginal(), array_keys($connection)) !== $connection) {
                throw new HttpResponseException(response()->json(['message' => 'The provider connection changed. Check it again.'], 409));
            }
            $provider->fill([
                'status' => $status === 200 ? 'healthy' : ($status === 503 ? 'unavailable' : 'error'),
                'last_checked_at' => now(),
                'last_error' => $status === 200 ? null : $message,
            ]);
            if ($status === 200) {
                $provider->capabilities = collect($models)->pluck('category')->unique()->values()->all();
            }
            $provider->save();
            $audit->record($request->user(), $status === 200 ? 'ai_provider.checked' : 'ai_provider.check_failed', $provider, [
                'status' => $provider->status,
                'model_count' => count($models),
            ]);

            return $provider;
        });

        return response()->json([
            'provider' => $provider->fresh()->adminPayload(),
            'model_count' => count($models),
            'message' => $message,
        ], $status);
    }

    private function validateConnection(Request $request, AiProviderEndpoint $endpoint, ?AiProviderProfile $provider = null): array
    {
        $fields = ['name', 'protocol', 'base_url', 'api_key', 'api_version', 'is_enabled'];
        if (! $provider) {
            $fields[] = 'slug';
        }
        $input = $request->only($fields);
        // Keep credentials out of Laravel's old-input flashing and exception request context.
        $request->request->remove('api_key');
        $request->query->remove('api_key');
        $request->json()->remove('api_key');
        if ($provider && isset($input['api_key']) && is_string($input['api_key']) && trim($input['api_key']) === '') {
            unset($input['api_key']);
        }
        $protocol = is_string($input['protocol'] ?? null) ? $input['protocol'] : ($provider?->protocol ?? 'openai');
        if ($provider?->base_url !== null && array_key_exists('protocol', $input) && ! array_key_exists('base_url', $input)) {
            $input['base_url'] = $provider->base_url;
        }
        $presence = $provider ? 'sometimes' : 'required';
        $rules = [
            'name' => [$presence, 'required', 'string', 'max:120'],
            'protocol' => [$presence, 'required', Rule::in(['openai', 'anthropic', 'fal'])],
            'base_url' => [$presence, 'required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) use ($endpoint, $protocol): void {
                if (! is_string($value)) {
                    return;
                }
                try {
                    $endpoint->normalize($value, $protocol);
                } catch (InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            }],
            'api_key' => [$provider ? 'nullable' : 'required', 'string', 'max:8192', 'regex:/^[\x21-\x7e]+$/D'],
            'api_version' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/D'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
        if (! $provider) {
            $rules['slug'] = ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::notIn(['ai-proxy']), 'unique:ai_provider_profiles,slug'];
        } elseif ($provider->base_url === null) {
            foreach (['protocol', 'base_url', 'api_key', 'api_version'] as $field) {
                $rules[$field] = ['prohibited'];
            }
        }
        $validator = Validator::make($input, $rules, [
            'api_key.required' => 'Enter an API key for this provider.',
            'api_key.string' => 'Enter a valid API key.',
            'api_key.max' => 'The API key is too long.',
            'api_key.regex' => 'Enter a valid API key without whitespace or control characters.',
            'base_url.prohibited' => 'Environment connection settings are managed by the server.',
            'api_key.prohibited' => 'Environment connection settings are managed by the server.',
            'protocol.prohibited' => 'Environment connection settings are managed by the server.',
            'api_version.prohibited' => 'Environment connection settings are managed by the server.',
        ]);
        if ($validator->fails()) {
            $this->invalid($validator->errors()->toArray());
        }
        $validated = $validator->validated();
        if ($provider && $provider->base_url === null) {
            return Arr::only($validated, ['name', 'is_enabled']);
        }
        if (array_key_exists('base_url', $validated)) {
            $validated['base_url'] = $endpoint->normalize($validated['base_url'], $protocol);
        }
        if ($provider && (! isset($validated['api_key']) || $validated['api_key'] === '')) {
            unset($validated['api_key']);
        }

        return $validated;
    }

    private function invalid(array $errors): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The provider configuration is invalid.',
            'errors' => $errors,
        ], 422));
    }
}
