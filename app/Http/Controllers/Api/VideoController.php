<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImageGenerationException;
use App\Http\Controllers\Controller;
use App\Media\AssetService;
use App\Media\CapabilityPresenter;
use App\Media\CapabilityResolver;
use App\Media\CapabilityValidator;
use App\Media\Enums\MediaOperation;
use App\Media\Exceptions\CapabilityValidationException;
use App\Media\MediaActivation;
use App\Media\MediaGenerationCoordinator;
use App\Models\AiModelProfile;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Services\GeneratedVideoStore;
use App\Services\MediaModelConfig;
use App\Services\VideoGenerationService;
use App\Services\VideoReferenceStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class VideoController extends Controller
{
    public function generate(Request $request, VideoGenerationService $videos): JsonResponse
    {
        // The emergency pause must cover BOTH video paths. Only the coordinator consulted it, so
        // MEDIA_KILL_SWITCH silently failed to stop submissions on the legacy pipeline that the
        // studio UI actually uses. In-flight jobs still finish: process()/poll() never call this.
        try {
            app(MediaActivation::class)->assertNotPaused();
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->responseStatus());
        }
        // Capability-driven submissions (F2) carry an `operation` and route to the coordinator;
        // legacy submissions (no `operation`) keep the existing verified pipeline below untouched.
        if ($request->filled('operation')) {
            return $this->generateCapability($request, $videos);
        }
        $input = $request->validate([
            'prompt' => 'required|string|max:4000',
            'model' => 'required|string|max:120',
            'aspect_ratio' => 'nullable|string|max:16',
            'count' => 'required|integer|min:1|max:10',
            'mode' => 'required|in:prompt,ab_testing',
            'pro_mode' => 'sometimes|boolean',
            'reference_image' => 'sometimes|nullable|file|mimetypes:image/jpeg,image/png,image/webp|max:10240',
            'ugc_variation' => 'sometimes|boolean',
            'cta' => 'nullable|string|max:500',
            'settings' => 'sometimes|array:duration',
            'settings.duration' => 'nullable|integer|min:1|max:600',
        ]);
        $jobs = $videos->create($request->user(), $input);

        return response()->json([
            'message' => 'Video requests queued for generation.',
            'jobs' => array_map(fn (VideoJob $job): array => $videos->payload($job), $jobs),
            'tokens_used' => array_sum(array_map(fn (VideoJob $job): int => $job->tokens_reserved, $jobs)),
            'balance' => UserToken::getBalance($request->user()->id),
            'billing_mode' => 'tokens',
        ], 202);
    }

    /**
     * Capability-driven video (text_to_video, image_to_video). The pilot routes to the coordinator;
     * a non-pilot keeps the existing verified path (text-to-video only without the coordinator).
     * This never blocks the existing service — it only routes the pilot to the new path.
     */
    private function generateCapability(Request $request, VideoGenerationService $videos): JsonResponse
    {
        $validated = $request->validate([
            'operation' => ['required', Rule::in(['text_to_video', 'image_to_video'])],
            'model' => ['required', 'string', 'max:160', Rule::exists('ai_model_profiles', 'model_id')->where(
                fn ($query) => $query->where('category', 'video')->where('is_enabled', true),
            )],
            'prompt' => 'required|string|max:4000',
            'aspect_ratio' => ['nullable', 'string', 'max:16'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:600'],
            'reference_image' => ['nullable', 'string', 'max:64'],
            'count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'pro' => ['nullable', 'boolean'],
            'mode' => ['nullable', Rule::in(['prompt', 'ab_testing'])],
            'cta' => ['nullable', 'string', 'max:500'],
            'ugc_variation' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'expected_price_tokens' => ['nullable', 'integer', 'min:1'],
            'expected_capability_hash' => ['nullable', 'string', 'max:64'],
        ]);
        $user = $request->user();
        $model = AiModelProfile::query()->with('provider')->where('model_id', $validated['model'])->first();
        if (! $model || $model->category !== 'video' || ! MediaModelConfig::allowedFor($user, $model)) {
            return response()->json(['message' => 'The selected video model is unavailable.'], 503);
        }
        $operation = MediaOperation::from($validated['operation']);
        $count = (int) ($validated['count'] ?? 1);
        $pro = (bool) ($validated['pro'] ?? false);
        $rawInputs = array_filter([
            'prompt' => $validated['prompt'],
            'aspect_ratio' => $validated['aspect_ratio'] ?? null,
            'duration' => $validated['duration'] ?? null,
            'reference_image' => $validated['reference_image'] ?? null,
        ], static fn ($v): bool => $v !== null);
        // Declare quantity/Pro only when they carry a real choice: a model whose contract omits
        // them rejects unknown fields, and sending the default would break those models.
        if ($count > 1) {
            $rawInputs['count'] = $count;
        }
        if ($pro) {
            $rawInputs['pro'] = true;
        }
        $options = array_filter([
            'idempotency_key' => $validated['idempotency_key'] ?? null,
            'expected_price_tokens' => $validated['expected_price_tokens'] ?? null,
            'expected_capability_hash' => $validated['expected_capability_hash'] ?? null,
            'mode' => $validated['mode'] ?? null,
            'cta' => $validated['cta'] ?? null,
        ], static fn ($v): bool => $v !== null);
        $options['ugc_variation'] = (bool) ($validated['ugc_variation'] ?? false);

        try {
            if (app(MediaActivation::class)->usesCoordinator($user)) {
                $jobs = app(MediaGenerationCoordinator::class)->startVideo($user, $model, $operation, $rawInputs, 'studio-video', $options);
            } elseif ($operation === MediaOperation::TextToVideo) {
                $jobs = DB::transaction(function () use ($user, $model, $operation, $rawInputs, $options, $videos, $validated, $count, $pro): array {
                    User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $dedup = isset($options['idempotency_key']) ? hash('sha256', $user->id.'|studio-video|'.$options['idempotency_key']) : null;
                    $execution = [
                        'cta' => trim($validated['cta'] ?? ''), 'ugc_variation' => $options['ugc_variation'], 'mode' => $validated['mode'] ?? 'prompt',
                    ];
                    if ($dedup !== null) {
                        $existing = VideoJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)->orderBy('id')->get();
                        if ($existing->isNotEmpty()) {
                            if (! app(MediaGenerationCoordinator::class)->matchesRequest($existing->first(), $operation, $model->model_id, $rawInputs, $execution)) {
                                throw new ImageGenerationException('This request key was already used with different input.', 409);
                            }

                            return $existing->all();
                        }
                    }
                    $model = AiModelProfile::query()->with('provider')->lockForUpdate()->findOrFail($model->id);
                    $resolved = app(CapabilityResolver::class)->resolve($model, $operation);
                    if (isset($options['expected_capability_hash']) && ! hash_equals($resolved->sourceHash, $options['expected_capability_hash'])) {
                        throw new ImageGenerationException('This model was updated since you opened this form. Review and try again.', 409);
                    }
                    $cost = (int) $model->token_cost * ($pro ? 2 : 1);
                    if (isset($options['expected_price_tokens']) && (int) $options['expected_price_tokens'] !== $cost) {
                        throw new ImageGenerationException('The price changed since you opened this form. Review and try again.', 409);
                    }
                    try {
                        $effective = app(CapabilityValidator::class)->validate($resolved->capability, $rawInputs);
                    } catch (CapabilityValidationException $exception) {
                        throw new ImageGenerationException($exception->getMessage(), 422);
                    }
                    $fingerprint = MediaGenerationCoordinator::fingerprintPayload($operation, $model->model_id, $effective);
                    $revision = app(CapabilityResolver::class)->ensureRevision($model, $operation, $resolved);
                    $created = $videos->create($user, [
                        'model' => $validated['model'], 'prompt' => $validated['prompt'],
                        'mode' => $validated['mode'] ?? 'prompt', 'count' => $count, 'pro_mode' => $pro,
                        'cta' => $validated['cta'] ?? null,
                        'ugc_variation' => (bool) ($validated['ugc_variation'] ?? false),
                        'aspect_ratio' => $validated['aspect_ratio'] ?? null,
                        'settings' => ['duration' => $validated['duration'] ?? null],
                    ]);
                    foreach ($created as $job) {
                        $job->forceFill(['dedup_key' => $dedup, 'payload_fingerprint' => $fingerprint, 'capability_revision_id' => $revision->id])->save();
                    }

                    return $created;
                });
            } else {
                return response()->json(['message' => 'This operation is not available for your account yet.'], 503);
            }
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'balance' => UserToken::getBalance($user->id)], $exception->responseStatus());
        }

        return response()->json([
            'message' => 'Video request queued for generation.',
            'jobs' => array_map(fn (VideoJob $job): array => $videos->payload($job), $jobs),
            'tokens_used' => array_sum(array_map(fn (VideoJob $job): int => $job->tokens_reserved, $jobs)),
            'balance' => UserToken::getBalance($user->id),
            'billing_mode' => 'tokens',
        ], 202);
    }

    public function history(Request $request, VideoGenerationService $videos): JsonResponse
    {
        $query = VideoJob::query()->where('user_id', $request->user()->id)->where('mode', '!=', 'avatar');
        $active = (clone $query)->whereIn('status', ['pending', 'processing'])->latest()->get();
        $recent = (clone $query)->whereIn('status', ['completed', 'failed'])->latest()->limit(50)->get();
        $jobs = $active->concat($recent)->sortByDesc('created_at')->values();

        return response()->json([
            'jobs' => $jobs->map(fn (VideoJob $job): array => $videos->payload($job))->all(),
            'balance' => UserToken::getBalance($request->user()->id),
        ]);
    }

    /** Delete one finished video job, its private asset, and its reference image. */
    public function destroy(Request $request, string $jobId, VideoReferenceStore $references): JsonResponse
    {
        DB::transaction(function () use ($request, $jobId, $references): void {
            $job = VideoJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->lockForUpdate()->firstOrFail();
            if (in_array($job->status, ['pending', 'processing'], true)) {
                throw ValidationException::withMessages(['job' => 'Pekerjaan masih berjalan. Batalkan dulu sebelum menghapusnya.']);
            }
            $this->removeVideoAssets($job, $references);
            $job->delete();
        });

        return response()->json(['deleted_count' => 1]);
    }

    /** Delete only the uploaded reference image, keeping the video job itself. */
    public function destroyReference(Request $request, string $jobId, VideoReferenceStore $references): JsonResponse
    {
        $job = VideoJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->firstOrFail();
        $reference = $references->existingPath($job);
        if ($reference !== null) {
            $references->delete($reference);
        }

        return response()->json(['deleted_count' => $reference !== null ? 1 : 0]);
    }

    /** Clear every finished video job; queued and running work stays untouched. */
    public function destroyAll(Request $request, VideoReferenceStore $references): JsonResponse
    {
        $removed = 0;
        VideoJob::query()->where('user_id', $request->user()->id)
            ->where('mode', '!=', 'avatar')
            ->whereNotIn('status', ['pending', 'processing'])->orderBy('id')
            ->chunkById(50, function ($jobs) use (&$removed, $references): void {
                foreach ($jobs as $job) {
                    DB::transaction(function () use ($job, $references, &$removed): void {
                        $locked = VideoJob::query()->lockForUpdate()->find($job->id);
                        if (! $locked || in_array($locked->status, ['pending', 'processing'], true)) {
                            return;
                        }
                        $this->removeVideoAssets($locked, $references);
                        $locked->delete();
                        $removed++;
                    });
                }
            });

        return response()->json(['deleted_count' => $removed]);
    }

    private function removeVideoAssets(VideoJob $job, VideoReferenceStore $references): void
    {
        $asset = GeneratedVideoStore::path($job->job_id);
        if (Storage::disk('local')->exists($asset)) {
            Storage::disk('local')->delete($asset);
        }
        $reference = $references->existingPath($job);
        if ($reference !== null) {
            $references->delete($reference);
        }
    }

    public function status(Request $request, string $jobId, VideoGenerationService $videos): JsonResponse
    {
        $job = VideoJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->firstOrFail();
        if ($job->billing_mode === 'wallet' && $job->billing_status === 'reserved' && in_array($job->status, ['failed', 'completed'], true)) {
            $videos->reconcileTerminalWalletReservation($job->id);
            $job->refresh();
        }

        return response()->json(['job' => $videos->payload($job), 'balance' => UserToken::getBalance($request->user()->id)]);
    }

    public function cancel(Request $request, string $jobId, VideoGenerationService $videos): JsonResponse
    {
        $job = $videos->cancel($request->user(), $jobId);
        $payload = $videos->payload($job);
        $cancelled = $job->status === 'failed' && $job->stage === 'cancelled';

        return response()->json([
            ...($cancelled ? [] : ['message' => $payload['cancel_reason'], 'reason_code' => $payload['cancel_reason_code']]),
            'job' => $payload,
            'balance' => UserToken::getBalance($request->user()->id),
        ], $cancelled ? 200 : 409);
    }

    public function asset(Request $request, string $jobId): BinaryFileResponse
    {
        $job = VideoJob::query()->where('job_id', $jobId)->firstOrFail();
        abort_unless($request->user()->isAdmin() || $job->user_id === $request->user()->id, 404);
        $path = GeneratedVideoStore::path($job->job_id);
        abort_unless($job->status === 'completed' && $job->video_url === '/api/v/'.$job->job_id.'/asset'
            && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'video/mp4', 'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function reference(Request $request, string $jobId, VideoReferenceStore $references): SymfonyResponse
    {
        $job = VideoJob::query()->where('job_id', $jobId)->firstOrFail();
        abort_unless($request->user()->isAdmin() || $job->user_id === $request->user()->id, 404);
        // Coordinator jobs keep the reference as an owned MediaAsset; legacy jobs use the
        // per-job store. Both are owner-gated here and neither mints a public URL.
        $assetId = is_array($job->reference_asset_ids) ? ($job->reference_asset_ids[0] ?? null) : null;
        if (is_string($assetId)) {
            $asset = MediaAsset::find($assetId);
            abort_if($asset === null || $asset->user_id !== $job->user_id, 404);

            return app(AssetService::class)->deliver($asset);
        }
        $path = $references->existingPath($job);
        abort_if($path === null, 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $job->reference_mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function models(Request $request): JsonResponse
    {
        $user = $request->user();
        $capabilities = app(CapabilityPresenter::class);
        $models = AiModelProfile::query()->with('provider')->where('category', 'video')
            ->where('is_enabled', true)->where('is_available', true)->orderBy('display_name')->get()
            ->filter(fn (AiModelProfile $model): bool => MediaModelConfig::allowedFor($user, $model)
                && in_array($model->provider->protocol, ['openai', 'fal', 'kinovi'], true) && $model->token_cost > 0)
            ->map(function (AiModelProfile $model) use ($capabilities): array {
                $payload = MediaModelConfig::publicModel($model);
                $payload['capabilities'] = $capabilities->forModel($model);

                return $payload;
            })->values()->all();

        return response()->json(['models' => $models, 'balance' => UserToken::getBalance($user->id)]);
    }
}
