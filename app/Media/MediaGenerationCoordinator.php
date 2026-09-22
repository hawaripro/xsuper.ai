<?php

namespace App\Media;

use App\Exceptions\ImageGenerationException;
use App\Jobs\ProcessImageJob;
use App\Jobs\ProcessVideoJob;
use App\Media\Enums\MediaOperation;
use App\Models\AiModelProfile;
use App\Models\ImageJob;
use App\Models\VideoJob;
use App\Models\User;
use App\Models\MediaAsset;
use Illuminate\Auth\Access\AuthorizationException;
use App\Media\Exceptions\CapabilityValidationException;
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
    private const MAX_TOKEN_AMOUNT = 2_147_483_647;

    public function __construct(
        private readonly CapabilityResolver $resolver,
        private readonly CapabilityValidator $validator,
        private readonly MediaTokenBillingService $tokens,
        private readonly MediaActivation $activation,
        private readonly AssetService $assets,
    ) {}

    /**
     * @param  array<string, mixed>  $rawInputs
     */
    public function startImage(User $user, AiModelProfile $model, MediaOperation $operation, array $rawInputs, string $entrypoint, array $options = []): ImageJob
    {
        $this->activation->assertNotPaused();
        if ($model->category !== 'image' || ! MediaModelConfig::allowedFor($user, $model)) {
            throw new ImageGenerationException('The selected image model is unavailable.', 503);
        }
        $resolved = $this->resolver->resolve($model, $operation);
        try {
            $validated = $this->validator->validate($resolved->capability, $rawInputs);
        } catch (CapabilityValidationException $e) {
            // A capability violation is a member-facing input error, not a 500.
            throw new ImageGenerationException((string) (array_values($e->errors())[0] ?? $e->getMessage()), 422);
        }
        if (! is_int($model->token_cost) || $model->token_cost < 1) {
            throw new ImageGenerationException('Image token pricing is unavailable.', 503);
        }

        // Reject a capability/price the member's form was built on if it changed, BEFORE any
        // reservation or provider call.
        $expectedPrice = $options['expected_price_tokens'] ?? null;
        if ($expectedPrice !== null && (int) $expectedPrice !== (int) $model->token_cost) {
            throw new ImageGenerationException('The price changed since you opened this form. Review and try again.', 409);
        }
        $expectedHash = $options['expected_capability_hash'] ?? null;
        if (is_string($expectedHash) && $expectedHash !== '' && ! hash_equals($resolved->sourceHash, $expectedHash)) {
            throw new ImageGenerationException('This model was updated since you opened this form. Review and try again.', 409);
        }

        // Resolve + own every declared reference asset BEFORE reserving or dispatching.
        $referenceAssetIds = $this->resolveReferenceAssets($user, $resolved->capability, $validated);

        // Client idempotency key: an accidental retry of the SAME action returns the same job;
        // a NEW Generate action carries a new key and gets a new job even with identical input.
        $fingerprint = $this->fingerprintPayload($operation, $model->model_id, $validated);
        $idempotencyKey = isset($options['idempotency_key']) && is_string($options['idempotency_key']) && trim($options['idempotency_key']) !== ''
            ? trim($options['idempotency_key']) : null;
        $dedup = null;
        if ($idempotencyKey !== null) {
            $dedup = hash('sha256', $user->id.'|'.$entrypoint.'|'.$idempotencyKey);
            $existing = ImageJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)
                ->whereIn('status', ['pending', 'processing'])->first();
            if ($existing !== null) {
                if ((string) $existing->payload_fingerprint === $fingerprint) {
                    return $existing;
                }
                throw new ImageGenerationException('This request key was already used with different input.', 409);
            }
        }

        $jobId = (string) Str::uuid();
        $job = DB::transaction(function () use ($user, $model, $operation, $resolved, $validated, $jobId, $dedup, $fingerprint, $referenceAssetIds): ImageJob {
            $revision = $this->resolver->ensureRevision($model, $operation, $resolved);
            $reservation = $this->tokens->reserve($user, 'image', $model->model_id, 1, "image:{$jobId}", (int) $model->token_cost);

            return ImageJob::create([
                'user_id' => $user->id, 'job_id' => $jobId, 'model' => $model->model_id,
                'provider_id' => $model->provider_id, 'upstream_model_id' => $model->upstream_model_id ?: $model->model_id,
                'connection_fingerprint' => $this->fingerprint($model), 'generation_config' => MediaModelConfig::forModel($model),
                'capability_revision_id' => $revision->id, 'routing_identity' => $model->upstream_model_id ?: $model->model_id,
                'price_tokens' => (int) $model->token_cost, 'dedup_key' => $dedup, 'payload_fingerprint' => $fingerprint,
                'reference_asset_ids' => $referenceAssetIds !== [] ? $referenceAssetIds : null,
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
     * Capability-driven video submission (text_to_video, image_to_video). Mirrors startImage:
     * resolve -> validate -> price/hash guards -> own references -> dedup -> reserve -> persist the
     * VideoJobs carrying the capability revision + routing + price -> dispatch. image_to_video
     * routes to the provider's dedicated reference model and carries no aspect ratio.
     *
     * Quantity and Pro come from the validated capability params, so one submission can create
     * several jobs (one reservation each) exactly like the studio's existing pipeline. CTA and
     * UGC variation are prompt composition, not model parameters, so they arrive via $options.
     *
     * @param  array<string, mixed>  $rawInputs
     * @param  array<string, mixed>  $options
     * @return VideoJob[]
     */
    public function startVideo(User $user, AiModelProfile $model, MediaOperation $operation, array $rawInputs, string $entrypoint, array $options = []): array
    {
        $this->activation->assertNotPaused();
        if ($model->category !== 'video' || ! MediaModelConfig::allowedFor($user, $model)) {
            throw new ImageGenerationException('The selected video model is unavailable.', 503);
        }
        $resolved = $this->resolver->resolve($model, $operation);
        try {
            $validated = $this->validator->validate($resolved->capability, $rawInputs);
        } catch (CapabilityValidationException $e) {
            throw new ImageGenerationException((string) (array_values($e->errors())[0] ?? $e->getMessage()), 422);
        }
        $config = MediaModelConfig::forModel($model);
        $proRequested = (bool) ($validated['params']['pro'] ?? false);
        $pro = $proRequested && ($config['supports_pro'] ?? false);
        if ($proRequested && ! $pro) {
            throw new ImageGenerationException('Pro quality is not supported by this model.', 422);
        }
        $count = max(1, (int) ($validated['params']['count'] ?? 1));
        $multiplier = $pro ? 2 : 1;
        if (! is_int($model->token_cost) || $model->token_cost < 1 || $model->token_cost > intdiv(self::MAX_TOKEN_AMOUNT, $multiplier)) {
            throw new ImageGenerationException('Video token pricing is unavailable.', 503);
        }
        $unitCost = (int) $model->token_cost * $multiplier;
        if ($count > intdiv(self::MAX_TOKEN_AMOUNT, $unitCost)) {
            throw new ImageGenerationException('The total token reservation exceeds the supported limit.', 422);
        }

        // Reject a capability/price the member's form was built on if it changed, BEFORE any
        // reservation. The guard compares the per-video price actually charged, so Pro is covered.
        $expectedPrice = $options['expected_price_tokens'] ?? null;
        if ($expectedPrice !== null && (int) $expectedPrice !== $unitCost) {
            throw new ImageGenerationException('The price changed since you opened this form. Review and try again.', 409);
        }
        $expectedHash = $options['expected_capability_hash'] ?? null;
        if (is_string($expectedHash) && $expectedHash !== '' && ! hash_equals($resolved->sourceHash, $expectedHash)) {
            throw new ImageGenerationException('This model was updated since you opened this form. Review and try again.', 409);
        }

        // Own every declared reference asset BEFORE reserving or dispatching.
        $referenceAssetIds = $this->resolveReferenceAssets($user, $resolved->capability, $validated);
        $hasReference = $referenceAssetIds !== [];

        $fingerprint = $this->fingerprintPayload($operation, $model->model_id, $validated);
        $idempotencyKey = isset($options['idempotency_key']) && is_string($options['idempotency_key']) && trim($options['idempotency_key']) !== ''
            ? trim($options['idempotency_key']) : null;
        $dedup = null;
        if ($idempotencyKey !== null) {
            $dedup = hash('sha256', $user->id.'|'.$entrypoint.'|'.$idempotencyKey);
            $existing = VideoJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)
                ->whereIn('status', ['pending', 'processing'])->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                if ((string) $existing->first()->payload_fingerprint === $fingerprint) {
                    return $existing->all();
                }
                throw new ImageGenerationException('This request key was already used with different input.', 409);
            }
        }

        $upstream = $model->upstream_model_id ?: $model->model_id;
        // image_to_video routes to the provider's dedicated reference model; text stays on the base model.
        $routing = ($operation === MediaOperation::ImageToVideo && is_string($config['reference_model'] ?? null))
            ? $config['reference_model'] : $upstream;
        $aspect = $operation === MediaOperation::ImageToVideo
            ? 'auto'
            : (string) ($validated['params']['aspect_ratio'] ?? ($config['aspect_ratios'][0] ?? 'auto'));
        $duration = (int) ($validated['params']['duration'] ?? ($config['durations'][0] ?? 0));
        // Pro is a real provider parameter, not only a price tier: the same inference/encoding
        // values the existing pipeline sends.
        if ($config['supports_pro'] ?? false) {
            $config['video_parameters'] = [
                'num_inference_steps' => $pro ? 16 : 12,
                'video_quality' => $pro ? 'maximum' : 'high',
            ];
        }

        $basePrompt = trim((string) ($validated['inputs']['prompt'] ?? ''));
        $cta = isset($options['cta']) && is_string($options['cta']) ? trim($options['cta']) : '';
        $variation = (bool) ($options['ugc_variation'] ?? false);
        $mode = isset($options['mode']) && is_string($options['mode']) && $options['mode'] !== '' ? $options['mode'] : 'prompt';

        $jobs = DB::transaction(function () use (
            $user, $model, $operation, $resolved, $dedup, $fingerprint, $referenceAssetIds, $hasReference,
            $config, $routing, $aspect, $duration, $count, $pro, $unitCost, $basePrompt, $cta, $variation, $mode
        ): array {
            $revision = $this->resolver->ensureRevision($model, $operation, $resolved);
            $created = [];
            for ($index = 0; $index < $count; $index++) {
                $prompt = $basePrompt;
                if ($cta !== '') {
                    $prompt .= "\nCall to action: ".$cta;
                }
                if ($variation && $index > 0) {
                    $prompt .= "\nCreate variation ".($index + 1).' with a distinct camera composition while preserving the same subject and message.';
                }
                $jobId = (string) Str::uuid();
                $reservation = $this->tokens->reserve($user, 'video', $model->model_id, 1, "video:{$jobId}", $unitCost);
                $created[] = VideoJob::create([
                    'user_id' => $user->id, 'job_id' => $jobId, 'model' => $model->model_id, 'mode' => $mode,
                    'provider_id' => $model->provider_id, 'upstream_model_id' => $routing,
                    'connection_fingerprint' => $this->fingerprint($model), 'generation_config' => $config,
                    'capability_revision_id' => $revision->id, 'routing_identity' => $routing,
                    'price_tokens' => $unitCost, 'dedup_key' => $dedup, 'payload_fingerprint' => $fingerprint,
                    'reference_asset_ids' => $referenceAssetIds !== [] ? $referenceAssetIds : null,
                    'prompt' => $prompt,
                    'aspect_ratio' => $aspect, 'duration' => $duration,
                    'pro_mode' => $pro, 'has_reference' => $hasReference,
                    'status' => 'pending', 'stage' => 'queued',
                    'billing_reserved_microusd' => 0, 'billing_reference_id' => "video:{$jobId}",
                    'billing_status' => 'reserved', 'billing_mode' => $reservation['billing_mode'],
                    'tokens_used' => $reservation['amount_tokens'], 'tokens_reserved' => $reservation['amount_tokens'],
                    'settings' => ['cta' => $cta !== '' ? $cta : null, 'ugc_variation' => $variation],
                    'next_poll_at' => now()->addMinute(),
                ]);
            }

            return $created;
        });
        foreach ($jobs as $job) {
            ProcessVideoJob::dispatch($job->id)->onConnection('media')->onQueue('media')->afterCommit();
        }

        return $jobs;
    }

    /**
     * Validate that every declared asset input references an image asset the member owns,
     * returning the stable asset ids to persist. Rejects (before any reservation) a missing,
     * non-owned, or non-image reference. Presence of a required asset is enforced upstream by
     * the CapabilityValidator; this adds ownership + media-type + existence.
     *
     * @param  array{inputs: array<string, mixed>, params: array<string, mixed>}  $validated
     * @return string[]
     */
    private function resolveReferenceAssets(User $user, MediaCapability $capability, array $validated): array
    {
        $ids = [];
        foreach ($capability->inputs as $input) {
            if ($input->type !== 'asset') {
                continue;
            }
            $value = $validated['inputs'][$input->key] ?? null;
            if ($value === null) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $assetId) {
                $asset = MediaAsset::find($assetId);
                if ($asset === null) {
                    throw new ImageGenerationException('A referenced asset could not be found.', 422);
                }
                try {
                    $this->assets->assertOwner($user, $asset);
                } catch (AuthorizationException) {
                    throw new ImageGenerationException('This reference is not available for your account.', 403);
                }
                if ($asset->media_type !== 'image') {
                    throw new ImageGenerationException('The reference must be an image.', 422);
                }
                $ids[] = $asset->id;
            }
        }

        return $ids;
    }

    /** @param  array{inputs: array<string, mixed>, params: array<string, mixed>}  $validated */
    private function fingerprintPayload(MediaOperation $operation, string $model, array $validated): string
    {
        return hash('sha256', json_encode(['o' => $operation->value, 'm' => $model, 'p' => $validated], JSON_THROW_ON_ERROR));
    }

    private function fingerprint(AiModelProfile $model): string
    {
        return hash('sha256', json_encode(array_intersect_key($model->provider->getRawOriginal(),
            array_flip(['base_url', 'api_key', 'protocol', 'api_version'])), JSON_THROW_ON_ERROR));
    }
}
