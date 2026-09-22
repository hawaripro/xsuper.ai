<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Jobs\PollAudioJob;
use App\Jobs\ProcessAudioJob;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AudioJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AudioGenerationService
{
    private const POLL_DELAY_SECONDS = 8;

    // Both leases outlive their worker's hard timeout; expired claims are never resubmitted.
    private const SUBMISSION_LEASE_SECONDS = 480;

    private const POLL_LEASE_SECONDS = 540;

    public function __construct(
        private readonly AiProviderTransport $transport,
        private readonly MediaTokenBillingService $tokens,
        private readonly GeneratedAudioStore $audio,
    ) {}

    public function create(User $user, array $input): AudioJob
    {
        $job = DB::transaction(function () use ($user, $input): AudioJob {
            $model = AiModelProfile::query()->with('provider')->where('model_id', $input['model'])->lockForUpdate()->first();
            if (! $model || $model->category !== 'audio' || ! MediaModelConfig::allowedFor($user, $model)
                || $model->provider->protocol !== 'fal') {
                throw ValidationException::withMessages(['model' => 'The selected audio model is unavailable.']);
            }
            if (! is_int($model->token_cost) || $model->token_cost < 1 || $model->token_cost > 2_147_483_647) {
                throw ValidationException::withMessages(['model' => 'Audio token pricing is unavailable.']);
            }
            $config = MediaModelConfig::forModel($model);
            $options = $this->options($input, $config);
            $jobId = (string) Str::uuid();
            $reservation = $this->tokens->reserve($user, 'audio', $model->model_id, 1, 'audio:'.$jobId, $model->token_cost);

            return AudioJob::create([
                'user_id' => $user->id, 'job_id' => $jobId, 'model' => $model->model_id,
                ...$options,
                'provider_id' => $model->provider_id, 'upstream_model_id' => $model->upstream_model_id ?: $model->model_id,
                'connection_fingerprint' => self::fingerprint($model->provider), 'generation_config' => $config,
                'tokens_reserved' => $reservation['amount_tokens'], 'billing_mode' => $reservation['billing_mode'],
                'billing_reference_id' => $reservation['reference_id'], 'billing_status' => 'reserved',
                'status' => 'pending', 'stage' => 'queued', 'next_poll_at' => now()->addMinute(),
            ]);
        });
        $this->queueSubmission($job->id);

        return $job;
    }

    private function options(array $input, array $config): array
    {
        $mode = $input['mode'] ?? null;
        if (! in_array($mode, ['speech', 'music'], true) || $mode !== ($config['audio_kind'] ?? null)) {
            throw ValidationException::withMessages(['mode' => 'This audio model does not support the selected mode.']);
        }
        $prompt = trim((string) ($input['prompt'] ?? ''));
        $maxCharacters = min(4000, (int) ($config['max_characters'] ?? 0));
        if ($prompt === '' || mb_strlen($prompt) > $maxCharacters) {
            throw ValidationException::withMessages(['prompt' => 'Enter a prompt within this audio model character limit.']);
        }
        $voice = $speed = $duration = $tempo = null;
        if ($mode === 'speech') {
            foreach (['duration', 'tempo'] as $field) {
                if (($input[$field] ?? null) !== null) {
                    throw ValidationException::withMessages([$field => 'This option is not supported by the selected speech model.']);
                }
            }
            $voices = array_column($config['voices'] ?? [], 'id');
            $voice = $input['voice'] ?? ($voices[0] ?? null);
            if (! is_string($voice) || ! in_array($voice, $voices, true)) {
                throw ValidationException::withMessages(['voice' => 'Choose one of this model\'s advertised voices.']);
            }
            $speed = $input['speed'] ?? ($config['speed_default'] ?? null);
            if (! is_numeric($speed) || ! is_finite((float) $speed)
                || (float) $speed < ($config['speed_min'] ?? INF) || (float) $speed > ($config['speed_max'] ?? -INF)) {
                throw ValidationException::withMessages(['speed' => 'The speed is outside this speech model\'s supported range.']);
            }
            $speed = (float) $speed;
        } else {
            foreach (['voice', 'speed'] as $field) {
                if (($input[$field] ?? null) !== null) {
                    throw ValidationException::withMessages([$field => 'This option is not supported by the selected music model.']);
                }
            }
            $duration = filter_var($input['duration'] ?? ($config['duration_default'] ?? null), FILTER_VALIDATE_INT);
            if ($duration === false || $duration < ($config['duration_min'] ?? PHP_INT_MAX)
                || $duration > ($config['duration_max'] ?? 0)) {
                throw ValidationException::withMessages(['duration' => 'The duration is outside this music model\'s supported range.']);
            }
            if (($input['tempo'] ?? null) !== null) {
                $tempo = filter_var($input['tempo'], FILTER_VALIDATE_INT);
                if ($tempo === false || $tempo < 40 || $tempo > 200) {
                    throw ValidationException::withMessages(['tempo' => 'Tempo guidance must be between 40 and 200 BPM.']);
                }
            }
        }
        $providerPrompt = $prompt;
        if ($tempo !== null) {
            $providerPrompt .= "\nTempo guidance: approximately {$tempo} BPM.";
            if (mb_strlen($providerPrompt) > $maxCharacters) {
                throw ValidationException::withMessages(['prompt' => 'Shorten the prompt to leave room for the selected tempo guidance.']);
            }
        }

        return [
            'mode' => $mode, 'prompt' => $prompt, 'provider_prompt' => $providerPrompt,
            'voice' => $voice, 'speed' => $speed, 'duration' => $duration, 'tempo' => $tempo,
        ];
    }

    public function process(int $id): void
    {
        $job = DB::transaction(function () use ($id): ?AudioJob {
            $job = AudioJob::query()->lockForUpdate()->find($id);
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
            $payload = ['model' => $job->upstream_model_id, 'prompt' => $job->provider_prompt];
            if ($job->mode === 'speech') {
                $payload['voice'] = $job->voice;
                $payload['speed'] = $job->speed;
            } else {
                if ($job->duration !== null) {
                    $payload['duration'] = $job->duration;
                }
                // Declared provider params the fixed columns cannot express (Suno custom
                // lyrics / instrumental) travel with the job in `settings`.
                foreach (['custom', 'instrumental'] as $flag) {
                    if (is_array($job->settings) && array_key_exists($flag, $job->settings)) {
                        $payload[$flag] = (bool) $job->settings[$flag];
                    }
                }
            }
            $result = $this->transport->submitAudio($provider, $payload, $job->generation_config['audio_path']);
            $taskId = $result['id'] ?? null;
            if (! is_string($taskId) || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $taskId) !== 1
                || ! in_array($result['status'] ?? null, ['queued', 'processing'], true)) {
                throw new AiProxyException('The audio provider did not return a valid job reference.', 502);
            }
            $submitted = DB::transaction(function () use ($job, $taskId): bool {
                $locked = AudioJob::query()->lockForUpdate()->find($job->id);
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
            $this->failClaim($job, 'Audio submission could not be completed. Reserved tokens have been returned. No automatic resubmission was made.');
        }
    }

    public function poll(int $id): void
    {
        $job = DB::transaction(function () use ($id): ?AudioJob {
            $job = AudioJob::query()->lockForUpdate()->find($id);
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
            $this->failClaim($job, 'Audio generation timed out. Reserved tokens have been returned.');

            return;
        }
        try {
            $provider = $this->provider($job);
        } catch (Throwable) {
            $this->failClaim($job, 'The audio connection changed or became unavailable. Reserved tokens have been returned.');

            return;
        }
        try {
            $result = $this->transport->audioStatus($provider, $job->upstream_job_id, $job->generation_config['audio_status_path']);
            $status = $result['status'] ?? null;
            if ($status === 'failed') {
                $this->failClaim($job, 'The audio provider could not complete this request. Reserved tokens have been returned.');

                return;
            }
            if (! in_array($status, ['processing', 'completed'], true)) {
                throw new AiProxyException('The audio provider returned an invalid status.', 502);
            }
            if ($status === 'completed') {
                $urls = $result['result_urls'] ?? null;
                if (! is_array($urls) || $urls === [] || ! array_is_list($urls)) {
                    throw new AiProxyException('The audio provider returned an invalid result collection.', 502);
                }
                $saving = DB::transaction(function () use ($job): ?AudioJob {
                    $locked = AudioJob::query()->lockForUpdate()->find($job->id);
                    if (! $this->ownsClaim($locked, $job)) {
                        return null;
                    }
                    $locked->update(['stage' => 'saving', 'processing_started_at' => now(), 'next_poll_at' => null]);

                    return $locked;
                });
                if ($saving) {
                    $job = $saving;
                    $this->complete($job, $urls);
                }

                return;
            }
        } catch (AiProxyException $exception) {
            if ($job->stage === 'saving') {
                $this->failClaim($job, 'The audio result could not be saved. Reserved tokens have been returned.');

                return;
            }
            if ($exception->responseStatus() !== 503) {
                $this->failClaim($job, 'The audio status could not be verified. Reserved tokens have been returned.');

                return;
            }
        } catch (Throwable) {
            $this->failClaim($job, 'Audio generation could not be completed. Reserved tokens have been returned.');

            return;
        }
        $reschedule = DB::transaction(function () use ($job): bool {
            $locked = AudioJob::query()->lockForUpdate()->find($job->id);
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

    public function reconcile(): array
    {
        $counts = ['queued' => 0, 'polls' => 0, 'failed' => 0];
        AudioJob::query()->whereIn('status', ['pending', 'processing'])->orderBy('id')->chunkById(100, function ($jobs) use (&$counts): void {
            foreach ($jobs as $candidate) {
                $action = DB::transaction(function () use ($candidate): ?string {
                    $job = AudioJob::query()->lockForUpdate()->find($candidate->id);
                    if (! $job || ! in_array($job->status, ['pending', 'processing'], true)) {
                        return null;
                    }
                    if ($job->status === 'pending' && $job->stage === 'queued'
                        && $job->submitted_at === null && $job->upstream_job_id === null
                        && ($job->next_poll_at === null || $job->next_poll_at->isPast())) {
                        $job->update(['next_poll_at' => now()->addMinute()]);

                        return 'queued';
                    }
                    $activity = $job->processing_started_at ?? $job->updated_at;
                    if ($job->stage === 'submitting' && $job->submitted_at === null && $job->upstream_job_id === null
                        && $activity->lt(now()->subSeconds(self::SUBMISSION_LEASE_SECONDS))) {
                        $this->terminate($job, 'failed', 'The audio request was interrupted. Reserved tokens have been returned. It was not automatically resubmitted.');

                        return 'failed';
                    }
                    if ($job->stage === 'saving' && $activity->lt(now()->subSeconds(self::POLL_LEASE_SECONDS))) {
                        $this->terminate($job, 'failed', 'The audio result could not be saved. Reserved tokens have been returned.');

                        return 'failed';
                    }
                    if ($job->stage === 'rendering' && $job->upstream_job_id !== null
                        && ($job->processing_started_at === null || $job->processing_started_at->lt(now()->subSeconds(self::POLL_LEASE_SECONDS)))) {
                        if (($job->submitted_at ?? $job->created_at)->lt(now()->subMinutes(30))) {
                            $this->terminate($job, 'failed', 'Audio generation timed out. Reserved tokens have been returned.');

                            return 'failed';
                        }
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
                    Storage::disk('local')->deleteDirectory(GeneratedAudioStore::directory($candidate->job_id));
                }
            }
        });

        return $counts;
    }

    public function cancel(User $user, string $jobId): AudioJob
    {
        return DB::transaction(function () use ($user, $jobId): AudioJob {
            $job = AudioJob::query()->where('job_id', $jobId)->lockForUpdate()->firstOrFail();
            abort_unless($user->isAdmin() || $job->user_id === $user->id, 404);
            if (self::cancellation($job)['can_cancel']) {
                $this->terminate($job, 'cancelled', 'Audio generation was cancelled. Reserved tokens have been returned.');
            }

            return $job;
        });
    }

    public static function cancellation(AudioJob $job): array
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
                'job_completed' => 'This audio has already completed and cannot be cancelled.',
                'already_cancelled' => 'This audio request is already cancelled.',
                'job_failed' => 'This audio has already failed and cannot be cancelled.',
                'submission_started' => 'Audio submission has started. It cannot be cancelled and this action does not refund tokens.',
                'cancellation_unavailable' => 'Cancellation is unavailable for this audio. Refresh its status before taking another action.',
                default => null,
            },
        ];
    }

    public function failSubmission(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $job = AudioJob::query()->lockForUpdate()->find($id);
            if (! $job || $job->submitted_at !== null || $job->upstream_job_id !== null
                || $job->status !== 'pending' || $job->stage !== 'queued') {
                return;
            }
            $this->terminate($job, 'failed', 'The audio request was interrupted. Reserved tokens have been returned. It was not automatically resubmitted.');
        });
    }

    public static function payload(AudioJob $job): array
    {
        return [
            'job_id' => $job->job_id, 'model' => $job->model, 'mode' => $job->mode,
            'prompt' => $job->prompt, 'voice' => $job->voice, 'speed' => $job->speed,
            'duration' => $job->duration, 'tempo' => $job->tempo, 'status' => $job->status, 'stage' => $job->stage,
            'outputs' => $job->status === 'completed' ? array_map(
                static fn (array $output, int $index): array => [
                    'url' => '/api/audio/'.$job->job_id.'/assets/'.$index,
                    'mime_type' => $output['mime_type'], 'size_bytes' => (int) $output['size_bytes'],
                ], $job->outputs ?? [], array_keys($job->outputs ?? []),
            ) : [],
            'error' => $job->error_message,
            'billing_mode' => $job->billing_mode, 'billing_status' => $job->billing_status,
            'tokens_reserved' => $job->tokens_reserved,
            ...self::cancellation($job),
            'created_at' => $job->created_at?->toISOString(), 'updated_at' => $job->updated_at?->toISOString(),
            'completed_at' => $job->completed_at?->toISOString(),
        ];
    }

    private function complete(AudioJob $job, array $urls): void
    {
        $outputs = $this->audio->persist($job, $urls);
        $retained = DB::transaction(function () use ($job, $outputs): bool {
            $locked = AudioJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return $locked?->status === 'completed' && $locked->outputs === $outputs;
            }
            $this->tokens->settle($locked->user_id, ['reference_id' => $locked->billing_reference_id], [
                'service' => 'audio', 'model' => $locked->model, 'mode' => $locked->mode,
            ]);
            $locked->update([
                'status' => 'completed', 'stage' => 'completed', 'outputs' => $outputs,
                'billing_status' => 'settled', 'completed_at' => now(), 'next_poll_at' => null,
                'processing_started_at' => null, 'processing_token' => null,
            ]);

            return true;
        });
        if (! $retained) {
            Storage::disk('local')->deleteDirectory(GeneratedAudioStore::directory($job->job_id));
        }
    }

    private function failClaim(AudioJob $claim, string $message): void
    {
        $terminated = DB::transaction(function () use ($claim, $message): bool {
            $job = AudioJob::query()->lockForUpdate()->find($claim->id);
            if (! $this->ownsClaim($job, $claim)) {
                return false;
            }
            $this->terminate($job, 'failed', $message);

            return true;
        });
        if ($terminated) {
            Storage::disk('local')->deleteDirectory(GeneratedAudioStore::directory($claim->job_id));
        }
    }

    private function terminate(AudioJob $job, string $stage, string $message): void
    {
        if (! in_array($job->status, ['pending', 'processing'], true)) {
            return;
        }
        $this->tokens->release($job->user_id, ['reference_id' => $job->billing_reference_id],
            $stage === 'cancelled' ? 'Audio generation cancelled' : 'Audio generation did not complete');
        $job->update([
            'status' => 'failed', 'stage' => $stage, 'error_message' => $message,
            'billing_status' => 'released', 'next_poll_at' => null, 'processing_started_at' => null,
            'processing_token' => null, 'completed_at' => now(),
        ]);
    }

    private function ownsClaim(?AudioJob $current, AudioJob $claim): bool
    {
        return $current !== null && $current->status === 'processing' && $current->stage === $claim->stage
            && is_string($current->processing_token) && is_string($claim->processing_token)
            && hash_equals($current->processing_token, $claim->processing_token);
    }

    private function provider(AudioJob $job): AiProviderProfile
    {
        $provider = AiProviderProfile::query()->find($job->provider_id);
        if (! $provider || ! $provider->is_enabled || ! in_array($provider->protocol, ['fal', 'kinovi'], true)
            || ! hash_equals((string) $job->connection_fingerprint, self::fingerprint($provider))) {
            throw new AiProxyException('The audio connection changed. No request was sent to a different provider.', 503);
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
            ProcessAudioJob::dispatch($id)->onConnection('media')->onQueue('media')->afterCommit();

            return true;
        } catch (Throwable) {
            // The durable queued row is recovered by reconciliation, not a second admission.
            return false;
        }
    }

    private function queuePoll(int $id): bool
    {
        try {
            PollAudioJob::dispatch($id)->onConnection('media')->onQueue('media')
                ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS))->afterCommit();

            return true;
        } catch (Throwable) {
            // Reconciliation resumes this task ID; it never repeats the paid POST.
            return false;
        }
    }
}
