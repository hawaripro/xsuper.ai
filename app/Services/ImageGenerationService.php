<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Exceptions\ImageGenerationException;
use App\Models\AiModelProfile;
use App\Models\ImageJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImageGenerationService
{
    public function __construct(
        private readonly AiProxyService $proxy,
        private readonly UsageBillingService $billing,
        private readonly MediaTokenBillingService $tokens,
        private readonly GeneratedImageStore $images,
    ) {}

    public function generate(User $user, string $model, string $prompt, string $size, int $quantity): ImageJob
    {
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
}
