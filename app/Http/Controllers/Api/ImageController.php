<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImageGenerationException;
use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\ImageJob;
use App\Models\UsageRate;
use App\Models\VideoJob;
use App\Services\ImageGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ImageController extends Controller
{
    private const SIZES = ['256x256', '512x512', '1024x1024', '1024x1792', '1792x1024'];

    public function models(): JsonResponse
    {
        $rates = UsageRate::query()
            ->active()
            ->where('service', 'image')
            ->where('meter', 'unit')
            ->get()
            ->keyBy('model');
        $models = AiModelProfile::query()
            ->with('provider')
            ->where('category', 'image')
            ->where('is_enabled', true)
            ->whereHas('provider', fn ($query) => $query->where('is_enabled', true))
            ->orderBy('display_name')
            ->get()
            ->filter(fn (AiModelProfile $model): bool => $rates->has($model->model_id))
            ->map(function (AiModelProfile $model) use ($rates): array {
                $rate = $rates->get($model->model_id);

                return [
                    'id' => $model->model_id,
                    'name' => $model->display_name,
                    'tier' => $model->tier,
                    'capabilities' => $model->capabilities ?? [],
                    'provider' => $model->provider?->name,
                    'price_usd' => (float) $rate->price_usd,
                    'price_idr' => (float) $rate->price_idr,
                    'unit' => $rate->unit,
                ];
            })
            ->values()
            ->all();

        return response()->json(['models' => $models]);
    }

    public function generate(Request $request, ImageGenerationService $generation): JsonResponse
    {
        $validated = $request->validate([
            'model' => [
                'required',
                'string',
                'max:160',
                Rule::exists('ai_model_profiles', 'model_id')->where(
                    fn ($query) => $query->where('category', 'image')->where('is_enabled', true),
                ),
            ],
            'prompt' => 'required|string|max:4000',
            'size' => ['required', 'string', Rule::in(self::SIZES)],
            'n' => 'required|integer|min:1|max:4',
        ]);

        try {
            $job = $generation->generate(
                $request->user(),
                $validated['model'],
                $validated['prompt'],
                $validated['size'],
                $validated['n'],
            );
        } catch (ImageGenerationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'job' => $exception->job() ? $this->imagePayload($exception->job()) : null,
            ], $exception->responseStatus());
        }

        return response()->json(['job' => $this->imagePayload($job)], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $jobs = ImageJob::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (ImageJob $job): array => $this->imagePayload($job))
            ->all();

        return response()->json(['jobs' => $jobs]);
    }

    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = ImageJob::query()
            ->where('user_id', $request->user()->id)
            ->where('job_id', $jobId)
            ->firstOrFail();

        return response()->json(['job' => $this->imagePayload($job)]);
    }

    public function adminQueue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['pending', 'processing', 'completed', 'failed'])],
            'limit' => 'nullable|integer|min:1|max:200',
        ]);
        $limit = $validated['limit'] ?? 100;
        $imageQuery = ImageJob::query()->with('user:id,name,email')->latest();
        $videoQuery = VideoJob::query()->with('user:id,name,email')->latest();
        if (isset($validated['status'])) {
            $imageQuery->where('status', $validated['status']);
            $videoQuery->where('status', $validated['status']);
        }

        return response()->json([
            'images' => $imageQuery->limit($limit)->get()
                ->map(fn (ImageJob $job): array => $this->imagePayload($job, true))
                ->all(),
            'videos' => $videoQuery->limit($limit)->get()
                ->map(fn (VideoJob $job): array => $this->videoPayload($job))
                ->all(),
        ]);
    }

    private function imagePayload(ImageJob $job, bool $includeUser = false): array
    {
        $payload = [
            'job_id' => $job->job_id,
            'model' => $job->model,
            'prompt' => $job->prompt,
            'size' => $job->size,
            'n' => $job->quantity,
            'status' => $job->status,
            'result_urls' => $job->result_urls ?? [],
            'error' => $job->error_message,
            'billing_status' => $job->billing_status,
            'cost_microusd' => $job->billing_reserved_microusd,
            'created_at' => $job->created_at?->toISOString(),
            'updated_at' => $job->updated_at?->toISOString(),
        ];
        if ($includeUser) {
            $payload['user'] = $job->user ? [
                'id' => $job->user->id,
                'name' => $job->user->name,
                'email' => $job->user->email,
            ] : null;
        }

        return $payload;
    }

    private function videoPayload(VideoJob $job): array
    {
        return [
            'job_id' => $job->job_id,
            'mode' => $job->mode,
            'model' => $job->model,
            'prompt' => $job->prompt,
            'aspect_ratio' => $job->aspect_ratio,
            'duration' => $job->duration,
            'status' => $job->status,
            'video_url' => $job->video_url,
            'thumbnail_url' => $job->thumbnail_url,
            'error' => $job->error_message,
            'billing_status' => $job->billing_status,
            'cost_microusd' => $job->billing_reserved_microusd,
            'created_at' => $job->created_at?->toISOString(),
            'updated_at' => $job->updated_at?->toISOString(),
            'user' => $job->user ? [
                'id' => $job->user->id,
                'name' => $job->user->name,
                'email' => $job->user->email,
            ] : null,
        ];
    }
}
