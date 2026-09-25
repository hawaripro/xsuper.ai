<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Exceptions\ImageGenerationException;
use App\Jobs\PollImageJob;
use App\Jobs\ProcessImageJob;
use App\Media\CapabilityResolver;
use App\Media\Contracts\StagesProviderReferences;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\MediaState;
use App\Media\Enums\OutputKind;
use App\Media\Enums\SubmitOutcome;
use App\Media\Exceptions\CapabilityConfigException;
use App\Media\MediaActivation;
use App\Media\MediaAdapterRegistry;
use App\Media\MediaCapability;
use App\Media\MediaGenerationCoordinator;
use App\Media\StatusResult;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ImageGenerationService
{
    private const POLL_DELAY_SECONDS = 8;

    private const POLL_LEASE_SECONDS = 300;

    private const UNCERTAIN_MESSAGE = 'The image provider did not confirm that it accepted this request. Reserved tokens stay reserved and the request will not be submitted again.';

    public function __construct(
        private readonly AiProxyService $proxy,
        private readonly UsageBillingService $billing,
        private readonly MediaTokenBillingService $tokens,
        private readonly GeneratedImageStore $images,
        private readonly AiProviderTransport $transport,
        private readonly MediaGenerationCoordinator $coordinator,
        private readonly MediaAdapterRegistry $adapters,
        private readonly MediaActivation $activation,
    ) {}

    public function generate(User $user, string $model, string $prompt, string $size, int $quantity, array $options = []): ImageJob
    {
        $key = isset($options['idempotency_key']) && is_string($options['idempotency_key']) ? trim($options['idempotency_key']) : '';
        $dedup = $key !== '' ? hash('sha256', $user->id.'|studio-image|'.$key) : null;
        $payloadFingerprint = $dedup !== null ? hash('sha256', json_encode([
            'model' => $model, 'prompt' => $prompt, 'size' => $size, 'quantity' => $quantity,
            'operation' => $options['operation'] ?? 'text_to_image', 'reference_image' => $options['reference_image'] ?? null,
        ], JSON_THROW_ON_ERROR)) : null;
        if ($dedup !== null) {
            // Replay by the saved contract before consulting today's routing, price or pause.
            $existing = ImageJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)->first();
            if ($existing !== null) {
                return $this->replay($existing, $payloadFingerprint, $model, $prompt, $size, $quantity, $options);
            }
        }
        $profile = AiModelProfile::query()->with('provider')->where('model_id', $model)->first();
        if ($profile !== null && MediaModelConfig::hasCatalogImage($profile)) {
            if (! $this->activation->usesCoordinator($user)) {
                throw new ImageGenerationException('This operation is not available for your account yet.', 503);
            }
            if ($quantity !== 1) {
                throw new ImageGenerationException('This image capability supports one generation per request.', 422);
            }
            $operation = MediaOperation::tryFrom($options['operation'] ?? 'text_to_image');
            if (! in_array($operation, [MediaOperation::TextToImage, MediaOperation::ImageEdit], true)) {
                throw new ImageGenerationException('The selected image operation is unavailable.', 422);
            }
            try {
                $resolved = app(CapabilityResolver::class)->resolve($profile, $operation);
                $raw = ['prompt' => $prompt];
                if ($size !== 'auto' || in_array('auto', $resolved->capability->param('size')?->options ?? [], true)) {
                    $raw['size'] = $size;
                }
                if (isset($options['reference_image'])) {
                    $raw['reference_image'] = $options['reference_image'];
                }

                return $this->coordinator->startImage($user, $profile, $operation, $raw, 'studio-image', $options);
            } catch (CapabilityConfigException) {
                throw new ImageGenerationException('The selected image capability is unavailable. Contact an administrator.', 503);
            }
        }
        if ($profile?->provider?->protocol === 'kinovi') {
            // Route before reservation. Each path checks the pause only after replaying accepted work.
            if ($this->activation->usesCoordinator($user)) {
                $operation = ($options['operation'] ?? null) === 'image_edit' ? MediaOperation::ImageEdit : MediaOperation::TextToImage;
                $rawInputs = ['prompt' => $prompt, 'size' => $size];
                $reference = $options['reference_image'] ?? null;
                if (is_string($reference) && $reference !== '') {
                    $rawInputs['reference_image'] = $reference;
                }

                return $this->coordinator->startImage($user, $profile, $operation, $rawInputs, 'studio-image', $options);
            }
            // Legacy (non-coordinator) path runs text-to-image only; reject any other operation here
            // rather than silently dropping a requested reference.
            if (($options['operation'] ?? 'text_to_image') !== 'text_to_image') {
                throw new ImageGenerationException('This operation is not available for your account yet.', 503);
            }

            return $this->createAsync($user, $profile, $prompt, $size, $quantity, $options, $dedup, $payloadFingerprint);
        }
        [$job, $reservation] = DB::transaction(function () use ($model, $prompt, $quantity, $size, $user, $options, $dedup, $payloadFingerprint): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($dedup !== null) {
                $existing = ImageJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)->first();
                if ($existing !== null) {
                    return [$this->replay($existing, $payloadFingerprint, $model, $prompt, $size, $quantity, $options), null];
                }
            }
            $profile = AiModelProfile::query()->with('provider')->where('model_id', $model)->lockForUpdate()->first();
            if (! $profile || $profile->category !== 'image' || ! MediaModelConfig::allowedFor($user, $profile)) {
                throw new ImageGenerationException('The selected image model is unavailable.', 503);
            }
            $config = MediaModelConfig::forModel($profile);
            if ($quantity < 1 || $quantity > $config['max_quantity']
                || ($config['supports_size'] && ! in_array($size, $config['sizes'], true))) {
                throw new ImageGenerationException('The selected image options are not supported by this model.', 422);
            }
            $this->assertLegacyAdmission($profile, $options);
            $jobId = (string) Str::uuid();
            $referenceId = "image:{$jobId}";
            $reservation = $this->tokens->reserve($user, 'image', $model, $quantity, $referenceId, (int) $profile->token_cost);
            $job = ImageJob::create([
                'user_id' => $user->id, 'job_id' => $jobId, 'model' => $model, 'prompt' => $prompt,
                'provider_id' => $profile->provider_id, 'upstream_model_id' => $profile->upstream_model_id ?: $model,
                'connection_fingerprint' => self::fingerprint($profile->provider), 'generation_config' => $config,
                'price_tokens' => (int) $profile->token_cost, 'dedup_key' => $dedup, 'payload_fingerprint' => $payloadFingerprint,
                'size' => $config['supports_size'] ? $size : 'auto', 'quantity' => $quantity,
                'status' => 'processing', 'stage' => 'generating', 'billing_reserved_microusd' => 0,
                'billing_reference_id' => $referenceId, 'billing_status' => 'reserved',
                'billing_mode' => $reservation['billing_mode'], 'tokens_reserved' => $reservation['amount_tokens'],
                'processing_started_at' => now(), 'processing_token' => (string) Str::uuid(),
            ]);

            return [$job, $reservation];
        });
        if ($reservation === null) {
            return $job;
        }

        try {
            $heartbeat = function () use ($job): void {
                DB::transaction(function () use ($job): void {
                    $active = ImageJob::query()->lockForUpdate()->find($job->id);
                    if (! $this->ownsClaim($active, $job) || $active->billing_status !== 'reserved') {
                        throw new AiProxyException('The image request is no longer active.', 409);
                    }
                    $active->forceFill(['processing_started_at' => now(), 'updated_at' => now()])->save();
                });
            };
            $items = $this->proxy->generateImages($model, $prompt, $size, $quantity, $heartbeat);
            $saving = $this->claimForSaving($job, [], ['data' => $items]);
            if ($saving !== null) {
                $this->complete($saving, []);
            }

            return $job->fresh();
        } catch (AiProxyException $exception) {
            $failed = $this->failAndRelease($job, $reservation, $exception->getMessage());
            throw new ImageGenerationException($exception->getMessage(), $exception->responseStatus(), $failed);
        } catch (Throwable) {
            $message = 'The AI image provider returned an invalid response.';
            $failed = $this->failAndRelease($job, $reservation, $message);
            throw new ImageGenerationException($message, 502, $failed);
        }
    }

    /**
     * Non-pilot Kinovi jobs keep the shared async pipeline without a capability revision.
     * Admission still serializes replay, pause and quote checks before reserving any tokens.
     */
    private function createAsync(User $user, AiModelProfile $profile, string $prompt, string $size, int $quantity, array $options, ?string $dedup, ?string $payloadFingerprint): ImageJob
    {
        return DB::transaction(function () use ($user, $profile, $prompt, $size, $quantity, $options, $dedup, $payloadFingerprint): ImageJob {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($dedup !== null) {
                $existing = ImageJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $payloadFingerprint, $profile->model_id, $prompt, $size, $quantity, $options);
                }
            }
            $model = AiModelProfile::query()->with('provider')->where('model_id', $profile->model_id)->lockForUpdate()->first();
            if (! $model || $model->category !== 'image' || ! MediaModelConfig::allowedFor($user, $model)) {
                throw new ImageGenerationException('The selected image model is unavailable.', 503);
            }
            if (! $model->provider || $model->provider->protocol !== 'kinovi') {
                throw new ImageGenerationException('The selected image model is unavailable.', 503);
            }
            $config = MediaModelConfig::forModel($model);
            if ($quantity !== 1 || (($config['supports_size'] ?? false) && ! in_array($size, $config['sizes'], true))) {
                throw new ImageGenerationException('The selected image options are not supported by this model.', 422);
            }
            $this->assertLegacyAdmission($model, $options);
            $jobId = (string) Str::uuid();
            $reservation = $this->tokens->reserve($user, 'image', $model->model_id, 1, "image:{$jobId}", (int) $model->token_cost);
            $job = ImageJob::create([
                'user_id' => $user->id, 'job_id' => $jobId, 'model' => $model->model_id,
                'provider_id' => $model->provider_id, 'upstream_model_id' => $model->upstream_model_id ?: $model->model_id,
                'connection_fingerprint' => self::fingerprint($model->provider), 'generation_config' => $config,
                'capability_revision_id' => null, 'routing_identity' => $model->upstream_model_id ?: $model->model_id,
                'price_tokens' => (int) $model->token_cost, 'dedup_key' => $dedup, 'payload_fingerprint' => $payloadFingerprint,
                'prompt' => $prompt, 'size' => ($config['supports_size'] ?? false) ? $size : 'auto', 'quantity' => 1,
                'status' => 'pending', 'stage' => 'queued', 'billing_reserved_microusd' => 0,
                'billing_reference_id' => "image:{$jobId}", 'billing_status' => 'reserved',
                'billing_mode' => $reservation['billing_mode'], 'tokens_reserved' => $reservation['amount_tokens'],
                'next_poll_at' => now()->addMinute(),
            ]);
            ProcessImageJob::dispatch($job->id)->onConnection('media')->onQueue('media')->afterCommit();

            return $job;
        });
    }

    private function replay(ImageJob $job, string $payloadFingerprint, string $model, string $prompt, string $size, int $quantity, array $options): ImageJob
    {
        $matches = hash_equals((string) $job->payload_fingerprint, $payloadFingerprint);
        if ($job->capability_revision_id !== null) {
            $operation = MediaOperation::tryFrom($options['operation'] ?? 'text_to_image');
            $revision = MediaCapabilityRevision::find($job->capability_revision_id);
            $matches = false;
            if ($quantity === 1 && $operation !== null && $revision !== null) {
                $capability = MediaCapability::fromArray($revision->definition);
                $raw = ['prompt' => $prompt];
                if ($size !== 'auto' || in_array('auto', $capability->param('size')?->options ?? [], true)) {
                    $raw['size'] = $size;
                }
                if (isset($options['reference_image'])) {
                    $raw['reference_image'] = $options['reference_image'];
                }
                $matches = $this->coordinator->matchesRequest($job, $operation, $model, $raw);
            }
        }
        if (! $matches) {
            throw new ImageGenerationException('This request key was already used with different input.', 409);
        }

        return $job;
    }

    private function assertLegacyAdmission(AiModelProfile $model, array $options): void
    {
        $this->activation->assertNotPaused();
        if (($options['operation'] ?? 'text_to_image') !== 'text_to_image' || ! empty($options['reference_image'])) {
            throw new ImageGenerationException('The selected image operation is unavailable.', 422);
        }
        if (! is_int($model->token_cost) || $model->token_cost < 1) {
            throw new ImageGenerationException('Image token pricing is unavailable.', 503);
        }
        $expectedPrice = $options['expected_price_tokens'] ?? null;
        if ($expectedPrice !== null && (int) $expectedPrice !== $model->token_cost) {
            throw new ImageGenerationException('The price changed since you opened this form. Review and try again.', 409);
        }
        $expectedHash = $options['expected_capability_hash'] ?? null;
        if (is_string($expectedHash) && $expectedHash !== '') {
            try {
                $resolved = app(CapabilityResolver::class)->resolve($model, MediaOperation::TextToImage);
            } catch (CapabilityConfigException) {
                throw new ImageGenerationException('The selected image capability is unavailable. Contact an administrator.', 503);
            }
            if (! hash_equals($resolved->sourceHash, $expectedHash)) {
                throw new ImageGenerationException('This model was updated since you opened this form. Review and try again.', 409);
            }
        }
    }

    public function reconcileStaleReservations(int $minutes): int
    {
        $reconciled = 0;
        $staleBefore = now()->subMinutes($minutes);
        ImageJob::query()
            ->where('status', 'processing')
            ->where('billing_status', 'reserved')
            // Unknown acceptance and recoverable output saves retain their reservations.
            ->whereNotIn('stage', ['submission_uncertain', 'save_failed'])
            ->where('updated_at', '<=', $staleBefore)
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use (&$reconciled, $staleBefore): void {
                foreach ($jobs as $job) {
                    $changed = DB::transaction(function () use ($job, $staleBefore): bool {
                        User::query()->whereKey($job->user_id)->lockForUpdate()->firstOrFail();
                        $locked = ImageJob::query()->lockForUpdate()->find($job->id);
                        if (! $locked || $locked->status !== 'processing' || $locked->billing_status !== 'reserved'
                            || $locked->updated_at->gt($staleBefore)) {
                            return false;
                        }
                        if ($locked->processing_started_at !== null && $locked->processing_started_at->gt(now()->subSeconds(self::POLL_LEASE_SECONDS))) {
                            return false;
                        }
                        if (in_array($locked->stage, ['submission_uncertain', 'save_failed'], true)) {
                            return false;
                        }
                        if ($locked->stage === 'submitting') {
                            // The claim expired mid-submission, so the paid request may have been accepted.
                            // The claim token stays: a still-running worker can record its authoritative outcome.
                            $locked->update(['stage' => 'submission_uncertain', 'error_message' => self::UNCERTAIN_MESSAGE,
                                'processing_started_at' => null, 'next_poll_at' => null]);

                            return true;
                        }
                        if ((! empty($locked->provider_result_urls) || ! empty($locked->provider_result_data))
                            && ($locked->submitted_at ?? $locked->created_at)->gte(now()->subMinutes(30))) {
                            // The provider already completed. Recover only private output storage, never submit again.
                            $locked->update(['stage' => 'rendering', 'processing_started_at' => null, 'processing_token' => null, 'next_poll_at' => now()]);
                            DB::afterCommit(fn () => $this->queuePoll($locked->id));

                            return false;
                        }
                        if (! empty($locked->provider_result_urls) || ! empty($locked->provider_result_data)) {
                            $locked->update(['stage' => 'save_failed', 'error_message' => 'The original image result could not be saved. Retry saving without generating again.',
                                'processing_started_at' => null, 'processing_token' => null, 'next_poll_at' => null]);

                            return true;
                        }

                        if (in_array($locked->billing_mode, ['tokens', 'admin'], true)) {
                            $this->tokens->release($locked->user_id, [
                                'reference_id' => $locked->billing_reference_id,
                                'amount_tokens' => $locked->tokens_reserved,
                            ], 'Timed out image generation');
                        } else {
                            $this->billing->releaseUnit($locked->user_id, [
                                'reference_id' => $locked->billing_reference_id,
                                'amount_microusd' => $locked->billing_reserved_microusd,
                            ], 'Timed out image generation');
                        }
                        $locked->update([
                            'status' => 'failed', 'stage' => 'failed',
                            'result_urls' => null,
                            'provider_result_urls' => null, 'provider_result_data' => null,
                            'error_message' => 'Image generation timed out.',
                            'billing_status' => 'released',
                        ]);

                        return true;
                    });
                    $reconciled += $changed ? 1 : 0;
                }
            });

        return $reconciled;
    }

    private function failAndRelease(ImageJob $job, array $reservation, string $message): ImageJob
    {
        return DB::transaction(function () use ($job, $reservation, $message): ImageJob {
            User::query()->whereKey($job->user_id)->lockForUpdate()->firstOrFail();
            $locked = ImageJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($locked->status !== 'processing' || $locked->billing_status !== 'reserved'
                || ($job->processing_token !== null && ! hash_equals((string) $locked->processing_token, $job->processing_token))) {
                return $locked;
            }
            $this->tokens->release($locked->user_id, $reservation, 'Failed image generation');
            $locked->update([
                'status' => 'failed', 'stage' => 'failed',
                'result_urls' => null,
                'provider_result_urls' => null, 'provider_result_data' => null,
                'error_message' => $message,
                'billing_status' => 'released',
            ]);

            return $locked->fresh();
        });
    }

    public function process(int $id): void
    {
        $job = DB::transaction(function () use ($id): ?ImageJob {
            $job = ImageJob::query()->lockForUpdate()->find($id);
            if (! $job || $job->status !== 'pending' || $job->stage !== 'queued'
                || $job->submitted_at !== null || $job->upstream_job_id !== null) {
                return null;
            }
            $job->update([
                // Preparation (request building, reference uploads) has no paid side effect; see the submitting mark below.
                'status' => 'processing', 'stage' => 'preparing', 'processing_started_at' => now(),
                'processing_token' => (string) Str::uuid(), 'next_poll_at' => null,
            ]);

            return $job;
        });
        if (! $job) {
            return;
        }
        try {
            $provider = $this->provider($job);
            $adapter = $this->adapters->for($provider->protocol);
            $inputs = ['prompt' => $job->prompt];
            if (! empty($job->reference_asset_ids)) {
                $inputs['reference_image'] = $job->reference_asset_ids;
            }
            $capability = $this->capabilityForJob($job);
            $hasSize = $job->capability_revision_id ? $capability->param('size') !== null : $job->size !== 'auto';
            $request = $adapter->buildRequest($capability, [
                'inputs' => $inputs,
                'params' => $hasSize ? ['size' => $job->size] : [],
                'owner_id' => $job->user_id, 'generation_config' => $job->generation_config,
            ], (string) $job->upstream_model_id);
        } catch (Throwable) {
            // Nothing has been sent upstream, so the request cannot have been charged by the provider.
            $this->failClaim($job, 'The saved image connection or inputs are no longer available. No generation was submitted and reserved tokens have been returned.');

            return;
        }
        if ($adapter instanceof StagesProviderReferences) {
            try {
                $request = $adapter->stageReferences($provider, $request);
            } catch (Throwable) {
                $this->failClaim($job, 'The reference file could not be staged. No generation was submitted and reserved tokens have been returned.');

                return;
            }
        }
        // Only now can a paid request be accepted: a claim that expires from here on is an unknown acceptance.
        $job = DB::transaction(function () use ($job): ?ImageJob {
            $locked = ImageJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return null;
            }
            $locked->update(['stage' => 'submitting', 'processing_started_at' => now()]);

            return $locked;
        });
        if (! $job) {
            return;
        }
        try {
            $submit = $adapter->submit($provider, $request);
        } catch (Throwable) {
            // Acceptance is unknown after an unexpected submit error: keep the reservation, never resubmit.
            $this->markUncertain($job);

            return;
        }

        if ($submit->outcome === SubmitOutcome::Rejected) {
            $this->failClaim($job, 'The image provider rejected this request. Reserved tokens have been returned.');

            return;
        }
        if ($submit->outcome === SubmitOutcome::Uncertain) {
            // Timeouts and successful HTTP responses without a usable acknowledgement alike.
            $this->markUncertain($job);

            return;
        }
        if ($submit->outcome === SubmitOutcome::Immediate) {
            if (($submit->resultUrls ?? []) === [] && empty($submit->resultData['data'])) {
                // A successful response without any usable image must be neither settled nor refunded.
                $this->markUncertain($job);

                return;
            }
            $saving = $this->claimForSaving($job, $submit->resultUrls ?? [], $submit->resultData);
            if ($saving) {
                try {
                    $this->finalize($saving, $submit->resultUrls ?? []);
                } catch (Throwable) {
                    $this->revertSavingToRendering($saving);
                }
            }

            return;
        }
        if (! is_string($submit->taskId) || $submit->taskId === '') {
            // An acceptance without a task handle cannot be polled, so acceptance remains unknown.
            $this->markUncertain($job);

            return;
        }
        // Accepted as an async job.
        $submitted = DB::transaction(function () use ($job, $submit): bool {
            $locked = ImageJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return false;
            }
            $locked->update([
                'upstream_job_id' => $submit->taskId, 'submitted_at' => now(), 'stage' => 'rendering',
                'processing_started_at' => null, 'processing_token' => null, 'error_message' => null,
                'next_poll_at' => now()->addSeconds(self::POLL_DELAY_SECONDS),
            ]);

            return true;
        });
        if ($submitted) {
            $this->queuePoll($job->id);
        }
    }

    public function poll(int $id): void
    {
        $job = DB::transaction(function () use ($id): ?ImageJob {
            $job = ImageJob::query()->lockForUpdate()->find($id);
            if (! $job || $job->status !== 'processing' || ! in_array($job->stage, ['rendering', 'saving'], true)
                || (! $job->upstream_job_id && empty($job->provider_result_urls) && empty($job->provider_result_data))
                || ($job->next_poll_at !== null && $job->next_poll_at->isFuture())
                || ($job->processing_started_at !== null && $job->processing_started_at->gt(now()->subSeconds(self::POLL_LEASE_SECONDS)))) {
                return null;
            }
            $job->update([
                'processing_started_at' => now(), 'processing_token' => (string) Str::uuid(),
                'next_poll_at' => now()->addSeconds(self::POLL_LEASE_SECONDS), 'poll_attempts' => $job->poll_attempts + 1,
            ]);

            return $job;
        });
        if (! $job) {
            return;
        }
        if (empty($job->provider_result_urls) && empty($job->provider_result_data)
            && ($job->submitted_at ?? $job->created_at)->lt(now()->subMinutes(30))) {
            $this->failClaim($job, 'Image generation timed out. Reserved tokens have been returned.');

            return;
        }
        if ($job->stage !== 'saving' && (! empty($job->provider_result_urls) || ! empty($job->provider_result_data))
            && ($job->submitted_at ?? $job->created_at)->lt(now()->subMinutes(30))) {
            $this->deferSavedResult($job, 'The original image result could not be saved. Retry saving without generating again.');

            return;
        }
        if (! empty($job->provider_result_urls) || ! empty($job->provider_result_data)) {
            $status = StatusResult::completed($job->provider_result_urls ?? [], $job->provider_result_data);
        } else {
            try {
                $provider = $this->provider($job);
            } catch (Throwable) {
                $this->failClaim($job, 'The image connection changed or became unavailable. Reserved tokens have been returned.');

                return;
            }
            try {
                $status = $this->adapters->for($provider->protocol)->pollStatus($provider, (string) $job->upstream_job_id, $this->capabilityForJob($job)->providerBindings);
            } catch (Throwable) {
                $this->rescheduleClaim($job);

                return;
            }
        }
        if ($status->state === MediaState::Failed) {
            $this->failClaim($job, 'The image provider could not complete this request. Reserved tokens have been returned.');

            return;
        }
        if ($status->state === MediaState::Completed) {
            $urls = $status->resultUrls ?? [];
            if ($urls === [] && empty($status->resultData['data'])) {
                $this->rescheduleClaim($job);

                return;
            }
            $saving = $this->claimForSaving($job, $urls, $status->resultData);
            if ($saving) {
                try {
                    $this->finalize($saving, $urls);
                } catch (Throwable) {
                    // Provider finished but saving the output failed: retry FINALIZATION only
                    // (re-poll -> re-download), never a new paid generation; bounded by the timeout.
                    $this->revertSavingToRendering($saving);
                }
            }

            return;
        }
        // Still processing.
        $this->rescheduleClaim($job);
    }

    /**
     * Recover coordinator jobs whose ProcessImageJob dispatch failed (e.g. queue down at
     * submit time): re-dispatch the idempotent processor. process() only acts on a still
     * queued job, so a re-dispatch never double-submits an already-claimed job.
     */
    public function redispatchStalePending(int $minutes = 5): int
    {
        $count = 0;
        ImageJob::query()
            ->where('status', 'pending')->where('stage', 'queued')
            ->whereNull('upstream_job_id')->whereNull('submitted_at')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use (&$count): void {
                foreach ($jobs as $job) {
                    ProcessImageJob::dispatch($job->id)->onConnection('media')->onQueue('media');
                    $count++;
                }
            });

        return $count;
    }

    public function failSubmission(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $job = ImageJob::query()->lockForUpdate()->find($id);
            if (! $job || $job->submitted_at !== null || $job->upstream_job_id !== null
                || $job->status !== 'pending' || $job->stage !== 'queued') {
                return;
            }
            $this->terminate($job, 'The image request was interrupted. Reserved tokens have been returned. It was not automatically resubmitted.');
        });
    }

    private function complete(ImageJob $job, array $urls): void
    {
        $items = $job->provider_result_data['data'] ?? array_map(static fn (string $url): array => ['url' => $url], array_values($urls));
        $stored = $this->images->persist($job, $items, function () use ($job): void {
            $updated = ImageJob::query()->whereKey($job->id)->where('status', 'processing')->where('stage', 'saving')
                ->where('processing_token', $job->processing_token)->update(['processing_started_at' => now()]);
            if ($updated !== 1) {
                throw new AiProxyException('The image request is no longer active.', 409);
            }
        });
        $assets = $job->asset_paths;
        $retained = false;
        $saveError = null;
        try {
            $retained = DB::transaction(function () use ($job, $stored, $assets): bool {
                $owner = User::query()->whereKey($job->user_id)->lockForUpdate()->firstOrFail();
                $locked = ImageJob::query()->lockForUpdate()->find($job->id);
                if (! $this->ownsClaim($locked, $job)) {
                    return $locked?->status === 'completed';
                }
                $bytes = 0;
                foreach (GeneratedImageStore::outputs($job) as $asset) {
                    $bytes += Storage::disk('local')->size($asset['path']);
                }
                app(StorageQuotaService::class)->assertCanStore($owner, $bytes);
                $this->tokens->settle($locked->user_id, [
                    'reference_id' => $locked->billing_reference_id, 'amount_tokens' => $locked->tokens_reserved,
                ], ['service' => 'image', 'model' => $locked->model]);
                $locked->update([
                    'status' => 'completed', 'stage' => 'completed', 'result_urls' => $stored, 'asset_paths' => $assets,
                    'error_message' => null, 'billing_status' => 'settled', 'completed_at' => now(),
                    'provider_result_urls' => null, 'provider_result_data' => null,
                    'next_poll_at' => null, 'processing_started_at' => null, 'processing_token' => null,
                ]);

                return true;
            });
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 413) {
                throw $exception;
            }
            $saveError = $exception->getMessage();
        } finally {
            if (! $retained) {
                GeneratedImageStore::discard($job);
            }
        }
        if ($saveError !== null) {
            $this->deferSavedResult($job, $saveError);
        }
    }

    private function deferSavedResult(ImageJob $job, string $message): void
    {
        DB::transaction(function () use ($job, $message): void {
            $locked = ImageJob::query()->lockForUpdate()->find($job->id);
            if ($this->ownsClaim($locked, $job)) {
                $locked->update(['stage' => 'save_failed', 'error_message' => $message,
                    'processing_started_at' => null, 'processing_token' => null, 'next_poll_at' => null]);
            }
        });
    }

    private function finalize(ImageJob $job, array $urls): void
    {
        $this->complete($job, $urls);
    }

    private function claimForSaving(ImageJob $job, array $resultUrls, mixed $resultData = null): ?ImageJob
    {
        return DB::transaction(function () use ($job, $resultUrls, $resultData): ?ImageJob {
            $locked = ImageJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return null;
            }
            $locked->update([
                'stage' => 'saving', 'processing_started_at' => now(), 'next_poll_at' => null, 'error_message' => null,
                'provider_result_urls' => $resultUrls, 'provider_result_data' => is_array($resultData) ? $resultData : null,
                'submitted_at' => $locked->submitted_at ?? now(),
            ]);

            return $locked;
        });
    }

    /** Unknown provider acceptance keeps the reservation; it is never refunded or resubmitted automatically. */
    private function markUncertain(ImageJob $claim): void
    {
        DB::transaction(function () use ($claim): void {
            $locked = ImageJob::query()->lockForUpdate()->find($claim->id);
            if (! $this->ownsClaim($locked, $claim)) {
                return;
            }
            $locked->update(['stage' => 'submission_uncertain', 'error_message' => self::UNCERTAIN_MESSAGE,
                'processing_started_at' => null, 'processing_token' => null, 'next_poll_at' => null]);
        });
    }

    private function rescheduleClaim(ImageJob $job): void
    {
        $released = DB::transaction(function () use ($job): bool {
            $locked = ImageJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return false;
            }
            $locked->update([
                'processing_started_at' => null, 'processing_token' => null,
                'next_poll_at' => now()->addSeconds(self::POLL_DELAY_SECONDS),
            ]);

            return true;
        });
        if ($released) {
            $this->queuePoll($job->id);
        }
    }

    private function revertSavingToRendering(ImageJob $job): void
    {
        $reverted = DB::transaction(function () use ($job): bool {
            $locked = ImageJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return false;
            }
            $locked->update([
                'stage' => 'rendering', 'processing_started_at' => null, 'processing_token' => null,
                'next_poll_at' => now()->addSeconds(self::POLL_DELAY_SECONDS),
            ]);

            return true;
        });
        if ($reverted) {
            $this->queuePoll($job->id);
        }
    }

    private function capabilityForJob(ImageJob $job): MediaCapability
    {
        if ($job->capability_revision_id) {
            $revision = MediaCapabilityRevision::query()->find($job->capability_revision_id);
            if ($revision !== null && is_array($revision->definition)) {
                return MediaCapability::fromArray([...$revision->definition, 'provider_bindings' => $revision->provider_bindings ?? []]);
            }
        }

        return new MediaCapability($job->model, MediaOperation::TextToImage, OutputKind::Image, 1);
    }

    private function failClaim(ImageJob $claim, string $message): void
    {
        DB::transaction(function () use ($claim, $message): void {
            User::query()->whereKey($claim->user_id)->lockForUpdate()->firstOrFail();
            $job = ImageJob::query()->lockForUpdate()->find($claim->id);
            if (! $this->ownsClaim($job, $claim)) {
                return;
            }
            $this->terminate($job, $message);
        });
    }

    private function terminate(ImageJob $job, string $message): void
    {
        if (! in_array($job->status, ['pending', 'processing'], true)) {
            return;
        }
        $this->tokens->release($job->user_id, [
            'reference_id' => $job->billing_reference_id, 'amount_tokens' => $job->tokens_reserved,
        ], 'Image generation did not complete');
        $job->update([
            'status' => 'failed', 'stage' => 'failed', 'error_message' => $message, 'result_urls' => null,
            'provider_result_urls' => null, 'provider_result_data' => null,
            'billing_status' => 'released', 'next_poll_at' => null, 'processing_started_at' => null,
            'processing_token' => null, 'completed_at' => now(),
        ]);
    }

    private function ownsClaim(?ImageJob $current, ImageJob $claim): bool
    {
        // A submission that reconciliation marked uncertain may still record its own late, authoritative outcome.
        return $current !== null && $current->status === 'processing'
            && ($current->stage === $claim->stage || ($claim->stage === 'submitting' && $current->stage === 'submission_uncertain'))
            && is_string($current->processing_token) && is_string($claim->processing_token)
            && hash_equals($current->processing_token, $claim->processing_token);
    }

    private function provider(ImageJob $job): AiProviderProfile
    {
        $provider = AiProviderProfile::query()->find($job->provider_id);
        if (! $provider || ! $provider->is_enabled || ! $this->adapters->has($provider->protocol)
            || ! hash_equals((string) $job->connection_fingerprint, self::fingerprint($provider))) {
            throw new AiProxyException('The image connection changed. No request was sent to a different provider.', 503);
        }

        return $provider;
    }

    private static function fingerprint(AiProviderProfile $provider): string
    {
        return hash('sha256', json_encode(array_intersect_key($provider->getRawOriginal(),
            array_flip(['base_url', 'api_key', 'protocol', 'api_version'])), JSON_THROW_ON_ERROR));
    }

    private function queuePoll(int $id): bool
    {
        try {
            PollImageJob::dispatch($id)->onConnection('media')->onQueue('media')
                ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS))->afterCommit();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
