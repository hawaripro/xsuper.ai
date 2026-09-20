<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Services\GeneratedVideoStore;
use App\Services\MediaModelConfig;
use App\Services\VideoGenerationService;
use App\Services\VideoReferenceStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VideoController extends Controller
{
    public function generate(Request $request, VideoGenerationService $videos): JsonResponse
    {
        $input = $request->validate([
            'prompt' => 'required|string|max:4000',
            'model' => 'required|string|max:120',
            'aspect_ratio' => 'nullable|string|max:16',
            'count' => 'required|integer|min:1|max:10',
            'mode' => 'required|in:prompt,ab_testing',
            'pro_mode' => 'sometimes|boolean',
            'reference_image' => 'sometimes|nullable|file|mimetypes:image/jpeg,image/png,image/webp|max:10240',
            'ugc_variation' => 'sometimes|boolean',
            'cta' => 'nullable|string|max:500',
            'settings' => 'sometimes|array:duration',
            'settings.duration' => 'nullable|integer|min:1|max:600',
        ]);
        $jobs = $videos->create($request->user(), $input);

        return response()->json([
            'message' => 'Video requests queued for generation.',
            'jobs' => array_map(fn (VideoJob $job): array => $videos->payload($job), $jobs),
            'tokens_used' => array_sum(array_map(fn (VideoJob $job): int => $job->tokens_reserved, $jobs)),
            'balance' => UserToken::getBalance($request->user()->id),
            'billing_mode' => 'tokens',
        ], 202);
    }

    public function history(Request $request, VideoGenerationService $videos): JsonResponse
    {
        $query = VideoJob::query()->where('user_id', $request->user()->id);
        $active = (clone $query)->whereIn('status', ['pending', 'processing'])->latest()->get();
        $recent = (clone $query)->whereIn('status', ['completed', 'failed'])->latest()->limit(50)->get();
        $jobs = $active->concat($recent)->sortByDesc('created_at')->values();

        return response()->json([
            'jobs' => $jobs->map(fn (VideoJob $job): array => $videos->payload($job))->all(),
            'balance' => UserToken::getBalance($request->user()->id),
        ]);
    }

    /** Delete one finished video job, its private asset, and its reference image. */
    public function destroy(Request $request, string $jobId, VideoReferenceStore $references): JsonResponse
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $jobId, $references): void {
            $job = VideoJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->lockForUpdate()->firstOrFail();
            if (in_array($job->status, ['pending', 'processing'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['job' => 'Pekerjaan masih berjalan. Batalkan dulu sebelum menghapusnya.']);
            }
            $this->removeVideoAssets($job, $references);
            $job->delete();
        });

        return response()->json(['deleted_count' => 1]);
    }

    /** Delete only the uploaded reference image, keeping the video job itself. */
    public function destroyReference(Request $request, string $jobId, VideoReferenceStore $references): JsonResponse
    {
        $job = VideoJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->firstOrFail();
        $reference = $references->existingPath($job);
        if ($reference !== null) {
            $references->delete($reference);
        }

        return response()->json(['deleted_count' => $reference !== null ? 1 : 0]);
    }

    /** Clear every finished video job; queued and running work stays untouched. */
    public function destroyAll(Request $request, VideoReferenceStore $references): JsonResponse
    {
        $removed = 0;
        VideoJob::query()->where('user_id', $request->user()->id)
            ->whereNotIn('status', ['pending', 'processing'])->orderBy('id')
            ->chunkById(50, function ($jobs) use (&$removed, $references): void {
                foreach ($jobs as $job) {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($job, $references, &$removed): void {
                        $locked = VideoJob::query()->lockForUpdate()->find($job->id);
                        if (! $locked || in_array($locked->status, ['pending', 'processing'], true)) {
                            return;
                        }
                        $this->removeVideoAssets($locked, $references);
                        $locked->delete();
                        $removed++;
                    });
                }
            });

        return response()->json(['deleted_count' => $removed]);
    }

    private function removeVideoAssets(VideoJob $job, VideoReferenceStore $references): void
    {
        $asset = GeneratedVideoStore::path($job->job_id);
        if (Storage::disk('local')->exists($asset)) {
            Storage::disk('local')->delete($asset);
        }
        $reference = $references->existingPath($job);
        if ($reference !== null) {
            $references->delete($reference);
        }
    }

    public function status(Request $request, string $jobId, VideoGenerationService $videos): JsonResponse
    {
        $job = VideoJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->firstOrFail();
        if ($job->billing_mode === 'wallet' && $job->billing_status === 'reserved' && in_array($job->status, ['failed', 'completed'], true)) {
            $videos->reconcileTerminalWalletReservation($job->id);
            $job->refresh();
        }

        return response()->json(['job' => $videos->payload($job), 'balance' => UserToken::getBalance($request->user()->id)]);
    }

    public function cancel(Request $request, string $jobId, VideoGenerationService $videos): JsonResponse
    {
        $job = $videos->cancel($request->user(), $jobId);
        $payload = $videos->payload($job);
        $cancelled = $job->status === 'failed' && $job->stage === 'cancelled';

        return response()->json([
            ...($cancelled ? [] : ['message' => $payload['cancel_reason'], 'reason_code' => $payload['cancel_reason_code']]),
            'job' => $payload,
            'balance' => UserToken::getBalance($request->user()->id),
        ], $cancelled ? 200 : 409);
    }

    public function asset(Request $request, string $jobId): BinaryFileResponse
    {
        $job = VideoJob::query()->where('job_id', $jobId)->firstOrFail();
        abort_unless($request->user()->isAdmin() || $job->user_id === $request->user()->id, 404);
        $path = GeneratedVideoStore::path($job->job_id);
        abort_unless($job->status === 'completed' && $job->video_url === '/api/v/'.$job->job_id.'/asset'
            && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'video/mp4', 'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function reference(Request $request, string $jobId, VideoReferenceStore $references): BinaryFileResponse
    {
        $job = VideoJob::query()->where('job_id', $jobId)->firstOrFail();
        abort_unless($request->user()->isAdmin() || $job->user_id === $request->user()->id, 404);
        $path = $references->existingPath($job);
        abort_if($path === null, 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $job->reference_mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function models(Request $request): JsonResponse
    {
        $user = $request->user();
        $models = AiModelProfile::query()->with('provider')->where('category', 'video')
            ->where('is_enabled', true)->where('is_available', true)->orderBy('display_name')->get()
            ->filter(fn (AiModelProfile $model): bool => MediaModelConfig::allowedFor($user, $model)
                && in_array($model->provider->protocol, ['openai', 'fal'], true) && $model->token_cost > 0)
            ->map(fn (AiModelProfile $model): array => MediaModelConfig::publicModel($model))->values()->all();

        return response()->json(['models' => $models, 'balance' => UserToken::getBalance($user->id)]);
    }
}
