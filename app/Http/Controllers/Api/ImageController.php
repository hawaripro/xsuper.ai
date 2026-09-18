<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImageGenerationException;
use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Services\AudioGenerationService;
use App\Services\ImageGenerationService;
use App\Services\MediaModelConfig;
use App\Services\VideoGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImageController extends Controller
{
    private const CANCELLATION = [
        'generation_mode' => 'synchronous',
        'can_cancel' => false,
        'cancel_reason_code' => 'synchronous_generation',
        'cancel_reason' => 'Image generation cannot be cancelled after submission. Closing the page does not stop generation or refund tokens.',
    ];

    public function models(Request $request): JsonResponse
    {
        $user = $request->user();
        $models = AiModelProfile::query()->with('provider')->where('category', 'image')
            ->where('is_enabled', true)->where('is_available', true)->orderBy('display_name')->get()
            ->filter(fn (AiModelProfile $model): bool => MediaModelConfig::allowedFor($user, $model)
                && $model->token_cost > 0)
            ->map(fn (AiModelProfile $model): array => MediaModelConfig::publicModel($model))
            ->values()->all();

        return response()->json(['models' => $models, 'balance' => UserToken::getBalance($user->id), ...self::CANCELLATION]);
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
            'size' => ['nullable', 'string', 'max:32'],
            'n' => 'required|integer|min:1|max:10',
        ]);

        try {
            $job = $generation->generate(
                $request->user(),
                $validated['model'],
                $validated['prompt'],
                $validated['size'] ?? 'auto',
                $validated['n'],
            );
        } catch (ImageGenerationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'job' => $exception->job() ? $this->imagePayload($exception->job()) : null,
                'balance' => UserToken::getBalance($request->user()->id),
            ], $exception->responseStatus());
        }

        return response()->json(['job' => $this->imagePayload($job), 'balance' => UserToken::getBalance($request->user()->id)], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $query = ImageJob::query()->where('user_id', $request->user()->id);
        $active = (clone $query)->whereIn('status', ['pending', 'processing'])->latest()->get();
        $recent = (clone $query)->whereIn('status', ['completed', 'failed'])->latest()->limit(50)->get();
        $jobs = $active->concat($recent)->sortByDesc('created_at')->values()
            ->map(fn (ImageJob $job): array => $this->imagePayload($job))
            ->all();

        return response()->json(['jobs' => $jobs, 'balance' => UserToken::getBalance($request->user()->id)]);
    }

    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = ImageJob::query()
            ->where('user_id', $request->user()->id)
            ->where('job_id', $jobId)
            ->firstOrFail();

        return response()->json(['job' => $this->imagePayload($job), 'balance' => UserToken::getBalance($request->user()->id)]);
    }

    public function asset(Request $request, string $jobId, int $index): BinaryFileResponse
    {
        $job = ImageJob::query()->where('job_id', $jobId)->firstOrFail();
        abort_unless($request->user()->isAdmin() || $job->user_id === $request->user()->id, 404);
        $asset = $job->asset_paths[(string) $index] ?? null;
        abort_unless(is_array($asset) && Storage::disk('local')->exists($asset['path']), 404);

        return response()->file(Storage::disk('local')->path($asset['path']), [
            'Content-Type' => $asset['mime'], 'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
        $audioQuery = AudioJob::query()->with('user:id,name,email')->latest();
        if (isset($validated['status'])) {
            $imageQuery->where('status', $validated['status']);
            $videoQuery->where('status', $validated['status']);
            $audioQuery->where('status', $validated['status']);
        }

        return response()->json([
            'images' => $imageQuery->limit($limit)->get()
                ->map(fn (ImageJob $job): array => $this->imagePayload($job, true))
                ->all(),
            'videos' => $videoQuery->limit($limit)->get()
                ->map(fn (VideoJob $job): array => $this->videoPayload($job))
                ->all(),
            'audio' => $audioQuery->limit($limit)->get()
                ->map(fn (AudioJob $job): array => [
                    ...AudioGenerationService::payload($job),
                    'user' => $job->user?->only(['id', 'name', 'email']),
                ])
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
            'stage' => $job->stage,
            ...self::CANCELLATION,
            'result_urls' => $job->result_urls ?? [],
            'error' => $job->error_message,
            'billing_status' => $job->billing_status,
            'cost_microusd' => $job->billing_reserved_microusd,
            'billing_mode' => $job->billing_mode,
            'tokens_reserved' => $job->tokens_reserved,
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
            'pro_mode' => (bool) $job->pro_mode,
            'has_reference' => (bool) $job->has_reference,
            'reference_url' => $job->reference_path ? '/api/v/'.$job->job_id.'/reference' : null,
            'status' => $job->status,
            'video_url' => $job->video_url,
            'thumbnail_url' => $job->thumbnail_url,
            'error' => $job->error_message,
            'billing_status' => $job->billing_status,
            'cost_microusd' => $job->billing_reserved_microusd,
            'stage' => $job->stage,
            ...VideoGenerationService::cancellation($job),
            'billing_mode' => $job->billing_mode,
            'tokens_reserved' => $job->tokens_reserved,
            'completed_at' => $job->completed_at?->toISOString(),
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
