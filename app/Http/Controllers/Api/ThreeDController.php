<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImageGenerationException;
use App\Http\Controllers\Controller;
use App\Media\CapabilityPresenter;
use App\Media\CapabilityResolver;
use App\Media\Enums\MediaOperation;
use App\Media\Exceptions\CapabilityConfigException;
use App\Media\Exceptions\CapabilityValidationException;
use App\Media\MediaActivation;
use App\Media\MediaGenerationCoordinator;
use App\Models\AiModelProfile;
use App\Models\ThreeDJob;
use App\Models\User;
use App\Models\UserToken;
use App\Services\GeneratedModel3dStore;
use App\Services\MediaModelConfig;
use App\Services\StorageQuotaService;
use App\Services\ThreeDGenerationService;
use App\Services\ThreeDProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class ThreeDController extends Controller
{
    public function models(Request $request, CapabilityPresenter $presenter, MediaActivation $activation, CapabilityResolver $resolver): JsonResponse
    {
        $user = $request->user();
        $models = [];
        if ($activation->usesCoordinator($user) && $user->hasPermission('image_generator')) {
            $models = AiModelProfile::query()->with('provider')->where('category', 'model3d')
                ->where('is_enabled', true)->where('is_available', true)->orderBy('display_name')->get()
                ->filter(fn (AiModelProfile $model): bool => ThreeDProtocol::supports($model)
                    && MediaModelConfig::allowedFor($user, $model) && is_int($model->token_cost)
                    && $model->token_cost > 0 && $model->token_cost <= 2_147_483_647)
                ->filter(function (AiModelProfile $model) use ($resolver): bool {
                    try {
                        return ThreeDProtocol::compatible($resolver->resolve($model, MediaOperation::ImageTo3d)->capability);
                    } catch (CapabilityConfigException) {
                        return false;
                    }
                })
                ->map(function (AiModelProfile $model) use ($presenter): array {
                    return [...MediaModelConfig::publicModel($model), 'capabilities' => MediaModelConfig::legacyCapabilities($presenter->forModel($model)),
                        'format' => 'glb', 'fixed_settings' => ThreeDProtocol::FIXED_PARAMS];
                })->filter(fn (array $model): bool => isset($model['capabilities']['image_to_3d']))->values()->all();
        }

        return response()->json(['models' => $models, 'balance' => UserToken::getBalance($user->id)]);
    }

    public function history(Request $request): JsonResponse
    {
        $query = ThreeDJob::query()->where('user_id', $request->user()->id);
        $active = (clone $query)->whereIn('status', ['pending', 'processing'])->latest()->get();
        $recent = (clone $query)->whereIn('status', ['completed', 'failed'])->latest()->limit(50)->get();

        return response()->json(['jobs' => $active->concat($recent)->sortByDesc('created_at')->values()
            ->map(fn (ThreeDJob $job): array => ThreeDGenerationService::payload($job))->all(),
            'balance' => UserToken::getBalance($request->user()->id)]);
    }

    public function generate(Request $request, MediaGenerationCoordinator $coordinator): JsonResponse
    {
        $envelope = $request->validate([
            'model' => 'required|string|max:160', 'operation' => 'required|in:image_to_3d',
            'expected_price_tokens' => 'required|integer|min:1|max:2147483647',
            'expected_capability_hash' => 'required|string|size:64', 'idempotency_key' => 'required|string|max:128',
        ]);
        $model = AiModelProfile::query()->with('provider')->where('model_id', $envelope['model'])->first();
        if ($model === null) {
            return response()->json(['message' => 'The selected 3D model is unavailable.'], 503);
        }
        // Preserve unknown input keys until the resolved capability validates them. Stale hashes take precedence.
        $inputs = $request->except(['model', 'operation', 'expected_price_tokens', 'expected_capability_hash', 'idempotency_key']);
        if (array_key_exists('seed', $inputs) && $inputs['seed'] === null) {
            unset($inputs['seed']);
        }
        try {
            $jobs = $coordinator->startModel3d($request->user(), $model, MediaOperation::ImageTo3d, $inputs, 'studio-model3d', [
                'expected_price_tokens' => (int) $envelope['expected_price_tokens'],
                'expected_capability_hash' => $envelope['expected_capability_hash'],
                'idempotency_key' => $envelope['idempotency_key'],
            ]);
        } catch (CapabilityValidationException $exception) {
            throw ValidationException::withMessages($exception->errors());
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage(),
                'balance' => UserToken::getBalance($request->user()->id)], $exception->responseStatus());
        }

        return response()->json(['jobs' => array_map(ThreeDGenerationService::payload(...), $jobs),
            'balance' => UserToken::getBalance($request->user()->id)], 202);
    }

    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = ThreeDJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->firstOrFail();

        return response()->json(['job' => ThreeDGenerationService::payload($job),
            'balance' => UserToken::getBalance($request->user()->id)]);
    }

    public function cancel(Request $request, string $jobId, ThreeDGenerationService $models): JsonResponse
    {
        $job = $models->cancel($request->user(), $jobId);
        $cancelled = $job->status === 'failed' && $job->stage === 'cancelled';
        $payload = ThreeDGenerationService::payload($job);

        return response()->json(['job' => $payload,
            ...($cancelled ? [] : ['message' => $payload['cancel_unavailable_reason']]),
            'balance' => UserToken::getBalance($request->user()->id)], $cancelled ? 200 : 409);
    }

    public function destroy(Request $request, string $jobId): JsonResponse
    {
        DB::transaction(function () use ($request, $jobId): void {
            $query = ThreeDJob::query()->where('job_id', $jobId);
            if (! $request->user()->isAdmin()) {
                $query->where('user_id', $request->user()->id);
            }
            $ownerId = (clone $query)->value('user_id');
            abort_if($ownerId === null, 404);
            $user = User::query()->whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $job = $query->lockForUpdate()->firstOrFail();
            if (in_array($job->status, ['pending', 'processing'], true)) {
                throw ValidationException::withMessages(['job' => 'Wait for this generation to finish before deleting it.']);
            }
            app(StorageQuotaService::class)->assertJobUnreferenced($user, 'model3d:'.$job->job_id);
            $directory = dirname(GeneratedModel3dStore::path($job->job_id));
            $disk = Storage::disk('local');
            if ($disk->exists($directory) && ! $disk->deleteDirectory($directory)) {
                throw ValidationException::withMessages(['job' => 'The stored model could not be deleted. Try again.']);
            }
            $job->delete();
        });

        return response()->json(['deleted_count' => 1]);
    }

    public function asset(Request $request, string $jobId): BinaryFileResponse
    {
        $job = ThreeDJob::query()->where('job_id', $jobId)->firstOrFail();
        abort_unless($request->user()->isAdmin() || $request->user()->id === $job->user_id, 404);
        $path = GeneratedModel3dStore::path($job->job_id);
        $disk = Storage::disk('local');
        abort_unless($job->status === 'completed' && $job->model_path === $path
            && $job->model_url === '/api/3d/'.$job->job_id.'/asset'
            && $job->mime_type === 'model/gltf-binary' && $disk->exists($path), 404);

        return response()->file($disk->path($path), ['Content-Type' => 'model/gltf-binary',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cross-Origin-Resource-Policy' => 'same-origin',
        ])->setContentDisposition($request->boolean('download') || ! $job->previewable
            ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE, 'model-'.$job->job_id.'.glb');
    }
}
