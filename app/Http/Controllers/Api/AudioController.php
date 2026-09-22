<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImageGenerationException;
use App\Http\Controllers\Controller;
use App\Media\AssetService;
use App\Media\CapabilityPresenter;
use App\Media\CapabilityResolver;
use App\Media\CapabilityValidator;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Exceptions\CapabilityValidationException;
use App\Media\MediaActivation;
use App\Media\MediaGenerationCoordinator;
use App\Models\AiModelProfile;
use App\Models\AudioJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\UserToken;
use App\Services\AudioGenerationService;
use App\Services\GeneratedAudioStore;
use App\Services\MediaModelConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AudioController extends Controller
{
    public function models(Request $request): JsonResponse
    {
        $user = $request->user();
        $capabilities = app(CapabilityPresenter::class);
        // Kinovi audio is coordinator-only while activation is restricted.
        $protocols = app(MediaActivation::class)->usesCoordinator($user) ? ['fal', 'kinovi'] : ['fal'];
        $models = AiModelProfile::query()->with('provider')->where('category', 'audio')
            ->where('is_enabled', true)->where('is_available', true)->orderBy('display_name')->get()
            ->filter(fn (AiModelProfile $model): bool => MediaModelConfig::allowedFor($user, $model)
                && in_array($model->provider->protocol, $protocols, true) && is_int($model->token_cost)
                && $model->token_cost > 0 && $model->token_cost <= 2_147_483_647)
            ->map(function (AiModelProfile $model) use ($capabilities): array {
                $payload = MediaModelConfig::publicModel($model);
                $payload['capabilities'] = $capabilities->forModel($model);

                return $payload;
            })->filter(fn (array $model): bool => $model['capabilities'] !== [])->values()->all();

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
        // The emergency pause covers BOTH audio paths, exactly like image and video.
        try {
            app(MediaActivation::class)->assertNotPaused();
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->responseStatus());
        }
        // Capability submissions carry an `operation`; legacy submissions stay untouched.
        if ($request->filled('operation')) {
            return $this->generateCapability($request, $audio);
        }
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

    /**
     * Capability-driven audio (text_to_speech, music). The coordinator user gets the
     * capability path; while activation is restricted, a non-coordinator member keeps the
     * existing verified fal path and never reaches Kinovi-only operations.
     */
    private function generateCapability(Request $request, AudioGenerationService $audio): JsonResponse
    {
        $validated = $request->validate([
            'operation' => ['required', Rule::in(['text_to_speech', 'music'])],
            'model' => ['required', 'string', 'max:160', Rule::exists('ai_model_profiles', 'model_id')->where(
                fn ($query) => $query->where('category', 'audio')->where('is_enabled', true),
            )],
            'prompt' => 'required|string|max:4000',
            'voice' => 'nullable|string|max:80',
            'speed' => 'nullable|numeric|min:0.1|max:5',
            'duration' => 'nullable|integer|min:1|max:190',
            'tempo' => 'nullable|integer|min:40|max:200',
            'instrumental' => 'nullable|boolean',
            'custom' => 'nullable|boolean',
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'expected_price_tokens' => ['nullable', 'integer', 'min:1'],
            'expected_capability_hash' => ['nullable', 'string', 'max:64'],
        ]);
        $user = $request->user();
        $model = AiModelProfile::query()->with('provider')->where('model_id', $validated['model'])->first();
        if (! $model || $model->category !== 'audio' || ! MediaModelConfig::allowedFor($user, $model)) {
            return response()->json(['message' => 'The selected audio model is unavailable.'], 503);
        }
        $operation = MediaOperation::from($validated['operation']);
        $rawInputs = ['prompt' => $validated['prompt']];
        foreach (['voice', 'speed', 'duration', 'tempo'] as $param) {
            if (($validated[$param] ?? null) !== null) {
                $rawInputs[$param] = $validated[$param];
            }
        }
        // Preserve an explicit false value when the resolved contract declares a toggle.
        foreach (['instrumental', 'custom'] as $flag) {
            if (array_key_exists($flag, $validated) && $validated[$flag] !== null) {
                $rawInputs[$flag] = (bool) $validated[$flag];
            }
        }
        $options = array_filter([
            'idempotency_key' => $validated['idempotency_key'] ?? null,
            'expected_price_tokens' => $validated['expected_price_tokens'] ?? null,
            'expected_capability_hash' => $validated['expected_capability_hash'] ?? null,
        ], static fn ($v): bool => $v !== null);

        try {
            if (app(MediaActivation::class)->usesCoordinator($user)) {
                $job = app(MediaGenerationCoordinator::class)->startAudio($user, $model, $operation, $rawInputs, 'studio-audio', $options);
            } elseif ($model->provider?->protocol === 'fal') {
                $job = DB::transaction(function () use ($user, $model, $operation, $rawInputs, $options, $audio): AudioJob {
                    User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $key = trim((string) ($options['idempotency_key'] ?? ''));
                    $dedup = $key !== '' ? hash('sha256', $user->id.'|studio-audio|'.$key) : null;
                    if ($dedup !== null) {
                        $existing = AudioJob::query()->where('user_id', $user->id)->where('dedup_key', $dedup)->first();
                        if ($existing !== null) {
                            if (! app(MediaGenerationCoordinator::class)->matchesRequest($existing, $operation, $model->model_id, $rawInputs)) {
                                throw new ImageGenerationException('This request key was already used with different input.', 409);
                            }

                            return $existing;
                        }
                    }
                    $model = AiModelProfile::query()->with('provider')->lockForUpdate()->findOrFail($model->id);
                    $resolved = app(CapabilityResolver::class)->resolve($model, $operation);
                    if (isset($options['expected_price_tokens']) && (int) $options['expected_price_tokens'] !== $model->token_cost) {
                        throw new ImageGenerationException('The price changed since you opened this form. Review and try again.', 409);
                    }
                    if (isset($options['expected_capability_hash']) && ! hash_equals($resolved->sourceHash, $options['expected_capability_hash'])) {
                        throw new ImageGenerationException('This model was updated since you opened this form. Review and try again.', 409);
                    }
                    $values = app(CapabilityValidator::class)->validate($resolved->capability, $rawInputs);
                    $fingerprint = MediaGenerationCoordinator::fingerprintPayload($operation, $model->model_id, $values);
                    $revision = app(CapabilityResolver::class)->ensureRevision($model, $operation, $resolved);
                    $job = $audio->create($user, [
                        'model' => $model->model_id,
                        'mode' => $operation === MediaOperation::TextToSpeech ? 'speech' : 'music',
                        ...$values['inputs'], ...$values['params'],
                    ]);
                    $job->update(['dedup_key' => $dedup, 'payload_fingerprint' => $fingerprint, 'capability_revision_id' => $revision->id]);

                    return $job;
                });
            } else {
                return response()->json(['message' => 'This operation is not available for your account yet.'], 503);
            }
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'balance' => UserToken::getBalance($user->id)], $exception->responseStatus());
        } catch (CapabilityValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }

        return response()->json([
            'job' => AudioGenerationService::payload($job),
            'balance' => UserToken::getBalance($user->id),
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

    /** Delete one finished audio job and every private track. Active work is protected. */
    public function destroy(Request $request, string $jobId): JsonResponse
    {
        DB::transaction(function () use ($request, $jobId): void {
            $job = AudioJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->lockForUpdate()->firstOrFail();
            if (in_array($job->status, ['pending', 'processing'], true)) {
                throw ValidationException::withMessages(['job' => 'Pekerjaan masih berjalan. Tunggu sampai selesai sebelum menghapusnya.']);
            }
            Storage::disk('local')->deleteDirectory(GeneratedAudioStore::directory($job->job_id));
            $job->delete();
        });

        return response()->json(['deleted_count' => 1]);
    }

    public function asset(Request $request, string $jobId, int $index): BinaryFileResponse
    {
        $job = AudioJob::query()->where('job_id', $jobId)->firstOrFail();
        abort_unless($request->user()->isAdmin() || $job->user_id === $request->user()->id, 404);
        $path = GeneratedAudioStore::outputPath($job, $index);
        $output = $job->outputs[$index] ?? null;
        $extension = GeneratedAudioStore::extensionForMime((string) ($output['mime_type'] ?? ''));
        $disk = Storage::disk('local');
        abort_unless($job->status === 'completed' && $path !== null && $extension !== null && $disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $output['mime_type'],
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ])->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'audio-'.$job->job_id.'-'.($index + 1).'.'.$extension);
    }

    /** Copy owned generated speech into an independently retained avatar reference. */
    public function reference(Request $request, string $jobId): JsonResponse
    {
        $input = $request->validate(['index' => ['required', 'integer', 'min:0']]);
        try {
            $asset = DB::transaction(function () use ($request, $jobId, $input): MediaAsset {
                $job = AudioJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->lockForUpdate()->firstOrFail();
                $index = (int) $input['index'];
                $path = GeneratedAudioStore::outputPath($job, $index);
                $disk = Storage::disk('local');
                abort_unless($job->status === 'completed' && $job->mode === 'speech' && $path !== null && $disk->exists($path), 404);
                $existing = MediaAsset::query()->where('user_id', $job->user_id)->where('role', InputRole::SpeechAudio->value)
                    ->where('metadata->audio_job_id', $job->job_id)->where('metadata->audio_output_index', $index)
                    ->where('retention_status', 'active')->where('signature_ok', true)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
                if ($existing !== null && Storage::disk($existing->storage_disk)->exists($existing->storage_path)) {
                    return $existing;
                }
                $file = new UploadedFile($disk->path($path), 'speech.audio', null, null, true);
                $asset = app(AssetService::class)->store($request->user(), $file, InputRole::SpeechAudio);
                $asset->update(['metadata' => [
                    ...($asset->metadata ?? []), 'audio_job_id' => $job->job_id, 'audio_output_index' => $index,
                ]]);

                return $asset;
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['asset' => [
            'id' => $asset->id, 'media_type' => $asset->media_type, 'mime' => $asset->mime,
            'size_bytes' => $asset->size_bytes, 'preview_url' => '/api/media/assets/'.$asset->id,
        ]]);
    }
}
