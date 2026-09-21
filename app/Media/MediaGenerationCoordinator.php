<?php

namespace App\Media;

use App\Exceptions\ImageGenerationException;
use App\Jobs\ProcessImageJob;
use App\Media\Enums\MediaOperation;
use App\Models\AiModelProfile;
use App\Models\ImageJob;
use App\Models\User;
use App\Services\MediaModelConfig;
use App\Services\MediaTokenBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared entry for capability-driven media generation. Owns the ordered flow: resolve
 * capability -> validate normalized inputs -> dedup -> reserve credit -> persist a job
 * carrying the capability revision + routing + price -> dispatch. The provider adapter
 * and the existing process/poll pipeline handle execution; this reserves credit but
 * never settles/refunds directly (that stays with the job lifecycle). A rejected
 * validation creates neither a reservation nor a job.
 */
final class MediaGenerationCoordinator
{
    public function __construct(
        private readonly CapabilityResolver $resolver,
        private readonly CapabilityValidator $validator,
        private readonly MediaTokenBillingService $tokens,
    ) {}

    /**
     * @param  array<string, mixed>  $rawInputs
     */
    public function startImage(User $user, AiModelProfile $model, MediaOperation $operation, array $rawInputs, string $entrypoint): ImageJob
    {
        if ($model->category !== 'image' || ! MediaModelConfig::allowedFor($user, $model)) {
            throw new ImageGenerationException('The selected image model is unavailable.', 503);
        }
        $resolved = $this->resolver->resolve($model, $operation);
        $validated = $this->validator->validate($resolved->capability, $rawInputs);
        if (! is_int($model->token_cost) || $model->token_cost < 1) {
            throw new ImageGenerationException('Image token pricing is unavailable.', 503);
        }

        $dedup = $this->dedupKey($user->id, $entrypoint, $operation, $model->model_id, $validated);
        $existing = ImageJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)
            ->whereIn('status', ['pending', 'processing'])->first();
        if ($existing !== null) {
            return $existing;
        }

        $jobId = (string) Str::uuid();
        $job = DB::transaction(function () use ($user, $model, $operation, $resolved, $validated, $jobId, $dedup): ImageJob {
            $revision = $this->resolver->ensureRevision($model, $operation, $resolved);
            $reservation = $this->tokens->reserve($user, 'image', $model->model_id, 1, "image:{$jobId}", (int) $model->token_cost);

            return ImageJob::create([
                'user_id' => $user->id, 'job_id' => $jobId, 'model' => $model->model_id,
                'provider_id' => $model->provider_id, 'upstream_model_id' => $model->upstream_model_id ?: $model->model_id,
                'connection_fingerprint' => $this->fingerprint($model), 'generation_config' => MediaModelConfig::forModel($model),
                'capability_revision_id' => $revision->id, 'routing_identity' => $model->upstream_model_id ?: $model->model_id,
                'price_tokens' => (int) $model->token_cost, 'dedup_key' => $dedup,
                'prompt' => (string) ($validated['inputs']['prompt'] ?? ''),
                'size' => (string) ($validated['params']['size'] ?? 'auto'), 'quantity' => 1,
                'status' => 'pending', 'stage' => 'queued',
                'billing_reserved_microusd' => 0, 'billing_reference_id' => "image:{$jobId}",
                'billing_status' => 'reserved', 'billing_mode' => $reservation['billing_mode'],
                'tokens_reserved' => $reservation['amount_tokens'], 'next_poll_at' => now()->addMinute(),
            ]);
        });
        ProcessImageJob::dispatch($job->id)->onConnection('media')->onQueue('media')->afterCommit();

        return $job;
    }

    /**
     * Bind dedup to principal + entrypoint + operation + model + normalized payload over
     * stable ids (never signed URLs). Same key + same payload returns the existing job.
     *
     * @param  array{inputs: array<string, mixed>, params: array<string, mixed>}  $validated
     */
    private function dedupKey(int $userId, string $entrypoint, MediaOperation $operation, string $model, array $validated): string
    {
        return hash('sha256', json_encode([
            'u' => $userId, 'e' => $entrypoint, 'o' => $operation->value, 'm' => $model, 'p' => $validated,
        ], JSON_THROW_ON_ERROR));
    }

    private function fingerprint(AiModelProfile $model): string
    {
        return hash('sha256', json_encode(array_intersect_key($model->provider->getRawOriginal(),
            array_flip(['base_url', 'api_key', 'protocol', 'api_version'])), JSON_THROW_ON_ERROR));
    }
}
