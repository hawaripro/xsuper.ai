<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Exceptions\ImageGenerationException;
use App\Media\Enums\MediaOperation;
use App\Media\MediaGenerationCoordinator;
use App\Media\Enums\MediaState;
use App\Media\Enums\OutputKind;
use App\Media\Enums\SubmitOutcome;
use App\Media\MediaAdapterRegistry;
use App\Media\MediaActivation;
use App\Media\MediaCapability;
use App\Models\MediaCapabilityRevision;
use App\Jobs\PollImageJob;
use App\Jobs\ProcessImageJob;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImageGenerationService
{
    private const POLL_DELAY_SECONDS = 8;

    private const POLL_LEASE_SECONDS = 300;

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
        $profile = AiModelProfile::query()->with('provider')->where('model_id', $model)->first();
        if ($profile?->provider?->protocol === 'kinovi') {
            // Route BEFORE any reservation. Kill switch pauses all new Kinovi submissions; the
            // pilot (or everyone, once unrestricted) uses the capability coordinator, while other
            // members keep the existing verified async path. No cross-path retry (no double charge).
            $this->activation->assertNotPaused();
            if ($this->activation->usesCoordinator($user)) {
                return $this->coordinator->startImage($user, $profile, MediaOperation::TextToImage, ['prompt' => $prompt, 'size' => $size], 'studio-image', $options);
            }

            return $this->createAsync($user, $profile, $prompt, $size);
        }
        $jobId = (string) Str::uuid();
        $referenceId = "image:{$jobId}";
        [$job, $reservation] = DB::transaction(function () use ($jobId, $model, $prompt, $quantity, $referenceId, $size, $user): array {
            $profile = AiModelProfile::query()->with('provider')->where('model_id', $model)->lockForUpdate()->first();
            if (! $profile || $profile->category !== 'image' || ! MediaModelConfig::allowedFor($user, $profile)) {
                throw new ImageGenerationException('The selected image model is unavailable.', 503);
            }
            $config = MediaModelConfig::forModel($profile);
            if ($quantity < 1 || $quantity > $config['max_quantity']
                || ($config['supports_size'] && ! in_array($size, $config['sizes'], true))) {
                throw new ImageGenerationException('The selected image options are not supported by this model.', 422);
            }
            if (! is_int($profile->token_cost) || $profile->token_cost < 1) {
                throw new ImageGenerationException('Image token pricing is unavailable.', 503);
            }
            $reservation = $this->tokens->reserve($user, 'image', $model, $quantity, $referenceId, (int) $profile->token_cost);
            $job = ImageJob::create([
                'user_id' => $user->id, 'job_id' => $jobId, 'model' => $model, 'prompt' => $prompt,
                'size' => $config['supports_size'] ? $size : 'auto', 'quantity' => $quantity,
                'status' => 'processing', 'stage' => 'generating', 'billing_reserved_microusd' => 0,
                'billing_reference_id' => $referenceId, 'billing_status' => 'reserved',
                'billing_mode' => $reservation['billing_mode'], 'tokens_reserved' => $reservation['amount_tokens'],
            ]);

            return [$job, $reservation];
        });

        try {
            $heartbeat = function (string $stage) use ($job): void {
                DB::transaction(function () use ($job, $stage): void {
                    $active = ImageJob::query()->lockForUpdate()->find($job->id);
                    if (! $active || $active->status !== 'processing' || $active->billing_status !== 'reserved') {
                        throw new AiProxyException('The image request is no longer active.', 409);
                    }
                    $active->forceFill(['stage' => $stage, 'updated_at' => now()])->save();
                });
            };
            $items = $this->proxy->generateImages($model, $prompt, $size, $quantity, fn () => $heartbeat('generating'));
            $urls = $this->images->persist($job, $items, fn () => $heartbeat('saving'));

            return DB::transaction(function () use ($job, $reservation, $urls): ImageJob {
                $locked = ImageJob::query()->lockForUpdate()->findOrFail($job->id);
                if ($locked->status !== 'processing' || $locked->billing_status !== 'reserved') {
                    foreach ($job->asset_paths ?? [] as $asset) {
                        Storage::disk('local')->delete($asset['path']);
                    }

                    return $locked;
                }
                $this->tokens->settle($locked->user_id, $reservation, ['service' => 'image', 'model' => $locked->model]);
                $locked->update([
                    'status' => 'completed', 'stage' => 'completed', 'result_urls' => $urls, 'asset_paths' => $job->asset_paths,
                    'error_message' => null, 'billing_status' => 'settled',
                ]);

                return $locked->fresh();
            });
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
     * Existing (pre-capability) async Kinovi path for non-pilot members during limited
     * activation. Reserve -> persist a pending job carrying provider routing identity (but no
     * capability revision) -> dispatch. The shared process()/poll() pipeline executes it via the
     * Kinovi adapter exactly like a coordinator job (capabilityForJob() supplies a default when
     * capability_revision_id is null). No capability validation, price re-check, or idempotency.
     */
    private function createAsync(User $user, AiModelProfile $profile, string $prompt, string $size): ImageJob
    {
        $jobId = (string) Str::uuid();
        $job = DB::transaction(function () use ($user, $profile, $prompt, $size, $jobId): ImageJob {
            $model = AiModelProfile::query()->with('provider')->where('model_id', $profile->model_id)->lockForUpdate()->first();
            if (! $model || $model->category !== 'image' || ! MediaModelConfig::allowedFor($user, $model)) {
                throw new ImageGenerationException('The selected image model is unavailable.', 503);
            }
            if (! $model->provider || $model->provider->protocol !== 'kinovi') {
                throw new ImageGenerationException('The selected image model is unavailable.', 503);
            }
            $config = MediaModelConfig::forModel($model);
            if (($config['supports_size'] ?? false) && ! in_array($size, $config['sizes'], true)) {
                throw new ImageGenerationException('The selected image options are not supported by this model.', 422);
            }
            if (! is_int($model->token_cost) || $model->token_cost < 1) {
                throw new ImageGenerationException('Image token pricing is unavailable.', 503);
            }
            $reservation = $this->tokens->reserve($user, 'image', $model->model_id, 1, "image:{$jobId}", (int) $model->token_cost);

            return ImageJob::create([
                'user_id' => $user->id, 'job_id' => $jobId, 'model' => $model->model_id,
                'provider_id' => $model->provider_id, 'upstream_model_id' => $model->upstream_model_id ?: $model->model_id,
                'connection_fingerprint' => self::fingerprint($model->provider), 'generation_config' => $config,
                'capability_revision_id' => null, 'routing_identity' => $model->upstream_model_id ?: $model->model_id,
                'price_tokens' => (int) $model->token_cost,
                'prompt' => $prompt, 'size' => ($config['supports_size'] ?? false) ? $size : 'auto', 'quantity' => 1,
                'status' => 'pending', 'stage' => 'queued', 'billing_reserved_microusd' => 0,
                'billing_reference_id' => "image:{$jobId}", 'billing_status' => 'reserved',
                'billing_mode' => $reservation['billing_mode'], 'tokens_reserved' => $reservation['amount_tokens'],
                'next_poll_at' => now()->addMinute(),
            ]);
        });
        ProcessImageJob::dispatch($job->id)->onConnection('media')->onQueue('media')->afterCommit();

        return $job;
    }

    public function reconcileStaleReservations(int $minutes): int
    {
        $reconciled = 0;
        $staleBefore = now()->subMinutes($minutes);
        ImageJob::query()
            ->where('status', 'processing')
            ->where('billing_status', 'reserved')
            ->where('updated_at', '<=', $staleBefore)
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use (&$reconciled, $staleBefore): void {
                foreach ($jobs as $job) {
                    $changed = DB::transaction(function () use ($job, $staleBefore): bool {
                        $locked = ImageJob::query()->lockForUpdate()->find($job->id);
                        if (! $locked || $locked->status !== 'processing' || $locked->billing_status !== 'reserved'
                            || $locked->updated_at->gt($staleBefore)) {
                            return false;
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
            $locked = ImageJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($locked->status !== 'processing' || $locked->billing_status !== 'reserved') {
                return $locked;
            }
            $this->tokens->release($locked->user_id, $reservation, 'Failed image generation');
            $locked->update([
                'status' => 'failed', 'stage' => 'failed',
                'result_urls' => null,
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
                'status' => 'processing', 'stage' => 'submitting', 'processing_started_at' => now(),
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
            $request = $adapter->buildRequest($this->capabilityForJob($job), [
                'inputs' => ['prompt' => $job->prompt],
                'params' => $job->size !== 'auto' ? ['size' => $job->size] : [],
            ], (string) $job->upstream_model_id);
            $submit = $adapter->submit($provider, $request);
        } catch (Throwable) {
            // Acceptance is unknown: never refund or resubmit on an unexpected submit error;
            // free the lease and let bounded stale-reservation reconciliation resolve it.
            $this->releaseClaimForReconcile($job);

            return;
        }

        if ($submit->outcome === SubmitOutcome::Rejected) {
            $this->failClaim($job, 'The image provider rejected this request. Reserved tokens have been returned.');

            return;
        }
        if ($submit->outcome === SubmitOutcome::Uncertain) {
            // Submit timeout / unknown acceptance: do NOT refund or resubmit; reconcile later.
            $this->releaseClaimForReconcile($job);

            return;
        }
        if ($submit->outcome === SubmitOutcome::Immediate) {
            $saving = $this->claimForSaving($job);
            if ($saving) {
                $this->finalize($saving, $submit->resultUrls ?? []);
            }

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
                'processing_started_at' => null, 'processing_token' => null,
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
            if (! $job || $job->status !== 'processing' || $job->stage !== 'rendering' || ! $job->upstream_job_id
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
        if (($job->submitted_at ?? $job->created_at)->lt(now()->subMinutes(30))) {
            $this->failClaim($job, 'Image generation timed out. Reserved tokens have been returned.');

            return;
        }
        try {
            $provider = $this->provider($job);
        } catch (Throwable) {
            $this->failClaim($job, 'The image connection changed or became unavailable. Reserved tokens have been returned.');

            return;
        }
        try {
            $status = $this->adapters->for($provider->protocol)->pollStatus($provider, (string) $job->upstream_job_id);
        } catch (Throwable) {
            // Transient status-check failure: reschedule within the 30-minute window, no release.
            $this->rescheduleClaim($job);

            return;
        }
        if ($status->state === MediaState::Failed) {
            $this->failClaim($job, 'The image provider could not complete this request. Reserved tokens have been returned.');

            return;
        }
        if ($status->state === MediaState::Completed) {
            $urls = $status->resultUrls ?? [];
            if ($urls === []) {
                $this->rescheduleClaim($job);

                return;
            }
            $saving = $this->claimForSaving($job);
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
        $items = array_map(static fn (string $url): array => ['url' => $url], array_values($urls));
        $stored = $this->images->persist($job, $items);
        $assets = $job->asset_paths;
        $retained = DB::transaction(function () use ($job, $stored, $assets): bool {
            $locked = ImageJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return $locked?->status === 'completed';
            }
            $this->tokens->settle($locked->user_id, [
                'reference_id' => $locked->billing_reference_id, 'amount_tokens' => $locked->tokens_reserved,
            ], ['service' => 'image', 'model' => $locked->model]);
            $locked->update([
                'status' => 'completed', 'stage' => 'completed', 'result_urls' => $stored, 'asset_paths' => $assets,
                'error_message' => null, 'billing_status' => 'settled', 'completed_at' => now(),
                'next_poll_at' => null, 'processing_started_at' => null, 'processing_token' => null,
            ]);

            return true;
        });
        if (! $retained) {
            foreach ($assets ?? [] as $asset) {
                Storage::disk('local')->delete($asset['path']);
            }
        }
    }

    private function finalize(ImageJob $job, array $urls): void
    {
        $this->complete($job, $urls);
    }

    private function claimForSaving(ImageJob $job): ?ImageJob
    {
        return DB::transaction(function () use ($job): ?ImageJob {
            $locked = ImageJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return null;
            }
            $locked->update(['stage' => 'saving', 'processing_started_at' => now(), 'next_poll_at' => null]);

            return $locked;
        });
    }

    private function releaseClaimForReconcile(ImageJob $job): void
    {
        // Keep the reservation; free the lease so bounded stale-reservation reconciliation acts.
        DB::transaction(function () use ($job): void {
            $locked = ImageJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return;
            }
            $locked->update([
                'stage' => 'submitting', 'processing_started_at' => null, 'processing_token' => null,
                'next_poll_at' => now()->addSeconds(self::POLL_DELAY_SECONDS),
            ]);
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
                return MediaCapability::fromArray($revision->definition);
            }
        }

        return new MediaCapability($job->model, MediaOperation::TextToImage, OutputKind::Image, 1);
    }

    private function failClaim(ImageJob $claim, string $message): void
    {
        DB::transaction(function () use ($claim, $message): void {
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
            'billing_status' => 'released', 'next_poll_at' => null, 'processing_started_at' => null,
            'processing_token' => null, 'completed_at' => now(),
        ]);
    }

    private function ownsClaim(?ImageJob $current, ImageJob $claim): bool
    {
        return $current !== null && $current->status === 'processing' && $current->stage === $claim->stage
            && is_string($current->processing_token) && is_string($claim->processing_token)
            && hash_equals($current->processing_token, $claim->processing_token);
    }

    private function provider(ImageJob $job): AiProviderProfile
    {
        $provider = AiProviderProfile::query()->find($job->provider_id);
        if (! $provider || ! $provider->is_enabled || $provider->protocol !== 'kinovi'
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
