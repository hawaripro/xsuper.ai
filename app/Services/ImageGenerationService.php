<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Exceptions\ImageGenerationException;
use App\Media\Enums\MediaOperation;
use App\Media\MediaGenerationCoordinator;
use App\Jobs\PollImageJob;
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
    ) {}

    public function generate(User $user, string $model, string $prompt, string $size, int $quantity): ImageJob
    {
        $profile = AiModelProfile::query()->with('provider')->where('model_id', $model)->first();
        if ($profile?->provider?->protocol === 'kinovi') {
            return $this->coordinator->startImage($user, $profile, MediaOperation::TextToImage, ['prompt' => $prompt, 'size' => $size], 'studio-image');
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
            $payload = ['model' => $job->upstream_model_id, 'prompt' => $job->prompt, 'size' => $job->size];
            $result = $this->transport->submitImage($provider, $payload, $job->generation_config['image_path']);
            $taskId = $result['id'] ?? null;
            if (! is_string($taskId) || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1
                || ! in_array($result['status'] ?? null, ['queued', 'processing'], true)) {
                throw new AiProxyException('The image provider did not return a valid job reference.', 502);
            }
            $submitted = DB::transaction(function () use ($job, $taskId): bool {
                $locked = ImageJob::query()->lockForUpdate()->find($job->id);
                if (! $this->ownsClaim($locked, $job)) {
                    return false;
                }
                $locked->update([
                    'upstream_job_id' => $taskId, 'submitted_at' => now(), 'stage' => 'rendering',
                    'processing_started_at' => null, 'processing_token' => null,
                    'next_poll_at' => now()->addSeconds(self::POLL_DELAY_SECONDS),
                ]);

                return true;
            });
            if ($submitted) {
                $this->queuePoll($job->id);
            }
        } catch (Throwable) {
            $this->failClaim($job, 'Image submission could not be completed. Reserved tokens have been returned. No automatic resubmission was made.');
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
            $result = $this->transport->imageStatus($provider, $job->upstream_job_id, $job->generation_config['image_path']);
            $status = $result['status'] ?? null;
            if ($status === 'failed') {
                $this->failClaim($job, 'The image provider could not complete this request. Reserved tokens have been returned.');

                return;
            }
            if (! in_array($status, ['processing', 'completed'], true)) {
                throw new AiProxyException('The image provider returned an invalid status.', 502);
            }
            if ($status === 'completed') {
                $urls = $result['result_urls'] ?? null;
                if (! is_array($urls) || $urls === []) {
                    throw new AiProxyException('The image provider returned no usable result.', 502);
                }
                $saving = DB::transaction(function () use ($job): ?ImageJob {
                    $locked = ImageJob::query()->lockForUpdate()->find($job->id);
                    if (! $this->ownsClaim($locked, $job)) {
                        return null;
                    }
                    $locked->update(['stage' => 'saving', 'processing_started_at' => now(), 'next_poll_at' => null]);

                    return $locked;
                });
                if ($saving) {
                    $this->complete($saving, $urls);
                }

                return;
            }
        } catch (AiProxyException $exception) {
            if ($job->stage === 'saving') {
                $this->failClaim($job, 'The image result could not be saved. Reserved tokens have been returned.');

                return;
            }
            if ($exception->responseStatus() !== 503) {
                $this->failClaim($job, 'The image status could not be verified. Reserved tokens have been returned.');

                return;
            }
        } catch (Throwable) {
            $this->failClaim($job, 'Image generation could not be completed. Reserved tokens have been returned.');

            return;
        }
        $reschedule = DB::transaction(function () use ($job): bool {
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
        if ($reschedule) {
            $this->queuePoll($job->id);
        }
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
