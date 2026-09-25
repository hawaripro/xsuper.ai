<?php

namespace App\Media;

use App\Exceptions\ImageGenerationException;
use App\Jobs\ProcessAudioJob;
use App\Jobs\ProcessImageJob;
use App\Jobs\ProcessVideoJob;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Exceptions\CapabilityConfigException;
use App\Media\Exceptions\CapabilityValidationException;
use App\Models\AiModelProfile;
use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\MediaAsset;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use App\Models\VideoJob;
use App\Services\MediaModelConfig;
use App\Services\MediaTokenBillingService;
use App\Services\ThreeDGenerationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared entry for capability-driven media generation. Owns the ordered flow: serialized
 * replay of the original request -> resolve current capability -> validate inputs -> reserve
 * credit -> persist a job with capability revision, routing and price -> dispatch. The adapter
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
        $job = DB::transaction(function () use ($user, $model, $operation, $rawInputs, $entrypoint, $options): ImageJob {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $idempotencyKey = isset($options['idempotency_key']) && is_string($options['idempotency_key']) && trim($options['idempotency_key']) !== ''
                ? trim($options['idempotency_key']) : null;
            $dedup = $idempotencyKey !== null ? hash('sha256', $user->id.'|'.$entrypoint.'|'.$idempotencyKey) : null;
            if ($dedup !== null) {
                $existing = ImageJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)->first();
                if ($existing !== null) {
                    if (! $this->matchesRequest($existing, $operation, $model->model_id, $rawInputs)) {
                        throw new ImageGenerationException('This request key was already used with different input.', 409);
                    }

                    return $existing;
                }
            }
            $model = AiModelProfile::query()->with('provider')->lockForUpdate()->findOrFail($model->id);
            $this->activation->assertNotPaused();
            if ($model->category !== 'image' || ! MediaModelConfig::allowedFor($user, $model)) {
                throw new ImageGenerationException('The selected image model is unavailable.', 503);
            }
            $resolved = $this->resolveNative($model, $operation);
            $expectedHash = $options['expected_capability_hash'] ?? null;
            if (is_string($expectedHash) && $expectedHash !== '' && ! hash_equals($resolved->sourceHash, $expectedHash)) {
                throw new ImageGenerationException('This model was updated since you opened this form. Review and try again.', 409);
            }
            try {
                $validated = $this->validator->validate($resolved->capability, $rawInputs);
            } catch (CapabilityValidationException $e) {
                // A capability violation is a member-facing input error, not a 500.
                throw new ImageGenerationException((string) (array_values($e->errors())[0] ?? $e->getMessage()), 422);
            }
            $fingerprint = self::fingerprintPayload($operation, $model->model_id, $validated);
            if (! is_int($model->token_cost) || $model->token_cost < 1) {
                throw new ImageGenerationException('Image token pricing is unavailable.', 503);
            }

            // Reject a capability/price the member's form was built on if it changed, BEFORE any
            // reservation or provider call.
            $expectedPrice = $options['expected_price_tokens'] ?? null;
            if ($expectedPrice !== null && (int) $expectedPrice !== (int) $model->token_cost) {
                throw new ImageGenerationException('The price changed since you opened this form. Review and try again.', 409);
            }

            $jobId = (string) Str::uuid();
            // Serialize availability with owner deletion using the same user lock.
            $referenceAssetIds = $this->resolveReferenceAssets($user, $resolved->capability, $validated);
            $revision = $this->resolver->ensureRevision($model, $operation, $resolved);
            $reservation = $this->tokens->reserve($user, 'image', $model->model_id, 1, "image:{$jobId}", (int) $model->token_cost);

            $job = ImageJob::create([
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
            ProcessImageJob::dispatch($job->id)->onConnection('media')->onQueue('media')->afterCommit();

            return $job;
        });

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
        $jobs = DB::transaction(function () use ($user, $model, $operation, $rawInputs, $entrypoint, $options): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $avatar = $operation === MediaOperation::TalkingAvatar;
            $cta = isset($options['cta']) && is_string($options['cta']) ? trim($options['cta']) : '';
            $variation = (bool) ($options['ugc_variation'] ?? false);
            $mode = $avatar ? 'avatar' : ($options['mode'] ?? 'prompt');
            $idempotencyKey = isset($options['idempotency_key']) && is_string($options['idempotency_key']) && trim($options['idempotency_key']) !== ''
                ? trim($options['idempotency_key']) : null;
            $dedup = $idempotencyKey !== null ? hash('sha256', $user->id.'|'.$entrypoint.'|'.$idempotencyKey) : null;

            if ($dedup !== null) {
                $existing = VideoJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)->orderBy('id')->get();
                if ($existing->isNotEmpty()) {
                    if (! $this->matchesRequest($existing->first(), $operation, $model->model_id, $rawInputs, [
                        'cta' => $cta, 'ugc_variation' => $variation, 'mode' => $mode,
                    ])) {
                        throw new ImageGenerationException('This request key was already used with different input.', 409);
                    }

                    return $existing->all();
                }
            }
            $model = AiModelProfile::query()->with('provider')->lockForUpdate()->findOrFail($model->id);
            $this->activation->assertNotPaused();
            if ($model->category !== ($avatar ? 'avatar' : 'video') || ! MediaModelConfig::allowedFor($user, $model)) {
                throw new ImageGenerationException('The selected video model is unavailable.', 503);
            }
            if ($avatar && ($options['rights_confirmed'] ?? false) !== true) {
                throw new ImageGenerationException('Confirm that you have permission to use this photo and voice.', 422);
            }
            $resolved = $this->resolveNative($model, $operation);
            $expectedHash = $options['expected_capability_hash'] ?? null;
            if (is_string($expectedHash) && $expectedHash !== '' && ! hash_equals($resolved->sourceHash, $expectedHash)) {
                throw new ImageGenerationException('This model was updated since you opened this form. Review and try again.', 409);
            }
            try {
                $validated = $this->validator->validate($resolved->capability, $rawInputs);
            } catch (CapabilityValidationException $e) {
                throw new ImageGenerationException((string) (array_values($e->errors())[0] ?? $e->getMessage()), 422);
            }
            $fingerprint = self::fingerprintPayload($operation, $model->model_id, $validated);
            $config = MediaModelConfig::forModel($model);
            $proRequested = (bool) ($validated['params']['pro'] ?? false);
            $pro = $proRequested && ($config['supports_pro'] ?? false);
            if ($proRequested && ! $pro) {
                throw new ImageGenerationException('Pro quality is not supported by this model.', 422);
            }
            $count = max(1, (int) ($validated['params']['count'] ?? 1));
            $seconds = ($config['price_unit'] ?? null) === 'second' ? (int) ($validated['params']['duration'] ?? ($config['durations'][0] ?? 0)) : 1;
            if ($seconds < 1) {
                throw new ImageGenerationException('A positive duration is required for per-second pricing.', 422);
            }
            $multiplier = $seconds * ($pro ? 2 : 1);
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
            $settings = $avatar
                ? ['avatar_photo' => $validated['inputs']['avatar_photo'], 'speech_audio' => $validated['inputs']['speech_audio'],
                    'rights_confirmed_at' => now()->toISOString()]
                : ['cta' => $cta !== '' ? $cta : null, 'ugc_variation' => $variation];

            $referenceAssetIds = $this->resolveReferenceAssets($user, $resolved->capability, $validated, $config);
            $hasReference = $referenceAssetIds !== [];
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
                    'settings' => $settings,
                    'next_poll_at' => now()->addMinute(),
                ]);
            }
            foreach ($created as $job) {
                ProcessVideoJob::dispatch($job->id)->onConnection('media')->onQueue('media')->afterCommit();
            }

            return $created;
        });

        return $jobs;
    }

    /**
     * Capability-driven audio submission (text_to_speech, music). Mirrors startImage:
     * resolve -> validate -> price/hash guards -> dedup -> reserve -> persist an AudioJob
     * carrying the capability revision + routing + price -> dispatch. Tempo stays prompt-level
     * guidance appended exactly like the studio's existing pipeline; declared provider flags
     * the fixed columns cannot express (Suno instrumental / custom lyrics) persist in settings.
     *
     * @param  array<string, mixed>  $rawInputs
     * @param  array<string, mixed>  $options
     */
    public function startAudio(User $user, AiModelProfile $model, MediaOperation $operation, array $rawInputs, string $entrypoint, array $options = []): AudioJob
    {
        $job = DB::transaction(function () use ($user, $model, $operation, $rawInputs, $entrypoint, $options): AudioJob {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $idempotencyKey = isset($options['idempotency_key']) && is_string($options['idempotency_key']) && trim($options['idempotency_key']) !== ''
                ? trim($options['idempotency_key']) : null;
            $dedup = $idempotencyKey !== null ? hash('sha256', $user->id.'|'.$entrypoint.'|'.$idempotencyKey) : null;

            if ($dedup !== null) {
                $existing = AudioJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)->first();
                if ($existing !== null) {
                    if (! $this->matchesRequest($existing, $operation, $model->model_id, $rawInputs)) {
                        throw new ImageGenerationException('This request key was already used with different input.', 409);
                    }

                    return $existing;
                }
            }
            $model = AiModelProfile::query()->with('provider')->lockForUpdate()->findOrFail($model->id);
            $this->activation->assertNotPaused();
            if ($model->category !== 'audio' || ! MediaModelConfig::allowedFor($user, $model)) {
                throw new ImageGenerationException('The selected audio model is unavailable.', 503);
            }
            $resolved = $this->resolveNative($model, $operation);
            $expectedHash = $options['expected_capability_hash'] ?? null;
            if (is_string($expectedHash) && $expectedHash !== '' && ! hash_equals($resolved->sourceHash, $expectedHash)) {
                throw new ImageGenerationException('This model was updated since you opened this form. Review and try again.', 409);
            }
            try {
                $validated = $this->validator->validate($resolved->capability, $rawInputs);
            } catch (CapabilityValidationException $e) {
                throw new ImageGenerationException((string) (array_values($e->errors())[0] ?? $e->getMessage()), 422);
            }
            $fingerprint = self::fingerprintPayload($operation, $model->model_id, $validated);
            if (! is_int($model->token_cost) || $model->token_cost < 1) {
                throw new ImageGenerationException('Audio token pricing is unavailable.', 503);
            }

            // Reject a capability/price the member's form was built on if it changed, BEFORE any reservation.
            $expectedPrice = $options['expected_price_tokens'] ?? null;
            if ($expectedPrice !== null && (int) $expectedPrice !== (int) $model->token_cost) {
                throw new ImageGenerationException('The price changed since you opened this form. Review and try again.', 409);
            }

            $config = MediaModelConfig::forModel($model);
            $mode = $operation === MediaOperation::TextToSpeech ? 'speech' : 'music';
            $prompt = trim((string) ($validated['inputs']['prompt'] ?? ''));
            $maxCharacters = min(4000, (int) ($config['max_characters'] ?? 0));
            if ($prompt === '' || mb_strlen($prompt) > $maxCharacters) {
                throw new ImageGenerationException('Enter a prompt within this audio model character limit.', 422);
            }
            $tempo = isset($validated['params']['tempo']) ? (int) $validated['params']['tempo'] : null;
            $providerPrompt = $prompt;
            if ($tempo !== null) {
                $providerPrompt .= "\nTempo guidance: approximately {$tempo} BPM.";
                if (mb_strlen($providerPrompt) > $maxCharacters) {
                    throw new ImageGenerationException('Shorten the prompt to leave room for the selected tempo guidance.', 422);
                }
            }
            $settings = array_intersect_key($validated['params'], array_flip(['instrumental', 'custom']));

            $routing = $model->upstream_model_id ?: $model->model_id;
            $jobId = (string) Str::uuid();
            $revision = $this->resolver->ensureRevision($model, $operation, $resolved);
            $reservation = $this->tokens->reserve($user, 'audio', $model->model_id, 1, "audio:{$jobId}", (int) $model->token_cost);

            $job = AudioJob::create([
                'user_id' => $user->id, 'job_id' => $jobId, 'model' => $model->model_id, 'mode' => $mode,
                'prompt' => $prompt, 'provider_prompt' => $providerPrompt,
                'voice' => $validated['params']['voice'] ?? null,
                'speed' => isset($validated['params']['speed']) ? (float) $validated['params']['speed'] : null,
                'duration' => isset($validated['params']['duration']) ? (int) $validated['params']['duration'] : null,
                'tempo' => $tempo,
                'provider_id' => $model->provider_id, 'upstream_model_id' => $routing,
                'connection_fingerprint' => $this->fingerprint($model), 'generation_config' => $config,
                'capability_revision_id' => $revision->id, 'routing_identity' => $routing,
                'price_tokens' => (int) $model->token_cost, 'dedup_key' => $dedup, 'payload_fingerprint' => $fingerprint,
                'settings' => $settings !== [] ? $settings : null,
                'status' => 'pending', 'stage' => 'queued',
                'billing_mode' => $reservation['billing_mode'], 'billing_reference_id' => $reservation['reference_id'],
                'billing_status' => 'reserved',
                'tokens_reserved' => $reservation['amount_tokens'],
                'next_poll_at' => now()->addMinute(),
            ]);
            ProcessAudioJob::dispatch($job->id)->onConnection('media')->onQueue('media')->afterCommit();

            return $job;
        });

        return $job;
    }

    public function startModel3d(User $user, AiModelProfile $model, MediaOperation $operation, array $rawInputs, string $entrypoint, array $options = []): array
    {
        return app(ThreeDGenerationService::class)->create($user, $model, $operation, $rawInputs, $entrypoint, $options);
    }

    /**
     * Validate the owner, media type, retention and bytes of each declared asset before
     * reservation. Input roles determine the media type; a photo cannot fill a speech
     * field. Compatible uploaded assets can be reused across operations.
     *
     * @param  array{inputs: array<string, mixed>, params: array<string, mixed>}  $validated
     * @return string[]
     */
    private function resolveReferenceAssets(User $user, MediaCapability $capability, array $validated, array $config = []): array
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
                // Asset ids are uuid columns: PostgreSQL errors on any other literal, so a malformed id is simply unknown.
                $asset = is_string($assetId) && Str::isUuid($assetId) ? MediaAsset::find($assetId) : null;
                if ($asset === null) {
                    throw new ImageGenerationException('A referenced asset could not be found.', 422);
                }
                try {
                    $this->assets->assertOwner($user, $asset);
                } catch (AuthorizationException) {
                    throw new ImageGenerationException('This reference is not available for your account.', 403);
                }
                $expectedType = match ($input->role) {
                    InputRole::SpeechAudio => 'audio',
                    InputRole::ReferenceVideo => 'video',
                    default => 'image',
                };
                if ($asset->media_type !== $expectedType) {
                    throw new ImageGenerationException('The reference must be '.$expectedType.'.', 422);
                }
                if (! $asset->signature_ok || $asset->retention_status !== 'active'
                    || ($asset->expires_at !== null && $asset->expires_at->isPast())
                    || ! Storage::disk($asset->storage_disk)->exists($asset->storage_path)) {
                    throw new ImageGenerationException('This reference is no longer available. Upload it again.', 422);
                }
                $maxBytes = $config['reference_'.$expectedType.'_max_bytes'] ?? null;
                if (is_int($maxBytes) && $asset->size_bytes > $maxBytes) {
                    throw new ImageGenerationException('This model accepts '.$expectedType.' references up to '.number_format($maxBytes / 1_000_000, 0).' MB.', 422);
                }
                $minimumSeconds = $expectedType === 'audio' ? ($config['reference_audio_min_seconds'] ?? null) : null;
                if (is_int($minimumSeconds) && $minimumSeconds > 0) {
                    try {
                        $longEnough = $this->assets->audioHasDuration($asset, $minimumSeconds);
                    } catch (\InvalidArgumentException $exception) {
                        throw new ImageGenerationException($exception->getMessage(), 422);
                    } catch (\RuntimeException) {
                        throw new ImageGenerationException('Audio inspection is temporarily unavailable. Try again later.', 503);
                    }
                    if (! $longEnough) {
                        throw new ImageGenerationException('This model requires at least '.$minimumSeconds.' seconds of audio.', 422);
                    }
                }
                $ids[] = $asset->id;
            }
        }

        return $ids;
    }

    /** Preserve the deployed normalized request format; revisions freeze its defaults. */
    public static function fingerprintPayload(MediaOperation $operation, string $model, array $validated): string
    {
        return hash('sha256', json_encode(['o' => $operation->value, 'm' => $model, 'p' => $validated], JSON_THROW_ON_ERROR));
    }

    public function matchesRequest(ImageJob|VideoJob|AudioJob $job, MediaOperation $operation, string $model, array $rawInputs, array $execution = []): bool
    {
        $revision = MediaCapabilityRevision::find($job->capability_revision_id);
        if ($revision === null || $revision->operation !== $operation->value || $job->model !== $model) {
            return false;
        }
        try {
            // Never resolve today's catalog here: defaults and valid choices may have changed.
            $values = $this->validator->validate(MediaCapability::fromArray($revision->definition), $rawInputs);
        } catch (CapabilityValidationException) {
            return false;
        }
        if (! hash_equals((string) $job->payload_fingerprint, self::fingerprintPayload($operation, $model, $values))) {
            return false;
        }

        return ! $job instanceof VideoJob || (
            $job->mode === ($execution['mode'] ?? 'prompt')
            && trim((string) ($job->settings['cta'] ?? '')) === ($execution['cta'] ?? '')
            && (bool) ($job->settings['ugc_variation'] ?? false) === (bool) ($execution['ugc_variation'] ?? false)
        );
    }

    /**
     * Fixed-form studio entrypoints execute only version-1 contracts; a schema contract or a
     * disabled operation is a member-facing availability error, never a server fault.
     */
    private function resolveNative(AiModelProfile $model, MediaOperation $operation): ResolvedCapability
    {
        try {
            return $this->resolver->resolve($model, $operation);
        } catch (CapabilityConfigException) {
            throw new ImageGenerationException('This model operation is unavailable in this studio. Open it from the media workspace.', 503);
        }
    }

    private function fingerprint(AiModelProfile $model): string
    {
        return hash('sha256', json_encode(array_intersect_key($model->provider->getRawOriginal(),
            array_flip(['base_url', 'api_key', 'protocol', 'api_version'])), JSON_THROW_ON_ERROR));
    }
}
