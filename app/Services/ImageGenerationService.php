<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Exceptions\ImageGenerationException;
use App\Models\AiModelProfile;
use App\Models\ImageJob;
use App\Models\UsageRate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImageGenerationService
{
    public function __construct(
        private readonly AiProxyService $proxy,
        private readonly UsageBillingService $billing,
    ) {}

    public function generate(User $user, string $model, string $prompt, string $size, int $quantity): ImageJob
    {
        $profile = AiModelProfile::query()
            ->with('provider')
            ->where('model_id', $model)
            ->where('category', 'image')
            ->where('is_enabled', true)
            ->first();

        if (! $profile || ! $profile->provider || ! $profile->provider->is_enabled) {
            throw new ImageGenerationException('The selected image model is unavailable.', 503);
        }

        $rate = UsageRate::forMeter('image', 'unit', $model);
        if (! $rate || $rate->price_usd === null) {
            throw new ImageGenerationException('Image generation pricing is unavailable.', 503);
        }

        $jobId = (string) Str::uuid();
        $referenceId = "image:{$jobId}";
        [$job, $reservation] = DB::transaction(function () use ($jobId, $model, $prompt, $quantity, $referenceId, $size, $user): array {
            $reservation = $this->billing->reserveUnit(
                (int) $user->id,
                'image',
                $model,
                $quantity,
                $referenceId,
            );
            $job = ImageJob::create([
                'user_id' => $user->id,
                'job_id' => $jobId,
                'model' => $model,
                'prompt' => $prompt,
                'size' => $size,
                'quantity' => $quantity,
                'status' => 'processing',
                'billing_reserved_microusd' => $reservation['amount_microusd'],
                'billing_reference_id' => $reservation['reference_id'],
                'billing_status' => 'reserved',
            ]);

            return [$job, $reservation];
        });

        try {
            $urls = $this->proxy->generateImages($model, $prompt, $size, $quantity);

            return DB::transaction(function () use ($job, $model, $quantity, $reservation, $urls): ImageJob {
                $locked = ImageJob::query()->lockForUpdate()->findOrFail($job->id);
                $this->billing->settleUnit(
                    $locked->user_id,
                    'image',
                    $model,
                    $quantity,
                    $reservation,
                );
                $locked->update([
                    'status' => 'completed',
                    'result_urls' => $urls,
                    'error_message' => null,
                    'billing_status' => 'settled',
                ]);

                return $locked->fresh();
            });
        } catch (AiProxyException $exception) {
            $failed = $this->failAndRelease($job, $reservation, $exception->getMessage());

            throw new ImageGenerationException(
                $exception->getMessage(),
                $exception->responseStatus(),
                $failed,
                $exception,
            );
        } catch (Throwable $exception) {
            $message = 'The AI image provider returned an invalid response.';
            $failed = $this->failAndRelease($job, $reservation, $message);

            throw new ImageGenerationException($message, 502, $failed, $exception);
        }
    }

    private function failAndRelease(ImageJob $job, array $reservation, string $message): ImageJob
    {
        return DB::transaction(function () use ($job, $reservation, $message): ImageJob {
            $locked = ImageJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($locked->billing_status === 'reserved') {
                $this->billing->releaseUnit($locked->user_id, $reservation, 'Failed image generation');
            }
            $locked->update([
                'status' => 'failed',
                'result_urls' => null,
                'error_message' => $message,
                'billing_status' => 'released',
            ]);

            return $locked->fresh();
        });
    }
}
