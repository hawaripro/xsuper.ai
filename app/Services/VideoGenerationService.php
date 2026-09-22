<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Jobs\PollVideoJob;
use App\Jobs\ProcessVideoJob;
use App\Media\AssetService;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\VideoJob;
use App\Models\Wallet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class VideoGenerationService
{
    private const MAX_TOKEN_AMOUNT = 2_147_483_647;

    public function __construct(
        private readonly AiProviderTransport $transport,
        private readonly MediaTokenBillingService $tokens,
        private readonly GeneratedVideoStore $videos,
        private readonly VideoReferenceStore $references,
        private readonly AssetService $assets,
    ) {}

    public function create(User $user, array $input): array
    {
        $storedReferences = [];
        $committed = false;
        try {
            $jobs = DB::transaction(function () use ($user, $input, &$storedReferences, &$committed): array {
                DB::afterCommit(static function () use (&$committed): void {
                    $committed = true;
                });
                $model = AiModelProfile::query()->with('provider')->where('model_id', $input['model'])->lockForUpdate()->first();
                if (! $model || $model->category !== 'video' || ! MediaModelConfig::allowedFor($user, $model)) {
                    throw ValidationException::withMessages(['model' => 'The selected video model is unavailable.']);
                }
                if (! in_array($model->provider->protocol, ['openai', 'fal', 'kinovi'], true)) {
                    throw ValidationException::withMessages(['model' => 'This provider does not support video generation.']);
                }
                $config = MediaModelConfig::forModel($model);
                $proMode = $input['pro_mode'] ?? false;
                if (! in_array($proMode, [true, false, 0, 1, '0', '1'], true)) {
                    throw ValidationException::withMessages(['pro_mode' => 'Choose either Standard or Pro quality.']);
                }
                $proMode = (bool) $proMode;
                if ($proMode && ($model->provider->protocol !== 'fal' || ! ($config['supports_pro'] ?? false))) {
                    throw ValidationException::withMessages(['pro_mode' => 'Pro quality is not supported by this model.']);
                }
                $upload = $input['reference_image'] ?? null;
                $hasReference = $upload !== null;
                if ($hasReference && ($model->provider->protocol !== 'fal' || ! ($config['supports_reference_image'] ?? false))) {
                    throw ValidationException::withMessages(['reference_image' => 'Reference images are not supported by this model.']);
                }
                if (! $hasReference && ($config['reference_required'] ?? false)) {
                    throw ValidationException::withMessages(['reference_image' => 'This model requires a reference image.']);
                }
                $upstreamModel = $model->upstream_model_id ?: $model->model_id;
                if ($hasReference) {
                    if (! $upload instanceof UploadedFile) {
                        throw ValidationException::withMessages(['reference_image' => 'Upload a JPEG, PNG or WebP reference image.']);
                    }
                    $upstreamModel = $config['reference_model'] ?? $upstreamModel;
                    $referenceConfig = is_string($upstreamModel) && (FalProtocol::MEDIA_MODELS[$upstreamModel] ?? null) === 'video'
                        ? FalProtocol::mediaConfig($upstreamModel) : null;
                    if (! ($referenceConfig['reference_required'] ?? false) || ($proMode && ! ($referenceConfig['supports_pro'] ?? false))) {
                        throw ValidationException::withMessages(['reference_image' => 'The selected reference-image mode is unavailable.']);
                    }
                    $config = $referenceConfig;
                }
                $quantity = (int) $input['count'];
                $aspect = $hasReference ? null : ($input['aspect_ratio'] ?? null);
                $duration = $input['settings']['duration'] ?? null;
                if ($quantity < 1 || $quantity > $config['max_quantity']) {
                    throw ValidationException::withMessages(['count' => 'The quantity exceeds this model limit.']);
                }
                if ($aspect !== null && ($config['aspect_ratios'] === [] || ! in_array($aspect, $config['aspect_ratios'], true))) {
                    throw ValidationException::withMessages(['aspect_ratio' => 'The aspect ratio is not supported by this model.']);
                }
                if ($duration !== null && ! in_array((int) $duration, $config['durations'], true)) {
                    throw ValidationException::withMessages(['settings.duration' => 'The duration is not supported by this model.']);
                }
                $multiplier = $proMode ? 2 : 1;
                if (! is_int($model->token_cost) || $model->token_cost < 1 || $model->token_cost > intdiv(self::MAX_TOKEN_AMOUNT, $multiplier)) {
                    throw ValidationException::withMessages(['model' => 'Video token pricing is unavailable or exceeds the supported limit.']);
                }
                $unitCost = $model->token_cost * $multiplier;
                if ($quantity > intdiv(self::MAX_TOKEN_AMOUNT, $unitCost)) {
                    throw ValidationException::withMessages(['count' => 'The total token reservation exceeds the supported limit.']);
                }
                $reference = $hasReference ? $this->references->validate($upload) : null;
                if ($config['supports_pro'] ?? false) {
                    $config['video_parameters'] = [
                        'num_inference_steps' => $proMode ? 16 : 12,
                        'video_quality' => $proMode ? 'maximum' : 'high',
                    ];
                }
                $jobs = [];
                for ($index = 0; $index < $quantity; $index++) {
                    $prompt = trim($input['prompt']);
                    if (! empty($input['cta'])) {
                        $prompt .= "\nCall to action: ".trim($input['cta']);
                    }
                    if (($input['ugc_variation'] ?? false) && $index > 0) {
                        $prompt .= "\nCreate variation ".($index + 1).' with a distinct camera composition while preserving the same subject and message.';
                    }
                    $jobId = (string) Str::uuid();
                    $reservation = $this->tokens->reserve($user, 'video', $model->model_id, 1, 'video:'.$jobId, $unitCost);
                    $referencePath = null;
                    if ($reference !== null) {
                        $referencePath = $this->references->persist($jobId, $reference);
                        $storedReferences[] = $referencePath;
                    }
                    $jobs[] = VideoJob::create([
                        'user_id' => $user->id, 'job_id' => $jobId, 'mode' => $input['mode'],
                        'prompt' => $prompt, 'model' => $model->model_id,
                        'aspect_ratio' => $hasReference ? 'auto' : ($aspect ?? ($config['aspect_ratios'][0] ?? 'auto')),
                        'duration' => $duration ?? ($config['durations'][0] ?? 0),
                        'pro_mode' => $proMode, 'has_reference' => $hasReference,
                        'reference_path' => $referencePath, 'reference_mime_type' => $reference['mime'] ?? null,
                        'provider_id' => $model->provider_id, 'upstream_model_id' => $upstreamModel,
                        'connection_fingerprint' => self::fingerprint($model->provider), 'generation_config' => $config,
                        'tokens_used' => $reservation['amount_tokens'], 'tokens_reserved' => $reservation['amount_tokens'],
                        'billing_mode' => $reservation['billing_mode'], 'billing_reference_id' => $reservation['reference_id'],
                        'billing_status' => 'reserved', 'billing_reserved_microusd' => 0,
                        'status' => 'pending', 'stage' => 'queued', 'next_poll_at' => now(),
                        'settings' => ['cta' => $input['cta'] ?? null, 'ugc_variation' => $input['ugc_variation'] ?? false],
                    ]);
                }

                return $jobs;
            });
        } catch (Throwable $exception) {
            if (! $committed) {
                foreach ($storedReferences as $path) {
                    $this->references->delete($path);
                }
            }
            throw $exception;
        }
        foreach ($jobs as $job) {
            ProcessVideoJob::dispatch($job->id)->onConnection('media')->onQueue('media')->afterCommit();
        }

        return $jobs;
    }

    public function process(int $id): void
    {
        $job = DB::transaction(function () use ($id): ?VideoJob {
            $job = VideoJob::query()->lockForUpdate()->find($id);
            if (! $job || $job->status !== 'pending' || $job->stage !== 'queued'
                || $job->submitted_at !== null || $job->upstream_job_id !== null) {
                return null;
            }
            $job->update(['status' => 'processing', 'stage' => 'submitting', 'processing_started_at' => now(), 'next_poll_at' => null]);

            return $job;
        });
        if (! $job) {
            return;
        }
        try {
            $provider = $this->provider($job);
            $config = $job->generation_config;
            $payload = ['model' => $job->upstream_model_id, 'prompt' => $job->prompt];
            if ($config['supports_aspect_ratio'] && (! $job->has_reference || $job->mode === 'avatar') && $job->aspect_ratio !== 'auto') {
                $payload['aspect_ratio'] = $job->aspect_ratio;
            }
            if ($config['supports_duration'] && $job->duration > 0) {
                $payload['duration'] = $job->duration;
            }
            if ($provider->protocol === 'fal' && ($config['supports_pro'] ?? false)) {
                $payload['pro_mode'] = $job->pro_mode;
            }
            if ($job->mode === 'avatar') {
                $payload['image_url'] = $this->avatarReferenceUrl($provider, $this->avatarReference($job, 'avatar_photo', 'image'));
                $payload['audio_url'] = $this->avatarReferenceUrl($provider, $this->avatarReference($job, 'speech_audio', 'audio'));
            } elseif ($job->has_reference) {
                // Fal consumes the owned image inline rather than fetching a workspace URL.
                $payload['image_url'] = $job->capability_revision_id !== null
                    ? $this->assets->dataUri($this->coordinatorReference($job))
                    : $this->references->dataUri($job);
            }
            $result = $this->transport->submitVideo($provider, $payload, $config['video_path']);
            $state = $this->state($result);
            if ($state['failed']) {
                $this->failSubmission($job->id, 'The video provider rejected this request.');

                return;
            }
            if ($state['url'] !== null) {
                $claimed = VideoJob::query()->whereKey($job->id)->where('status', 'processing')->where('stage', 'submitting')
                    ->where('processing_started_at', $job->processing_started_at)->update(['stage' => 'saving', 'processing_started_at' => now(), 'next_poll_at' => null]);
                if ($claimed !== 1) {
                    return;
                }
                $this->complete($job->id, $state);

                return;
            }
            $taskId = $result['id'] ?? $result['job_id'] ?? $result['task_id'] ?? data_get($result, 'data.id') ?? data_get($result, 'data.job_id') ?? data_get($result, 'data.task_id');
            if (! is_string($taskId) || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1) {
                throw new AiProxyException('The video provider did not return a valid job reference.', 502);
            }
            DB::transaction(function () use ($job, $taskId): void {
                $locked = VideoJob::query()->lockForUpdate()->findOrFail($job->id);
                if ($locked->status !== 'processing' || $locked->stage !== 'submitting') {
                    return;
                }
                $locked->update([
                    'upstream_job_id' => $taskId, 'submitted_at' => now(), 'stage' => 'rendering',
                    'processing_started_at' => null, 'next_poll_at' => now()->addSeconds(8),
                ]);
            });
            $this->schedulePoll($job->id);
        } catch (AiProxyException $exception) {
            $this->failSubmission($job->id, $exception->getMessage());
        } catch (Throwable) {
            $this->failSubmission($job->id, 'Video generation could not be completed. No automatic resubmission was made.');
        }
    }

    public function poll(int $id): void
    {
        $job = DB::transaction(function () use ($id): ?VideoJob {
            $job = VideoJob::query()->lockForUpdate()->find($id);
            if (! $job || $job->status !== 'processing' || $job->stage !== 'rendering' || ! $job->upstream_job_id
                || ($job->next_poll_at !== null && $job->next_poll_at->isFuture())
                || ($job->processing_started_at !== null && $job->processing_started_at->gt(now()->subMinute()))) {
                return null;
            }
            $job->update(['processing_started_at' => now(), 'next_poll_at' => now()->addSeconds(30), 'poll_attempts' => $job->poll_attempts + 1]);

            return $job;
        });
        if (! $job) {
            return;
        }
        if (($job->submitted_at ?? $job->created_at)->lt(now()->subMinutes(30))) {
            $this->fail($job->id, 'Video generation timed out. Reserved tokens have been returned.');

            return;
        }
        try {
            $provider = $this->provider($job);
        } catch (AiProxyException $exception) {
            $this->fail($job->id, $exception->getMessage());

            return;
        }
        try {
            $result = $this->transport->videoStatus($provider, $job->upstream_job_id, $job->generation_config['video_status_path']);
            $state = $this->state($result);
            if ($state['failed']) {
                $this->fail($job->id, 'The video provider could not complete this request.');

                return;
            }
            if ($state['url'] !== null) {
                $claimed = VideoJob::query()->whereKey($job->id)->where('status', 'processing')->where('stage', 'rendering')
                    ->where('processing_started_at', $job->processing_started_at)->update(['stage' => 'saving', 'processing_started_at' => now(), 'next_poll_at' => null]);
                if ($claimed !== 1) {
                    return;
                }
                $this->complete($job->id, $state);

                return;
            }
        } catch (AiProxyException $exception) {
            $current = VideoJob::query()->find($job->id);
            if ($current?->stage === 'saving') {
                $this->fail($job->id, 'The video result could not be saved. Reserved tokens have been returned.');

                return;
            }
            if ($exception->responseStatus() !== 503) {
                $this->fail($job->id, 'The video status could not be verified. Reserved tokens have been returned.');

                return;
            }
        } catch (Throwable) {
            $current = VideoJob::query()->find($job->id);
            $message = $current?->stage === 'saving'
                ? 'The video result could not be saved. Reserved tokens have been returned.'
                : 'The video status response was invalid.';
            $this->fail($job->id, $message);

            return;
        }
        VideoJob::query()->whereKey($job->id)->where('status', 'processing')->update([
            'processing_started_at' => null, 'next_poll_at' => now()->addSeconds(8),
        ]);
        $this->schedulePoll($job->id);
    }

    public function reconcile(): int
    {
        $count = 0;
        VideoJob::query()->whereIn('status', ['pending', 'processing'])->orderBy('id')->chunkById(100, function ($jobs) use (&$count): void {
            foreach ($jobs as $job) {
                if ($job->stage === 'legacy') {
                    $this->fail($job->id, 'This earlier video request was never submitted. Reserved credit has been returned.');
                    $count++;
                } elseif ($job->status === 'pending' && $job->stage === 'queued' && $job->submitted_at === null && $job->upstream_job_id === null) {
                    ProcessVideoJob::dispatch($job->id)->onConnection('media')->onQueue('media');
                    $count++;
                } elseif ($job->stage === 'rendering' && ($job->next_poll_at === null || $job->next_poll_at->isPast())) {
                    PollVideoJob::dispatch($job->id)->onConnection('media')->onQueue('media');
                    $count++;
                } elseif ($job->stage === 'saving' && $job->processing_started_at?->lt(now()->subMinutes(6))) {
                    $this->fail($job->id, 'The video result could not be saved. Reserved tokens have been returned.');
                    $count++;
                } elseif ($job->stage === 'submitting' && $job->processing_started_at?->lt(now()->subMinutes(6))) {
                    $this->failSubmission($job->id, 'The video request was interrupted. It was not automatically resubmitted.');
                    $count++;
                } elseif ($job->stage === 'reviewing' && $job->processing_started_at?->lt(now()->subMinutes(6))) {
                    // Retire pre-cutover review claims without resubmitting them.
                    $count += DB::transaction(function () use ($job): int {
                        $job = VideoJob::query()->lockForUpdate()->find($job->id);
                        if (! $job || $job->status !== 'processing' || $job->stage !== 'reviewing'
                            || $job->submitted_at !== null || $job->upstream_job_id !== null
                            || ! $job->processing_started_at?->lt(now()->subMinutes(6))) {
                            return 0;
                        }
                        $this->terminate($job, 'failed', 'The video request was interrupted. It was not automatically resubmitted.');

                        return 1;
                    });
                }
            }
        });
        VideoJob::query()->whereIn('status', ['failed', 'completed'])->where('billing_mode', 'wallet')->where('billing_status', 'reserved')
            ->orderBy('id')->chunkById(100, function ($jobs) use (&$count): void {
                foreach ($jobs as $job) {
                    $count += $this->reconcileTerminalWalletReservation($job->id) ? 1 : 0;
                }
            });
        VideoJob::query()->where('status', 'failed')->whereNotNull('reference_path')->orderBy('id')
            ->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) {
                    $this->discardReference($job);
                }
            });

        return $count;
    }

    public function reconcileTerminalWalletReservation(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $job = VideoJob::query()->whereIn('status', ['failed', 'completed'])
                ->where('billing_mode', 'wallet')->where('billing_status', 'reserved')->lockForUpdate()->find($id);
            if (! $job) {
                return false;
            }
            $reservation = ['reference_id' => $job->billing_reference_id, 'amount_microusd' => $job->billing_reserved_microusd];
            if ($job->status === 'failed') {
                Wallet::release($job->user_id, $reservation, 'Video generation did not complete');
            } else {
                Wallet::settle($job->user_id, $reservation, $job->billing_reserved_microusd, [
                    'service' => 'video', 'model' => $job->model, 'meter' => 'unit', 'quantity' => 1,
                    'description' => 'Video usage: '.$job->model,
                ]);
            }
            $job->timestamps = false;
            $job->update(['billing_status' => $job->status === 'failed' ? 'released' : 'settled']);

            return true;
        });
    }

    public function cancel(User $user, string $jobId): VideoJob
    {
        return DB::transaction(function () use ($user, $jobId): VideoJob {
            $job = VideoJob::query()->where('job_id', $jobId)->lockForUpdate()->firstOrFail();
            abort_unless($user->isAdmin() || $job->user_id === $user->id, 404);
            if (self::cancellation($job)['can_cancel']) {
                $this->terminate($job, 'cancelled', 'Video generation was cancelled. Reserved credit has been returned.');
            }

            return $job;
        });
    }

    public static function cancellation(VideoJob $job): array
    {
        $reason = match (true) {
            $job->status === 'completed' => 'job_completed',
            $job->stage === 'cancelled' => 'already_cancelled',
            $job->status === 'failed' => 'job_failed',
            $job->status === 'pending' && $job->stage === 'queued'
                && $job->submitted_at === null && $job->upstream_job_id === null => null,
            in_array($job->stage, ['submitting', 'submitted', 'rendering', 'saving'], true)
                || $job->submitted_at !== null || $job->upstream_job_id !== null => 'submission_started',
            default => 'cancellation_unavailable',
        };

        return [
            'can_cancel' => $reason === null,
            'cancel_reason_code' => $reason,
            'cancel_reason' => match ($reason) {
                'job_completed' => 'This video has already completed and cannot be cancelled.',
                'already_cancelled' => 'This video request is already cancelled.',
                'job_failed' => 'This video has already failed and cannot be cancelled.',
                'submission_started' => 'Video submission has started. It cannot be cancelled and this action does not refund tokens.',
                'cancellation_unavailable' => 'Cancellation is unavailable for this video. Refresh its status before taking another action.',
                default => null,
            },
        ];
    }

    public function failSubmission(int $id, string $message): void
    {
        DB::transaction(function () use ($id, $message): void {
            $job = VideoJob::query()->lockForUpdate()->find($id);
            if (! $job || $job->upstream_job_id !== null || $job->submitted_at !== null
                || ! (($job->status === 'pending' && $job->stage === 'queued')
                    || ($job->status === 'processing' && in_array($job->stage, ['submitting', 'saving'], true)))) {
                return;
            }
            $this->terminate($job, 'failed', $message);
        });
    }

    public function fail(int $id, string $message): void
    {
        DB::transaction(function () use ($id, $message): void {
            $job = VideoJob::query()->lockForUpdate()->find($id);
            if ($job) {
                $this->terminate($job, 'failed', $message);
            }
        });
    }

    private function terminate(VideoJob $job, string $stage, string $message): void
    {
        if (! in_array($job->status, ['pending', 'processing'], true)) {
            return;
        }
        $description = $stage === 'cancelled' ? 'Video generation cancelled' : 'Video generation did not complete';
        $reservation = ['reference_id' => $job->billing_reference_id, 'amount_tokens' => $job->tokens_reserved];
        if (in_array($job->billing_mode, ['tokens', 'admin'], true)) {
            $this->tokens->release($job->user_id, $reservation, $description);
        } elseif ($job->billing_status === 'reserved') {
            Wallet::release($job->user_id, ['reference_id' => $job->billing_reference_id, 'amount_microusd' => $job->billing_reserved_microusd], $description);
        }
        $job->update([
            'status' => 'failed', 'stage' => $stage,
            'error_message' => $message,
            'billing_status' => 'released', 'next_poll_at' => null, 'processing_started_at' => null,
            'completed_at' => now(),
        ]);
        $this->discardReference($job);
    }

    private function discardReference(VideoJob $job): void
    {
        $path = $job->reference_path;
        if ($path === null) {
            return;
        }
        DB::afterCommit(function () use ($job, $path): void {
            if (! $this->references->delete($path)) {
                return;
            }
            VideoJob::query()->whereKey($job->id)->where('status', 'failed')->where('reference_path', $path)
                ->update(['reference_path' => null, 'reference_mime_type' => null]);
            $job->reference_path = null;
            $job->reference_mime_type = null;
        });
    }

    /** The owned MediaAsset backing a coordinator image_to_video job's reference. */
    private function coordinatorReference(VideoJob $job): MediaAsset
    {
        $assetId = is_array($job->reference_asset_ids) ? ($job->reference_asset_ids[0] ?? null) : null;
        $asset = is_string($assetId) ? MediaAsset::find($assetId) : null;
        if ($asset === null) {
            throw new AiProxyException('The reference asset for this video is no longer available.', 422);
        }

        return $asset;
    }

    private function avatarReferenceUrl(AiProviderProfile $provider, MediaAsset $asset): string
    {
        if ($provider->protocol === 'fal') {
            if ($asset->media_type === 'audio' && $asset->size_bytes > 15_000_000) {
                throw new AiProxyException('Speech audio must not exceed 15 MB for this model.', 422);
            }

            return $this->assets->dataUri($asset);
        }
        if ($provider->protocol !== 'kinovi') {
            throw new AiProxyException('Avatar references are not supported by this provider.', 422);
        }
        $stream = Storage::disk($asset->storage_disk)->readStream($asset->storage_path);
        if (! is_resource($stream)) {
            throw new AiProxyException('An avatar input is no longer available. Upload it again.', 422);
        }
        try {
            return $this->transport->uploadKinoviReference(
                $provider, $stream, basename($asset->storage_path), $asset->mime, (int) $asset->size_bytes,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function avatarReference(VideoJob $job, string $key, string $type): MediaAsset
    {
        $id = $job->settings[$key] ?? null;
        $asset = is_string($id) && in_array($id, $job->reference_asset_ids ?? [], true) ? MediaAsset::find($id) : null;
        if (! $asset || $asset->user_id !== $job->user_id || $asset->media_type !== $type
            || ! $asset->signature_ok || $asset->retention_status !== 'active'
            || ($asset->expires_at !== null && $asset->expires_at->isPast())
            || ! Storage::disk($asset->storage_disk)->exists($asset->storage_path)) {
            throw new AiProxyException('An avatar input is no longer available. Upload it again.', 422);
        }

        return $asset;
    }

    public function payload(VideoJob $job): array
    {
        return [
            'job_id' => $job->job_id, 'mode' => $job->mode, 'model' => $job->model,
            'prompt' => $job->prompt, 'improved_prompt' => $job->improved_prompt,
            'pro_mode' => (bool) $job->pro_mode, 'has_reference' => (bool) $job->has_reference,
            // A reference lives either in the per-job store (legacy) or as an owned MediaAsset
            // (coordinator); both are served by the same owner-gated route.
            'reference_url' => ($job->reference_path !== null || (is_array($job->reference_asset_ids) && $job->reference_asset_ids !== []))
                ? '/api/v/'.$job->job_id.'/reference' : null,
            'speech_audio_url' => $job->mode === 'avatar' && isset($job->settings['speech_audio'])
                ? '/api/media/assets/'.$job->settings['speech_audio'] : null,
            'aspect_ratio' => $job->aspect_ratio, 'duration' => $job->duration,
            'status' => $job->status, 'stage' => $job->stage,
            ...self::cancellation($job),
            'video_url' => $job->mode === 'avatar' && $job->video_url !== null
                ? '/api/avatar/'.$job->job_id.'/asset' : $job->video_url,
            'thumbnail_url' => $job->thumbnail_url,
            'error' => $job->error_message, 'error_message' => $job->error_message,
            'moderation_reason_code' => $job->moderation_reason_code,
            'billing_mode' => $job->billing_mode, 'billing_status' => $job->billing_status,
            'tokens_reserved' => $job->tokens_reserved, 'tokens_used' => $job->tokens_used,
            'billing_reserved_microusd' => $job->billing_reserved_microusd,
            'created_at' => $job->created_at?->toISOString(), 'updated_at' => $job->updated_at?->toISOString(),
            'completed_at' => $job->completed_at?->toISOString(),
        ];
    }

    private function complete(int $id, array $state): void
    {
        $job = VideoJob::query()->findOrFail($id);
        if ($job->status !== 'processing' || $job->stage !== 'saving') {
            return;
        }
        $videoUrl = $this->videos->persist($job, $state['url']);
        $retained = DB::transaction(function () use ($id, $videoUrl): bool {
            $job = VideoJob::query()->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'processing' || $job->stage !== 'saving') {
                return $job->status === 'completed';
            }
            $this->tokens->settle($job->user_id, ['reference_id' => $job->billing_reference_id, 'amount_tokens' => $job->tokens_reserved], ['service' => 'video', 'model' => $job->model]);
            $job->update([
                'status' => 'completed', 'stage' => 'completed', 'video_url' => $videoUrl,
                'thumbnail_url' => null, 'billing_status' => 'settled',
                'completed_at' => now(), 'next_poll_at' => null, 'processing_started_at' => null,
            ]);

            return true;
        });
        if (! $retained) {
            Storage::disk('local')->delete(GeneratedVideoStore::path($job->job_id));
        }
    }

    private function state(array $result): array
    {
        $status = strtolower((string) ($result['status'] ?? data_get($result, 'data.status') ?? 'queued'));
        $url = $result['video_url'] ?? data_get($result, 'data.video_url') ?? data_get($result, 'content.video_url') ?? data_get($result, 'data.content.video_url') ?? $result['url'] ?? data_get($result, 'data.url');
        $thumbnail = $result['thumbnail_url'] ?? data_get($result, 'data.thumbnail_url');
        if ($url !== null && (! is_string($url) || ! GeneratedImageStore::validResultUrl($url))) {
            throw new AiProxyException('The video provider returned an invalid result URL.', 502);
        }

        return [
            'url' => $url,
            'thumbnail' => is_string($thumbnail) && GeneratedImageStore::validResultUrl($thumbnail) ? $thumbnail : null,
            'failed' => in_array($status, ['failed', 'failure', 'error', 'cancelled', 'canceled', 'rejected'], true),
        ];
    }

    private function provider(VideoJob $job): AiProviderProfile
    {
        $provider = AiProviderProfile::query()->find($job->provider_id);
        if (! $provider || ! $provider->is_enabled || ! hash_equals((string) $job->connection_fingerprint, self::fingerprint($provider))) {
            throw new AiProxyException('The video connection changed. No request was sent to a different provider.', 503);
        }

        return $provider;
    }

    private static function fingerprint(AiProviderProfile $provider): string
    {
        return hash('sha256', json_encode(array_intersect_key($provider->getRawOriginal(), array_flip(['base_url', 'api_key', 'protocol', 'api_version'])), JSON_THROW_ON_ERROR));
    }

    private function schedulePoll(int $id): void
    {
        PollVideoJob::dispatch($id)->onConnection('media')->onQueue('media')->delay(now()->addSeconds(8));
    }
}
