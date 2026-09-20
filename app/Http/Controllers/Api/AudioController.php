<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\AudioJob;
use App\Models\UserToken;
use App\Services\AudioGenerationService;
use App\Services\GeneratedAudioStore;
use App\Services\MediaModelConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AudioController extends Controller
{
    public function models(Request $request): JsonResponse
    {
        $user = $request->user();
        $models = AiModelProfile::query()->with('provider')->where('category', 'audio')
            ->where('is_enabled', true)->where('is_available', true)->orderBy('display_name')->get()
            ->filter(fn (AiModelProfile $model): bool => MediaModelConfig::allowedFor($user, $model)
                && $model->provider->protocol === 'fal' && is_int($model->token_cost)
                && $model->token_cost > 0 && $model->token_cost <= 2_147_483_647)
            ->map(fn (AiModelProfile $model): array => MediaModelConfig::publicModel($model))->values()->all();

        return response()->json(['models' => $models, 'balance' => UserToken::getBalance($user->id)]);
    }

    public function history(Request $request): JsonResponse
    {
        $query = AudioJob::query()->where('user_id', $request->user()->id);
        $active = (clone $query)->whereIn('status', ['pending', 'processing'])->latest()->get();
        $recent = (clone $query)->whereIn('status', ['completed', 'failed'])->latest()->limit(50)->get();
        $jobs = $active->concat($recent)->sortByDesc('created_at')->values();

        return response()->json([
            'jobs' => $jobs->map(fn (AudioJob $job): array => AudioGenerationService::payload($job))->all(),
            'balance' => UserToken::getBalance($request->user()->id),
        ]);
    }

    public function generate(Request $request, AudioGenerationService $audio): JsonResponse
    {
        $input = $request->validate([
            'model' => 'required|string|max:160',
            'mode' => 'required|in:speech,music',
            'prompt' => 'required|string|max:4000',
            'voice' => 'nullable|string|max:80',
            'speed' => 'nullable|numeric|min:0.1|max:5',
            'duration' => 'nullable|integer|min:1|max:190',
            'tempo' => 'nullable|integer|min:40|max:200',
        ]);
        $job = $audio->create($request->user(), $input);

        return response()->json([
            'job' => AudioGenerationService::payload($job),
            'balance' => UserToken::getBalance($request->user()->id),
        ], 202);
    }

    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = AudioJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->firstOrFail();

        return response()->json(['job' => AudioGenerationService::payload($job), 'balance' => UserToken::getBalance($request->user()->id)]);
    }

    public function cancel(Request $request, string $jobId, AudioGenerationService $audio): JsonResponse
    {
        $job = $audio->cancel($request->user(), $jobId);
        $payload = AudioGenerationService::payload($job);
        $cancelled = $job->status === 'failed' && $job->stage === 'cancelled';

        return response()->json([
            ...($cancelled ? [] : ['message' => $payload['cancel_reason'], 'reason_code' => $payload['cancel_reason_code']]),
            'job' => $payload,
            'balance' => UserToken::getBalance($request->user()->id),
        ], $cancelled ? 200 : 409);
    }

    /** Delete one finished audio job and its private asset. Active work is protected. */
    public function destroy(Request $request, string $jobId): JsonResponse
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $jobId): void {
            $job = AudioJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->lockForUpdate()->firstOrFail();
            if (in_array($job->status, ['pending', 'processing'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['job' => 'Pekerjaan masih berjalan. Tunggu sampai selesai sebelum menghapusnya.']);
            }
            $path = GeneratedAudioStore::path($job->job_id);
            $disk = Storage::disk('local');
            if ($disk->exists($path)) {
                $disk->deleteDirectory(dirname($path));
            }
            $job->delete();
        });

        return response()->json(['deleted_count' => 1]);
    }

    public function asset(Request $request, string $jobId): BinaryFileResponse
    {
        $job = AudioJob::query()->where('job_id', $jobId)->firstOrFail();
        abort_unless($request->user()->isAdmin() || $job->user_id === $request->user()->id, 404);
        $path = GeneratedAudioStore::path($job->job_id);
        $extension = GeneratedAudioStore::extensionForMime((string) $job->mime_type);
        $disk = Storage::disk('local');
        abort_unless($job->status === 'completed' && $job->audio_url === '/api/audio/'.$job->job_id.'/asset'
            && $job->audio_path === $path && $extension !== null && $disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $job->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ])->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'audio-'.$job->job_id.'.'.$extension);
    }
}
