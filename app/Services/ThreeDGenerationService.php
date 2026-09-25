<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Exceptions\ImageGenerationException;
use App\Jobs\PollThreeDJob;
use App\Jobs\ProcessThreeDJob;
use App\Media\AssetService;
use App\Media\CapabilityResolver;
use App\Media\CapabilityValidator;
use App\Media\Enums\MediaOperation;
use App\Media\Exceptions\CapabilityConfigException;
use App\Media\MediaActivation;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaAsset;
use App\Models\ThreeDJob;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ThreeDGenerationService
{
    private const POLL_DELAY_SECONDS = 8;

    private const SUBMISSION_LEASE_SECONDS = 480;

    private const POLL_LEASE_SECONDS = 300;

    private const MAX_MINUTES = 30;

    public function __construct(
        private readonly MediaActivation $activation,
        private readonly CapabilityResolver $resolver,
        private readonly CapabilityValidator $validator,
        private readonly AssetService $assets,
        private readonly MediaTokenBillingService $tokens,
        private readonly FalThreeDTransport $transport,
        private readonly GeneratedModel3dStore $models,
        private readonly StorageQuotaService $quota,
    ) {}

    /** @return ThreeDJob[] */
    public function create(User $user, AiModelProfile $model, MediaOperation $operation, array $rawInputs, string $entrypoint, array $options = []): array
    {
        $this->activation->assertNotPaused();
        $key = $options['idempotency_key'] ?? null;
        if (! is_string($key) || trim($key) === '' || strlen($key) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'A request key is required.']);
        }
        ksort($rawInputs);
        $fingerprint = hash('sha256', json_encode([$model->model_id, $operation->value, $rawInputs], JSON_THROW_ON_ERROR));
        $dedup = hash('sha256', $user->id.'|'.$entrypoint.'|'.trim($key));
        $job = DB::transaction(function () use ($user, $model, $operation, $rawInputs, $options, $dedup, $fingerprint): ThreeDJob {
            // Serialize admission and idempotent replay, including terminal jobs, before any debit.
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($owner->is_active === false || $owner->isExpired() || ! $owner->hasPermission('image_generator')) {
                throw new ImageGenerationException('3D generation is not available for your account.', 403);
            }
            if (! $this->activation->usesCoordinator($owner)) {
                throw new ImageGenerationException('This operation is not available for your account yet.', 503);
            }
            $existing = ThreeDJob::query()->where('user_id', $owner->id)->where('dedup_key', $dedup)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_fingerprint, $fingerprint)) {
                    throw new ImageGenerationException('This request key was already used with different input.', 409);
                }

                return $existing;
            }
            $selected = AiModelProfile::query()->with('provider')->lockForUpdate()->find($model->id);
            if ($selected === null || ! ThreeDProtocol::supports($selected) || ! MediaModelConfig::allowedFor($owner, $selected)
                || $operation !== MediaOperation::ImageTo3d) {
                throw new ImageGenerationException('The selected 3D model is unavailable.', 503);
            }
            try {
                $resolved = $this->resolver->resolve($selected, $operation);
            } catch (CapabilityConfigException) {
                throw new ImageGenerationException('The 3D model capability is unavailable.', 503);
            }
            // Check the form's contract first: a removed field must produce a stale-form conflict, not a misleading validation error.
            $expectedHash = $options['expected_capability_hash'] ?? null;
            if (! is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) {
                throw ValidationException::withMessages(['expected_capability_hash' => 'A current capability quote is required.']);
            }
            if (! hash_equals($resolved->sourceHash, $expectedHash)) {
                throw new ImageGenerationException('This model was updated since you opened this form. Review and try again.', 409);
            }
            if (! ThreeDProtocol::compatible($resolved->capability)) {
                throw new ImageGenerationException('The published 3D capability is not supported by this executor.', 503);
            }
            $validated = $this->validator->validate($resolved->capability, $rawInputs);
            $asset = $this->reference($owner, (string) ($validated['inputs']['image_ref'] ?? ''));
            if (! is_int($selected->token_cost) || $selected->token_cost < 1 || $selected->token_cost > 2_147_483_647) {
                throw new ImageGenerationException('3D token pricing is unavailable.', 503);
            }
            if (! is_int($options['expected_price_tokens'] ?? null) || $options['expected_price_tokens'] < 1) {
                throw ValidationException::withMessages(['expected_price_tokens' => 'A current token price is required.']);
            }
            if ($options['expected_price_tokens'] !== $selected->token_cost) {
                throw new ImageGenerationException('The price changed since you opened this form. Review and try again.', 409);
            }
            if ($this->quota->exceeded($owner)) {
                throw new ImageGenerationException('Your storage is full. Delete an item from your Library before generating another model.', 422);
            }
            $revision = $this->resolver->ensureRevision($selected, $operation, $resolved);
            $jobId = (string) Str::uuid();
            $reservation = $this->tokens->reserve($owner, 'model3d', $selected->model_id, 1, 'model3d:'.$jobId, $selected->token_cost);
            $created = ThreeDJob::create([
                'user_id' => $owner->id, 'job_id' => $jobId, 'model' => $selected->model_id,
                'model_label' => $selected->display_name, 'operation' => $operation->value,
                'provider_id' => $selected->provider_id, 'upstream_model_id' => ThreeDProtocol::MODEL,
                'connection_fingerprint' => self::fingerprint($selected->provider),
                'generation_config' => ThreeDProtocol::config(), 'capability_revision_id' => $revision->id,
                'routing_identity' => ThreeDProtocol::MODEL, 'price_tokens' => $selected->token_cost,
                'dedup_key' => $dedup, 'payload_fingerprint' => $fingerprint, 'reference_asset_ids' => [$asset->id],
                'settings' => [...ThreeDProtocol::FIXED_PARAMS, ...$validated['params']],
                'status' => 'pending', 'stage' => 'queued', 'billing_mode' => $reservation['billing_mode'],
                'billing_reference_id' => $reservation['reference_id'], 'billing_status' => 'reserved',
                'tokens_reserved' => $reservation['amount_tokens'], 'next_poll_at' => now()->addMinute(),
            ]);
            DB::afterCommit(fn () => $this->queueSubmission($created->id));

            return $created;
        });

        return [$job];
    }

    public function process(int $id): void
    {
        $job = DB::transaction(function () use ($id): ?ThreeDJob {
            $job = ThreeDJob::query()->lockForUpdate()->find($id);
            if (! $job || $job->status !== 'pending' || $job->stage !== 'queued'
                || $job->submitted_at !== null || $job->upstream_job_id !== null) {
                return null;
            }
            $job->update(['status' => 'processing', 'stage' => 'submitting', 'processing_started_at' => now(),
                'processing_token' => (string) Str::uuid(), 'next_poll_at' => null]);

            return $job;
        });
        if ($job === null) {
            return;
        }
        try {
            if ($job->created_at->lt(now()->subMinutes(self::MAX_MINUTES))) {
                $this->failClaim($job, 'The 3D request could not be started. Reserved tokens have been returned.');

                return;
            }
            $provider = $this->provider($job);
            $owner = User::query()->findOrFail($job->user_id);
            $asset = $this->reference($owner, (string) ($job->reference_asset_ids[0] ?? ''));
            $requestId = $this->transport->submit($provider, $job->upstream_model_id, $this->assets->dataUri($asset), $job->settings);
            $accepted = DB::transaction(function () use ($job, $requestId): bool {
                $locked = ThreeDJob::query()->lockForUpdate()->find($job->id);
                if (! $this->ownsClaim($locked, $job)) {
                    return false;
                }
                $locked->update(['upstream_job_id' => $requestId, 'submitted_at' => now(), 'stage' => 'rendering',
                    'processing_started_at' => null, 'processing_token' => null,
                    'next_poll_at' => now()->addSeconds(self::POLL_DELAY_SECONDS)]);

                return true;
            });
            if ($accepted) {
                $this->queuePoll($job->id);
            }
        } catch (Throwable) {
            $this->failClaim($job, '3D submission could not be confirmed. Reserved tokens have been returned. No automatic resubmission was made.');
        }
    }

    public function poll(int $id): void
    {
        $job = DB::transaction(function () use ($id): ?ThreeDJob {
            $job = ThreeDJob::query()->lockForUpdate()->find($id);
            if (! $job || $job->status !== 'processing' || ! in_array($job->stage, ['rendering', 'saving'], true)
                || (! $job->upstream_job_id && ! $job->provider_result_url) || ($job->next_poll_at !== null && $job->next_poll_at->isFuture())
                || ($job->processing_started_at !== null && $job->processing_started_at->gt(now()->subSeconds(self::POLL_LEASE_SECONDS)))) {
                return null;
            }
            $job->update(['processing_started_at' => now(), 'processing_token' => (string) Str::uuid(),
                'next_poll_at' => now()->addSeconds(self::POLL_LEASE_SECONDS), 'poll_attempts' => $job->poll_attempts + 1]);

            return $job;
        });
        if ($job === null) {
            return;
        }
        $savingOriginal = $job->stage === 'saving' && $job->provider_result_url !== null;
        try {
            // Locally saved originals can finish settlement even if the upstream URL or provider has expired.
            if ($job->stage === 'saving' && ($saved = $this->models->existing($job)) !== null) {
                $this->complete($job, $saved);

                return;
            }
            if (! $job->provider_result_url
                && (($job->submitted_at ?? $job->created_at)->lt(now()->subMinutes(self::MAX_MINUTES)) || $job->poll_attempts > 240)) {
                $this->failClaim($job, '3D generation timed out. Reserved tokens have been returned.');

                return;
            }
            if ($job->stage === 'rendering') {
                $result = $this->transport->status($this->provider($job), $job->upstream_job_id);
                if ($result['status'] === 'failed') {
                    $this->failClaim($job, 'The 3D provider could not complete this request. Reserved tokens have been returned.');

                    return;
                }
                if ($result['status'] === 'completed') {
                    $saving = DB::transaction(function () use ($job, $result): ?ThreeDJob {
                        $locked = ThreeDJob::query()->lockForUpdate()->find($job->id);
                        if (! $this->ownsClaim($locked, $job)) {
                            return null;
                        }
                        $locked->update(['stage' => 'saving', 'provider_result_url' => $result['model_url'],
                            'processing_started_at' => now(), 'next_poll_at' => null]);

                        return $locked;
                    });
                    if ($saving === null) {
                        return;
                    }
                    $job = $saving;
                }
            }
            if ($job->stage === 'saving') {
                $this->complete($job, $this->models->persist($job, (string) $job->provider_result_url));

                return;
            }
        } catch (AiProxyException $exception) {
            if ($savingOriginal) {
                $this->deferSavedResult($job, 'The original 3D result could not be saved. Retry saving without generating again.');

                return;
            }
            if ($exception->responseStatus() !== 503) {
                $this->failClaim($job, 'The 3D result could not be verified or saved. Reserved tokens have been returned.');

                return;
            }
        } catch (Throwable) {
            if ($savingOriginal) {
                $this->deferSavedResult($job, 'The original 3D result could not be saved. Retry saving without generating again.');

                return;
            }
            $this->failClaim($job, '3D generation could not be completed. Reserved tokens have been returned.');

            return;
        }
        $reschedule = DB::transaction(function () use ($job): bool {
            $locked = ThreeDJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return false;
            }
            $locked->update(['processing_started_at' => null, 'processing_token' => null,
                'next_poll_at' => now()->addSeconds(self::POLL_DELAY_SECONDS)]);

            return true;
        });
        if ($reschedule) {
            $this->queuePoll($job->id);
        }
    }

    public function reconcile(): array
    {
        $counts = ['queued' => 0, 'polls' => 0, 'completed' => 0, 'failed' => 0];
        ThreeDJob::query()->whereIn('status', ['pending', 'processing'])->orderBy('id')->chunkById(100, function ($jobs) use (&$counts): void {
            foreach ($jobs as $candidate) {
                $action = DB::transaction(function () use ($candidate): ?string {
                    User::query()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
                    $job = ThreeDJob::query()->lockForUpdate()->find($candidate->id);
                    if (! $job || ! in_array($job->status, ['pending', 'processing'], true)) {
                        return null;
                    }
                    if ($job->status === 'pending' && $job->stage === 'queued' && $job->upstream_job_id === null && $job->submitted_at === null) {
                        if ($job->created_at->lt(now()->subMinutes(self::MAX_MINUTES))) {
                            $this->terminate($job, 'failed', 'The 3D request could not be started. Reserved tokens have been returned.');

                            return 'failed';
                        }
                        if ($job->next_poll_at === null || $job->next_poll_at->isPast()) {
                            $job->update(['next_poll_at' => now()->addMinute()]);

                            return 'queued';
                        }
                    }
                    if ($job->stage === 'submitting' && ($job->processing_started_at ?? $job->updated_at)->lt(now()->subSeconds(self::SUBMISSION_LEASE_SECONDS))) {
                        $this->terminate($job, 'failed', 'The 3D request was interrupted. Reserved tokens have been returned. It was not automatically resubmitted.');

                        return 'failed';
                    }
                    if (in_array($job->stage, ['rendering', 'saving'], true) && $job->upstream_job_id !== null
                        && ($job->processing_started_at === null || $job->processing_started_at->lt(now()->subSeconds(self::POLL_LEASE_SECONDS)))) {
                        if (($job->submitted_at ?? $job->created_at)->lt(now()->subMinutes(self::MAX_MINUTES)) || $job->poll_attempts > 240) {
                            if ($job->stage === 'saving' && ($saved = $this->models->existing($job)) !== null) {
                                $job->update(['processing_token' => (string) Str::uuid(), 'processing_started_at' => now()]);
                                $this->complete($job, $saved);

                                return $job->fresh()->status === 'completed' ? 'completed' : null;
                            }
                            if ($job->provider_result_url) {
                                $job->update(['stage' => 'save_failed', 'error_message' => 'The original 3D result could not be saved. Retry saving without generating again.',
                                    'processing_started_at' => null, 'processing_token' => null, 'next_poll_at' => null]);

                                return null;
                            }
                            $this->terminate($job, 'failed', '3D generation timed out. Reserved tokens have been returned.');

                            return 'failed';
                        }
                        // Saving is re-entered safely: first recover private bytes, otherwise only repeat the GET.
                        if ($job->next_poll_at === null || $job->next_poll_at->isPast()) {
                            return 'polls';
                        }
                    }

                    return null;
                });
                if ($action === 'queued' && $this->queueSubmission($candidate->id)) {
                    $counts['queued']++;
                } elseif ($action === 'polls' && $this->queuePoll($candidate->id)) {
                    $counts['polls']++;
                } elseif ($action === 'failed') {
                    $counts['failed']++;
                    Storage::disk('local')->deleteDirectory(dirname(GeneratedModel3dStore::path($candidate->job_id)));
                } elseif ($action === 'completed') {
                    $counts['completed']++;
                }
            }
        });

        return $counts;
    }

    public function cancel(User $user, string $jobId): ThreeDJob
    {
        return DB::transaction(function () use ($user, $jobId): ThreeDJob {
            $job = ThreeDJob::query()->where('user_id', $user->id)->where('job_id', $jobId)->lockForUpdate()->firstOrFail();
            if (self::cancellation($job)['can_cancel']) {
                $this->terminate($job, 'cancelled', '3D generation was cancelled. Reserved tokens have been returned.');
            }

            return $job;
        });
    }

    public function failSubmission(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $job = ThreeDJob::query()->lockForUpdate()->find($id);
            // An obsolete queue failure must not cancel another worker's live claim.
            if ($job && $job->status === 'pending' && $job->stage === 'queued'
                && $job->submitted_at === null && $job->upstream_job_id === null) {
                $this->terminate($job, 'failed', 'The 3D request could not be started. Reserved tokens have been returned.');
            }
        });
    }

    public static function cancellation(ThreeDJob $job): array
    {
        $canCancel = $job->status === 'pending' && $job->stage === 'queued'
            && $job->submitted_at === null && $job->upstream_job_id === null;
        $reason = $canCancel ? null : (in_array($job->status, ['completed', 'failed'], true)
            ? 'This request is already finished.' : '3D submission has started. It cannot be cancelled and this action does not refund tokens.');

        return ['can_cancel' => $canCancel, 'cancel_unavailable_reason' => $reason, 'cancel_reason' => $reason];
    }

    public static function payload(ThreeDJob $job): array
    {
        return [
            'id' => $job->job_id, 'job_id' => $job->job_id, 'model' => $job->model, 'model_label' => $job->model_label,
            'operation' => $job->operation, 'prompt' => '', 'status' => $job->status, 'stage' => $job->stage,
            'error_message' => $job->error_message, 'error' => $job->error_message,
            'model_url' => $job->status === 'completed' ? $job->model_url : null, 'format' => 'glb',
            'previewable' => $job->status === 'completed' && $job->previewable,
            'preview_unavailable_reason' => $job->preview_unavailable_reason,
            'token_cost' => $job->price_tokens, 'price_tokens' => $job->price_tokens, 'settings' => $job->settings,
            'billing_mode' => $job->billing_mode, 'billing_status' => $job->billing_status, 'tokens_reserved' => $job->tokens_reserved,
            ...self::cancellation($job), 'created_at' => $job->created_at?->toISOString(),
            'updated_at' => $job->updated_at?->toISOString(), 'completed_at' => $job->completed_at?->toISOString(),
        ];
    }

    private function reference(User $owner, string $id): MediaAsset
    {
        if (! Str::isUuid($id) || ($asset = MediaAsset::query()->find($id)) === null) {
            throw ValidationException::withMessages(['image_ref' => 'Upload an image reference before generating a model.']);
        }
        try {
            $this->assets->assertOwner($owner, $asset);
        } catch (AuthorizationException) {
            throw new ImageGenerationException('This reference is not available for your account.', 403);
        }
        if ($asset->media_type !== 'image' || ! in_array($asset->mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ! $asset->signature_ok || $asset->retention_status !== 'active'
            || ($asset->expires_at !== null && $asset->expires_at->isPast()) || $asset->size_bytes < 1
            || $asset->size_bytes > 15_728_640 || ! Storage::disk($asset->storage_disk)->exists($asset->storage_path)) {
            throw ValidationException::withMessages(['image_ref' => 'This image reference is no longer available. Upload it again.']);
        }

        return $asset;
    }

    private function complete(ThreeDJob $job, array $asset): void
    {
        $retained = false;
        $saveError = null;
        try {
            $retained = DB::transaction(function () use ($job, $asset): bool {
                $owner = User::query()->whereKey($job->user_id)->lockForUpdate()->firstOrFail();
                $locked = ThreeDJob::query()->lockForUpdate()->find($job->id);
                if (! $this->ownsClaim($locked, $job)) {
                    return $locked !== null && $locked->status !== 'failed';
                }
                app(StorageQuotaService::class)->assertCanStore($owner, Storage::disk('local')->size($asset['path']));
                $this->tokens->settle($locked->user_id, ['reference_id' => $locked->billing_reference_id],
                    ['service' => 'model3d', 'model' => $locked->model, 'operation' => $locked->operation]);
                $locked->update(['status' => 'completed', 'stage' => 'completed',
                    'model_url' => '/api/3d/'.$locked->job_id.'/asset', 'model_path' => $asset['path'],
                    'mime_type' => $asset['mime_type'], 'size_bytes' => $asset['size_bytes'],
                    'previewable' => $asset['previewable'], 'preview_unavailable_reason' => $asset['preview_unavailable_reason'],
                    'provider_result_url' => null, 'error_message' => null, 'billing_status' => 'settled', 'completed_at' => now(),
                    'next_poll_at' => null, 'processing_started_at' => null, 'processing_token' => null]);

                return true;
            });
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 413) {
                throw $exception;
            }
            $saveError = $exception->getMessage();
        } finally {
            if (! $retained) {
                Storage::disk('local')->deleteDirectory(dirname($asset['path']));
            }
        }
        if ($saveError !== null) {
            $this->deferSavedResult($job, $saveError);
        }
    }

    private function deferSavedResult(ThreeDJob $job, string $message): void
    {
        DB::transaction(function () use ($job, $message): void {
            $locked = ThreeDJob::query()->lockForUpdate()->find($job->id);
            if ($this->ownsClaim($locked, $job)) {
                Storage::disk('local')->deleteDirectory(dirname(GeneratedModel3dStore::path($job->job_id)));
                $locked->update(['stage' => 'save_failed', 'error_message' => $message,
                    'processing_started_at' => null, 'processing_token' => null, 'next_poll_at' => null]);
            }
        });
    }

    private function failClaim(ThreeDJob $claim, string $message): void
    {
        $terminated = DB::transaction(function () use ($claim, $message): bool {
            User::query()->whereKey($claim->user_id)->lockForUpdate()->firstOrFail();
            $job = ThreeDJob::query()->lockForUpdate()->find($claim->id);
            if (! $this->ownsClaim($job, $claim)) {
                return false;
            }
            $this->terminate($job, 'failed', $message);

            return true;
        });
        if ($terminated) {
            Storage::disk('local')->deleteDirectory(dirname(GeneratedModel3dStore::path($claim->job_id)));
        }
    }

    private function terminate(ThreeDJob $job, string $stage, string $message): void
    {
        if (! in_array($job->status, ['pending', 'processing'], true)) {
            return;
        }
        $this->tokens->release($job->user_id, ['reference_id' => $job->billing_reference_id],
            $stage === 'cancelled' ? '3D generation cancelled' : '3D generation did not complete');
        $job->update(['status' => 'failed', 'stage' => $stage, 'error_message' => $message,
            'billing_status' => 'released', 'provider_result_url' => null, 'next_poll_at' => null,
            'processing_started_at' => null, 'processing_token' => null, 'completed_at' => now()]);
    }

    private function ownsClaim(?ThreeDJob $current, ThreeDJob $claim): bool
    {
        return $current !== null && $current->status === 'processing' && $current->stage === $claim->stage
            && is_string($current->processing_token) && is_string($claim->processing_token)
            && hash_equals($current->processing_token, $claim->processing_token);
    }

    private function provider(ThreeDJob $job): AiProviderProfile
    {
        $provider = AiProviderProfile::query()->find($job->provider_id);
        if (! $provider || ! $provider->is_enabled || $provider->protocol !== 'fal'
            || ! hash_equals($job->connection_fingerprint, self::fingerprint($provider))) {
            throw new AiProxyException('The 3D connection changed. No request was sent to a different provider.', 502);
        }

        return $provider;
    }

    private static function fingerprint(AiProviderProfile $provider): string
    {
        return hash('sha256', json_encode(array_intersect_key($provider->getRawOriginal(),
            array_flip(['base_url', 'api_key', 'protocol', 'api_version'])), JSON_THROW_ON_ERROR));
    }

    private function queueSubmission(int $id): bool
    {
        try {
            ProcessThreeDJob::dispatch($id)->onConnection('media')->onQueue('media')->afterCommit();

            return true;
        } catch (Throwable) {
            return false; // The committed queued row is recovered without a second admission.
        }
    }

    private function queuePoll(int $id): bool
    {
        try {
            PollThreeDJob::dispatch($id)->onConnection('media')->onQueue('media')
                ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS))->afterCommit();

            return true;
        } catch (Throwable) {
            return false; // Recovery only polls the persisted request ID; it never repeats POST.
        }
    }
}
