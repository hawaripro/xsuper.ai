<?php

namespace App\Services;

use App\Jobs\ProcessMediaToolJob;
use App\Models\MediaToolJob;
use App\Models\MediaToolSetting;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Throwable;

final class MediaToolService
{
    public function __construct(private readonly MediaTokenBillingService $billing) {}

    public const MIME_TYPES = [
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav', 'flac' => 'audio/flac', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
    ];

    public const QUALITIES = ['best', '1080', '720', '480', '360'];

    public const RESOLUTIONS = ['source', '1080', '720', '480', '360'];

    public const ENCODING = ['high', 'balanced', 'small'];

    public const AUDIO_BITRATES = [128, 192, 320];

    public const GIF_MAX_SECONDS = 15;

    private const ERRORS = [
        'source' => 'This source cannot be downloaded safely. Use a public, unencrypted single-item HTTP(S) source.',
        'invalid_media' => 'The file is not a supported, self-contained media file.',
        'incompatible' => 'The source does not contain the audio or visual stream required by this output format.',
        'input_limit' => 'The source exceeds the 128 MiB input limit.',
        'output_limit' => 'The result exceeds the 256 MiB output limit.',
        'storage_full' => 'Library storage is full. Download and delete items or upgrade storage before saving.',
        'duration_limit' => 'Media must have a known duration of no more than 10 minutes.',
        'dimensions' => 'Media dimensions exceed the supported limit of 4096 pixels per side.',
        'timeout' => 'Processing exceeded the allowed runtime. No output was retained.',
        'runtime' => 'The private media processing runtime is unavailable.',
        'failed' => 'Media processing could not be completed. Try another supported source or format.',
    ];

    public function capabilities(): array
    {
        $paths = $this->runtimePaths();
        $available = ['download' => false, 'convert' => false, 'rembg' => false];
        $diagnosis = [];
        if (! config('media_tools.enabled')) {
            $diagnosis[] = 'media_tools.enabled=false';
        }
        foreach (['python', 'ffmpeg', 'ffprobe'] as $binary) {
            if (! $paths[$binary]) {
                $diagnosis[] = 'missing MEDIA_'.strtoupper($binary).'_PATH';
            }
        }
        // Background removal needs only python + the rembg library; download/convert additionally
        // need ffmpeg/ffprobe. Probe whenever python is present so a missing ffmpeg never hides
        // rembg. The rembg import is slow on a cold numba cache, so its probe gets a wider timeout.
        if (config('media_tools.enabled') && $paths['python']) {
            try {
                [$available, $probe] = Cache::remember('media-tools:runtime:v2:'.hash('sha256', json_encode($paths)), 60, function () use ($paths): array {
                    $mediaReady = (bool) ($paths['ffmpeg'] ?? null) && (bool) ($paths['ffprobe'] ?? null);
                    $process = null;
                    $state = null;
                    if ($mediaReady) {
                        $process = new Process([$paths['python'], '-I', '-B', '-u', base_path('scripts/media/download.py'), 'check'], base_path('scripts/media'), $this->environment(), json_encode($paths, JSON_THROW_ON_ERROR), 20);
                        $process->run();
                        $state = json_decode($process->getOutput(), true);
                    }

                    // The probe needs the model directory too: rembg's numba cache cannot be written
                    // under php-fpm's HOME, so a bare check failed for www-data while succeeding on the CLI.
                    $rembgManifest = json_encode([
                        'model_dir' => is_string(config('media_tools.rembg_model_dir')) ? config('media_tools.rembg_model_dir') : null,
                    ], JSON_THROW_ON_ERROR);
                    $rembg = new Process([$paths['python'], '-I', '-B', '-u', base_path('scripts/media/rembg_tool.py'), 'check'], base_path('scripts/media'), $this->environment(), $rembgManifest, 45);
                    $rembg->run();
                    $rembgState = json_decode($rembg->getOutput(), true);

                    return [[
                        'download' => $process !== null && $process->isSuccessful() && ($state['download'] ?? false) === true,
                        'convert' => $process !== null && $process->isSuccessful() && ($state['convert'] ?? false) === true,
                        'rembg' => $rembg->isSuccessful() && ($rembgState['rembg'] ?? false) === true,
                    ], [
                        'reasons' => is_array($state) ? ($state['reasons'] ?? null) : null,
                        'probe_exit' => $process?->getExitCode(),
                        'rembg_exit' => $rembg->getExitCode(),
                    ]];
                });
                if (! is_array($available) || ! array_key_exists('convert', $available)) {
                    // A cached entry written by an older shape would otherwise
                    // destructure into nulls and silently disable every tool.
                    Cache::forget('media-tools:runtime:v2:'.hash('sha256', json_encode($paths)));
                    $available = ['download' => false, 'convert' => false, 'rembg' => false];
                    $diagnosis[] = 'cache-shape';
                } elseif (in_array(false, $available, true)) {
                    $diagnosis[] = 'probe: '.json_encode($probe);
                }
            } catch (Throwable $error) {
                // Diagnostics go to the log only — never to the HTTP response,
                // which must not leak command lines or URL tokens.
                $diagnosis[] = 'exception';
                Log::warning('Media tool capability probe failed', ['error' => $error->getMessage()]);
            }
        }
        if ($diagnosis !== []) {
            // Previously an empty catch swallowed this, so an unavailable tool
            // was indistinguishable from a misconfigured one.
            Log::info('Media tools unavailable', ['reasons' => $diagnosis]);
        }

        return [
            'available' => $available,
            'limits' => ['max_upload_bytes' => $this->inputLimit(), 'max_output_bytes' => $this->outputLimit(), 'max_duration_seconds' => min(600, (int) config('media_tools.max_duration_seconds', 600))],
            'download_formats' => $available['download'] ? ['mp4', 'webm', 'mp3'] : [],
            'convert_formats' => $available['convert'] ? array_keys(self::MIME_TYPES) : [],
            'rembg_formats' => ($available['rembg'] ?? false) ? ['png'] : [],
            'rembg_tokens' => max(0, (int) config('media_tools.rembg_tokens', 15)),
            'message' => ! $available['convert'] ? self::ERRORS['runtime'] : (! $available['download'] ? 'Conversion is available, but the private downloader or sandboxed JavaScript runtime is unavailable.' : 'Downloads support public, unencrypted single items with native HTTP(S) streams. Login, live streams and playlists are not supported. Image conversion saves the first frame.'),
        ];
    }

    public function download(User $user, array $input): MediaToolJob
    {
        $url = $this->validateUrl($input['url']);
        $options = [];
        if (in_array($input['format'], ['mp4', 'webm'], true) && ($input['quality'] ?? 'best') !== 'best') {
            $options['quality'] = (string) $input['quality'];
        }

        return $this->admit($user, 'download', $input['format'], $url, null, $options);
    }

    public function convert(User $user, UploadedFile $file, string $format, array $input = []): MediaToolJob
    {
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > $this->inputLimit()) {
            throw ValidationException::withMessages(['file' => self::ERRORS['input_limit']]);
        }

        return $this->admit($user, 'convert', $format, null, $file, $this->conversionOptions($format, $input));
    }

    /**
     * Remove an image background (rembg). Members reserve tokens; admins are free.
     */
    public function removeBackground(User $user, UploadedFile $file): MediaToolJob
    {
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > $this->inputLimit()) {
            throw ValidationException::withMessages(['file' => self::ERRORS['input_limit']]);
        }
        if (! in_array('png', $this->capabilities()['rembg_formats'], true)) {
            abort(503, self::ERRORS['runtime']);
        }

        return $this->admit($user, 'rembg', 'png', null, $file, []);
    }

    /**
     * Inspect a public URL without downloading media: title, duration, thumbnail,
     * available heights and stream availability, as sanitized by the private runner.
     */
    public function inspect(User $user, string $url): array
    {
        $url = $this->validateUrl($url);
        $capabilities = $this->capabilities();
        if (! ($capabilities['available']['download'] ?? false)) {
            abort(503, self::ERRORS['runtime']);
        }
        $paths = $this->runtimePaths();
        // YouTube blocks anonymous datacenter probes; hand the inspector the same burner cookies
        // the download path uses so title/format inspection clears the bot check too.
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $useCookies = preg_match('/(^|\.)(youtube\.com|youtu\.be)$/i', $host)
            && ($cookies = $this->youtubeCookies()) !== null;
        $manifest = [...$paths, 'kind' => 'inspect', 'url' => $url, 'timeout' => 40,
            'input_limit' => $this->inputLimit(), 'output_limit' => $this->outputLimit(),
            'cookies' => $useCookies ? 'cookies.txt' : null,
            'duration_limit' => min(600, (int) config('media_tools.max_duration_seconds', 600)),
            'dimension_limit' => min(4096, (int) config('media_tools.max_dimension', 4096)),
            'pixel_limit' => min(16777216, (int) config('media_tools.max_pixels', 16777216)),
        ];
        // The runner only accepts `<uuid>/work` as its cwd; the scratch tree is removed afterwards.
        $scratch = $this->directory((string) Str::uuid());
        if (! mkdir($scratch.'/work', 0700, true)) {
            abort(503, self::ERRORS['runtime']);
        }
        if ($useCookies) {
            file_put_contents($scratch.'/work/cookies.txt', $cookies, LOCK_EX);
            @chmod($scratch.'/work/cookies.txt', 0600);
        }
        $process = null;
        try {
            $process = new Process([$paths['python'], '-I', '-B', '-u', base_path('scripts/media/download.py'), 'inspect'], $scratch.'/work', $this->environment($scratch.'/work'), json_encode($manifest, JSON_THROW_ON_ERROR), 45);
            $process->run();
            $result = null;
            $error = 'source';
            foreach (explode("\n", substr($process->getOutput(), 0, 65536)) as $line) {
                $event = json_decode($line, true);
                if (($event['event'] ?? null) === 'result') {
                    $result = $event;
                } elseif (($event['event'] ?? null) === 'error') {
                    $error = isset(self::ERRORS[$event['code'] ?? '']) ? $event['code'] : 'source';
                }
            }
        } catch (Throwable) {
            $result = null;
            $error = 'timeout';
        } finally {
            $this->remove($scratch);
        }
        if (! is_array($result) || ! $process?->isSuccessful()) {
            throw ValidationException::withMessages(['url' => self::ERRORS[$error] ?? self::ERRORS['source']]);
        }
        $heights = array_values(array_filter(array_map('intval', (array) ($result['heights'] ?? [])), fn (int $height): bool => $height > 0 && $height <= 4320));
        rsort($heights);
        $thumbnail = is_string($result['thumbnail'] ?? null) && preg_match('#^https://[^\s"\'<>]{1,2000}$#', $result['thumbnail']) ? $result['thumbnail'] : null;

        return [
            'title' => mb_substr(trim((string) ($result['title'] ?? '')), 0, 200) ?: null,
            'duration' => is_numeric($result['duration'] ?? null) ? round((float) $result['duration'], 1) : null,
            'thumbnail' => $thumbnail,
            'uploader' => mb_substr(trim((string) ($result['uploader'] ?? '')), 0, 120) ?: null,
            'source_host' => parse_url($url, PHP_URL_HOST),
            'heights' => array_slice($heights, 0, 12),
            'has_video' => (bool) ($result['has_video'] ?? false),
            'has_audio' => (bool) ($result['has_audio'] ?? false),
        ];
    }

    /**
     * Delete a finished job and its private files. Running work is cancelled first;
     * its files are removed by the worker once the cancellation lands.
     */
    public function destroy(User $user, string $jobId): void
    {
        $job = MediaToolJob::query()->where('user_id', $user->id)->where('job_id', $jobId)->firstOrFail();
        if (in_array($job->status, ['pending', 'processing'], true)) {
            $job = $this->cancel($user, $jobId);
        }
        if (in_array($job->status, ['pending', 'processing'], true)) {
            throw ValidationException::withMessages(['job' => 'The task is still finishing its cancellation. Try again in a moment.']);
        }
        $this->forget($job);
    }

    /** Delete every finished job of a kind; running tasks stay untouched. */
    public function destroyAll(User $user, string $kind): int
    {
        $removed = 0;
        MediaToolJob::query()->where('user_id', $user->id)->where('kind', $kind)
            ->whereNotIn('status', ['pending', 'processing'])->orderBy('id')->chunkById(50, function ($jobs) use (&$removed): void {
                foreach ($jobs as $job) {
                    $this->forget($job);
                    $removed++;
                }
            });

        return $removed;
    }

    private function forget(MediaToolJob $job): void
    {
        DB::transaction(function () use ($job): void {
            Notification::query()->where('user_id', $job->user_id)->where('kind', 'media')
                ->where('metadata->job_id', $job->job_id)->delete();
            $job->delete();
        });
        if (($lock = $this->lock($job->job_id)) !== null) {
            $this->cleanup($job->job_id);
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($this->directory($job->job_id).'/work.lock');
            @rmdir($this->directory($job->job_id));
        }
    }

    private function conversionOptions(string $format, array $input): array
    {
        $options = [];
        $video = in_array($format, ['mp4', 'webm'], true);
        if ($video && ($input['resolution'] ?? 'source') !== 'source') {
            $options['resolution'] = (string) $input['resolution'];
        }
        if ($video && ($input['encoding'] ?? 'balanced') !== 'balanced') {
            $options['encoding'] = (string) $input['encoding'];
        }
        if ($format === 'mp3' && (int) ($input['audio_bitrate'] ?? 192) !== 192) {
            $options['audio_bitrate'] = (int) $input['audio_bitrate'];
        }
        if (! in_array($format, ['png', 'jpg', 'webp'], true)) {
            $start = isset($input['trim_start']) && $input['trim_start'] !== '' ? (float) $input['trim_start'] : null;
            $end = isset($input['trim_end']) && $input['trim_end'] !== '' ? (float) $input['trim_end'] : null;
            if ($start !== null && $end !== null && $end <= $start) {
                throw ValidationException::withMessages(['trim_end' => 'The end of the clip must come after its start.']);
            }
            if ($format === 'gif' && ($end ?? self::GIF_MAX_SECONDS + 1) - ($start ?? 0) > self::GIF_MAX_SECONDS) {
                throw ValidationException::withMessages(['trim_end' => 'GIF clips are limited to '.self::GIF_MAX_SECONDS.' seconds. Set a shorter start and end.']);
            }
            if ($start !== null && $start > 0) {
                $options['trim_start'] = round($start, 3);
            }
            if ($end !== null) {
                $options['trim_end'] = round($end, 3);
            }
        }

        return $options;
    }

    private function admit(User $user, string $kind, string $format, ?string $url, ?UploadedFile $file, array $options = []): MediaToolJob
    {
        $capabilities = $this->capabilities();
        if (! ($capabilities['available'][$kind] ?? false)) {
            abort(503, self::ERRORS['runtime']);
        }
        if (! in_array($format, $capabilities[$kind.'_formats'], true)) {
            throw ValidationException::withMessages(['format' => 'The selected output format is unavailable.']);
        }
        $jobId = (string) Str::uuid();
        $directory = $this->directory($jobId);
        $job = null;
        try {
            $job = DB::transaction(function () use ($user, $kind, $format, $url, $file, $jobId, $directory, $options): MediaToolJob {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (MediaToolJob::query()->where('user_id', $user->id)->whereIn('status', ['pending', 'processing'])->count() >= min(2, max(1, (int) config('media_tools.max_concurrent_jobs', 2)))) {
                    throw ValidationException::withMessages(['file' => 'Wait for or cancel an active media task before starting another.']);
                }
                if (! mkdir($directory.'/work', 0700, true)) {
                    throw new RuntimeException('Private workspace unavailable.');
                }
                $name = $file ? mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?: 'Uploaded media', 0, 200) : null;
                if ($file) {
                    if (! copy($file->getRealPath(), $directory.'/work/input')) {
                        throw new RuntimeException('Private input could not be stored.');
                    }
                    @chmod($directory.'/work/input', 0600);
                }

                // Paid tools (rembg) reserve member tokens; admins run free of charge.
                $paid = $kind === 'rembg' && ! $user->isAdmin();
                $unitCost = max(1, (int) config('media_tools.rembg_tokens', 15));
                $billingMode = $kind === 'rembg' ? ($user->isAdmin() ? 'admin' : 'tokens') : null;

                $job = MediaToolJob::create([
                    'user_id' => $user->id, 'job_id' => $jobId, 'kind' => $kind, 'format' => $format,
                    'options' => $options ?: null,
                    'billing_mode' => $billingMode, 'tokens_reserved' => $paid ? $unitCost : 0,
                    'source_url' => $url, 'input_name' => $name,
                    'title' => $name ?: mb_substr('Download from '.parse_url($url, PHP_URL_HOST), 0, 240),
                    'status' => 'pending', 'stage' => 'queued', 'dispatched_at' => now(),
                ]);

                if ($paid) {
                    // Throws a token-shortfall ValidationException, which rolls back the job.
                    $this->billing->reserve($user, 'image', 'rembg', 1, $jobId, $unitCost);
                }

                return $job;
            });
            ProcessMediaToolJob::dispatch($job->id)->onConnection('media')->onQueue('media')->afterCommit();
        } catch (Throwable $exception) {
            if ($job) {
                $this->finish($job->id, 'failed', self::ERRORS['runtime']);
            }
            $this->cleanup($jobId);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            abort(503, self::ERRORS['runtime']);
        }

        return $job->refresh();
    }

    public function process(int $id): void
    {
        $record = MediaToolJob::find($id);
        if (! $record || $record->status !== 'pending') {
            return;
        }
        $lock = $this->lock($record->job_id);
        if ($lock === null) {
            // No lock and no directory means the private workspace could not be
            // created (e.g. storage permissions) — a condition that never
            // resolves on its own, so fail the job instead of leaving it queued.
            // A missing lock while the directory exists means another worker owns
            // it; leave that job for the active worker.
            if (! is_dir($this->directory($record->job_id))) {
                $this->finish($id, 'failed', self::ERRORS['runtime']);
            }
            return;
        }
        $process = null;
        $job = null;
        $completed = false;
        try {
            $job = DB::transaction(function () use ($id): ?MediaToolJob {
                $job = MediaToolJob::query()->lockForUpdate()->find($id);
                if (! $job || $job->status !== 'pending' || $job->lease_token !== null) {
                    return null;
                }
                $job->update(['status' => 'processing', 'stage' => $job->kind === 'download' ? 'downloading' : 'probing', 'heartbeat_at' => now(), 'lease_token' => bin2hex(random_bytes(32))]);

                return $job;
            });
            if (! $job) {
                return;
            }
            $directory = $this->directory($job->job_id);
            $paths = $this->runtimePaths();
            if (! $paths['python'] || ($job->kind !== 'rembg' && (! $paths['ffmpeg'] || ! $paths['ffprobe'])) || ($job->kind === 'download' && ! $paths['node']) || ! is_dir($directory.'/work') || is_link($directory.'/work')) {
                throw new RuntimeException('runtime');
            }
            if (file_put_contents($directory.'/lease', $job->lease_token, LOCK_EX) === false) {
                throw new RuntimeException('runtime');
            }
            @chmod($directory.'/lease', 0600);
            // Authenticated YouTube downloads: hand yt-dlp a cookies file from the
            // burner account so datacenter IPs clear the bot check. Written into the
            // private work dir (0600) and torn down with the job.
            $host = parse_url((string) $job->source_url, PHP_URL_HOST) ?: '';
            $useCookies = $job->kind === 'download' && preg_match('/(^|\.)(youtube\.com|youtu\.be)$/i', $host)
                && ($cookies = $this->youtubeCookies()) !== null;
            if ($useCookies) {
                file_put_contents($directory.'/work/cookies.txt', $cookies, LOCK_EX);
                @chmod($directory.'/work/cookies.txt', 0600);
            }
            $timeout = min(360, max(30, (int) config('media_tools.process_timeout', 360)));
            $manifest = [...$paths, 'kind' => $job->kind, 'format' => $job->format, 'url' => $job->source_url, 'options' => $job->options ?: new \stdClass,
                'lease' => $job->lease_token, 'timeout' => $timeout - 5, 'input_limit' => $this->inputLimit(), 'output_limit' => $this->outputLimit(),
                'cookies' => $useCookies ? 'cookies.txt' : null,
                'duration_limit' => min(600, (int) config('media_tools.max_duration_seconds', 600)),
                'dimension_limit' => min(4096, (int) config('media_tools.max_dimension', 4096)),
                'pixel_limit' => min(16777216, (int) config('media_tools.max_pixels', 16777216)),
                'model_dir' => $job->kind === 'rembg' ? (is_string(config('media_tools.rembg_model_dir')) ? config('media_tools.rembg_model_dir') : null) : null,
            ];
            $script = $job->kind === 'rembg' ? 'scripts/media/rembg_tool.py' : 'scripts/media/download.py';
            $process = new Process([$paths['python'], '-I', '-B', '-u', base_path($script), 'run'], $directory.'/work', $this->environment($directory.'/work'), json_encode($manifest, JSON_THROW_ON_ERROR), $timeout);
            $process->start();
            $buffer = '';
            $result = null;
            $error = 'failed';
            $state = ['stage' => $job->stage, 'progress' => null];
            $heartbeat = 0.0;
            do {
                $running = $process->isRunning();
                $process->checkTimeout();
                $buffer .= $process->getIncrementalOutput();
                $process->clearOutput();
                $process->clearErrorOutput();
                if (strlen($buffer) > 65536) {
                    throw new RuntimeException('failed');
                }
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $event = json_decode(substr($buffer, 0, $newline), true);
                    $buffer = substr($buffer, $newline + 1);
                    if (($event['event'] ?? null) === 'progress' && in_array($event['stage'] ?? null, ['downloading', 'probing', 'converting', 'saving'], true)) {
                        $state = ['stage' => $event['stage'], 'progress' => isset($event['progress']) && is_numeric($event['progress']) ? round(max(0, min(99, (float) $event['progress'])), 1) : null];
                    } elseif (($event['event'] ?? null) === 'result') {
                        $result = $event;
                    } elseif (($event['event'] ?? null) === 'error') {
                        $error = isset(self::ERRORS[$event['code'] ?? '']) ? $event['code'] : 'failed';
                    }
                }
                if (microtime(true) - $heartbeat >= 1) {
                    $current = MediaToolJob::find($id);
                    if (! $current || $current->status !== 'processing' || $current->lease_token !== $job->lease_token || $current->cancel_requested_at !== null) {
                        file_put_contents($directory.'/cancel', 'cancel', LOCK_EX);
                        $process->stop(2);
                        $this->finish($id, 'cancelled', null, $job->lease_token);

                        return;
                    }
                    touch($directory.'/lease');
                    MediaToolJob::query()->whereKey($id)->where('lease_token', $job->lease_token)->whereNull('cancel_requested_at')->update([...$state, 'heartbeat_at' => now()]);
                    $heartbeat = microtime(true);
                }
                if ($running) {
                    usleep(100000);
                }
            } while ($running);
            if (! $process->isSuccessful() || ! is_array($result)) {
                throw new RuntimeException($error);
            }
            $output = $directory.'/work/result.'.$job->format;
            clearstatcache(true, $output);
            if (! is_file($output) || is_link($output) || filesize($output) < 1 || filesize($output) > $this->outputLimit()
                || ($result['mime_type'] ?? null) !== self::MIME_TYPES[$job->format] || ($result['size_bytes'] ?? null) !== filesize($output)) {
                throw new RuntimeException('failed');
            }
            $completed = DB::transaction(function () use ($job, $directory, $output, $result): bool {
                $owner = User::query()->whereKey($job->user_id)->lockForUpdate()->firstOrFail();
                $current = MediaToolJob::query()->lockForUpdate()->find($job->id);
                if (! $current || $current->status !== 'processing' || $current->lease_token !== $job->lease_token || $current->cancel_requested_at !== null) {
                    return false;
                }
                app(StorageQuotaService::class)->assertCanStore($owner, filesize($output));
                if (! rename($output, $directory.'/result.'.$job->format)) {
                    throw new RuntimeException('failed');
                }
                @chmod($directory.'/result.'.$job->format, 0600);
                $current->update(['status' => 'completed', 'stage' => 'completed', 'progress' => 100, 'mime_type' => self::MIME_TYPES[$job->format],
                    'size_bytes' => $result['size_bytes'], 'duration' => $result['duration'] ?? null, 'completed_at' => now(), 'lease_token' => null]);

                // Settle the member's token reservation now that the result is retained.
                if ($current->billing_mode === 'tokens') {
                    $this->billing->settle($current->user_id, ['reference_id' => $current->job_id], ['kind' => $current->kind, 'size_bytes' => $result['size_bytes']]);
                }

                return true;
            });
            if (! $completed) {
                $this->finish($id, 'cancelled', null, $job->lease_token);
            }
        } catch (Throwable $exception) {
            if ($process?->isRunning()) {
                $process->stop(2);
            }
            if ($job) {
                $code = $exception instanceof HttpException && $exception->getStatusCode() === 413
                    ? 'storage_full'
                    : ($exception instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException ? 'timeout' : $exception->getMessage());
                $this->finish($id, 'failed', self::ERRORS[$code] ?? self::ERRORS['failed'], $job->lease_token);
            } else {
                $this->finish($id, 'failed', self::ERRORS['runtime']);
            }
        } finally {
            if ($process?->isRunning()) {
                $process->stop(2);
            }
            // Keep the lock inode: deleting an open lock would allow a second owner.
            if ($job !== null || in_array(MediaToolJob::find($id)?->status, ['failed', 'cancelled'], true)) {
                $this->cleanup($record->job_id, $completed);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function cancel(User $user, string $jobId): MediaToolJob
    {
        $job = DB::transaction(function () use ($user, $jobId): MediaToolJob {
            $job = MediaToolJob::query()->where('user_id', $user->id)->where('job_id', $jobId)->lockForUpdate()->firstOrFail();
            if ($job->status === 'pending') {
                $job->update(['status' => 'cancelled', 'stage' => 'cancelled', 'completed_at' => now(), 'cancel_requested_at' => now()]);
                if ($job->billing_mode === 'tokens') {
                    $this->billing->release($job->user_id, ['reference_id' => $job->job_id], 'Media task cancelled');
                }
            } elseif ($job->status === 'processing' && $job->cancel_requested_at === null) {
                $job->update(['stage' => 'cancelling', 'cancel_requested_at' => now()]);
            }

            return $job;
        });
        if ($job->cancel_requested_at !== null) {
            $directory = $this->directory($job->job_id);
            if (is_dir($directory) && ! is_link($directory)) {
                file_put_contents($directory.'/cancel', 'cancel', LOCK_EX);
            }
            if ($job->status === 'cancelled' && ($lock = $this->lock($job->job_id)) !== null) {
                $this->cleanup($job->job_id);
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $job->refresh();
    }

    public function interrupted(int $id): void
    {
        $job = MediaToolJob::find($id);
        if (! $job || $job->status !== 'pending') {
            // Running work owns its process handle; its lease expires without PID-based killing.
            return;
        }
        // A pending job never started its external process, so there is no live
        // worker to protect. Finalise it as failed even when the private
        // workspace cannot be locked (e.g. the directory could not be created);
        // gating this on the lock left such jobs stuck at "queued" forever.
        $lock = $this->lock($job->job_id);
        try {
            if ($this->finish($id, 'failed', 'The media task could not start. It was not automatically retried.')) {
                $this->cleanup($job->job_id);
            }
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function reconcile(): array
    {
        $counts = ['failed' => 0, 'cancelled' => 0];
        MediaToolJob::query()->whereIn('status', ['pending', 'processing'])->orderBy('id')->chunkById(100, function ($jobs) use (&$counts): void {
            foreach ($jobs as $job) {
                $pending = $job->status === 'pending';
                $stale = $pending ? $job->created_at->lt(now()->subMinutes(15)) : ($job->heartbeat_at === null || $job->heartbeat_at->lt(now()->subSeconds(30)));
                if (! $stale) {
                    continue;
                }
                // A processing job may still own a live process, so only reclaim
                // it when the lock is free. A pending job never started, so
                // reclaim it even if its workspace cannot be locked.
                $lock = $this->lock($job->job_id);
                if ($lock === null && ! $pending) {
                    continue;
                }
                try {
                    $status = $job->cancel_requested_at !== null ? 'cancelled' : 'failed';
                    if ($this->finish($job->id, $status, $status === 'failed' ? 'The media task was interrupted. It was not automatically retried.' : null, $job->lease_token)) {
                        $this->cleanup($job->job_id);
                        $counts[$status]++;
                    }
                } finally {
                    if ($lock !== null) {
                        flock($lock, LOCK_UN);
                        fclose($lock);
                    }
                }
            }
        });

        return $counts;
    }

    private function finish(int $id, string $status, ?string $error, ?string $lease = null): bool
    {
        return DB::transaction(function () use ($id, $status, $error, $lease): bool {
            $job = MediaToolJob::query()->lockForUpdate()->find($id);
            if (! $job || ! in_array($job->status, ['pending', 'processing'], true) || ($lease !== null && $job->lease_token !== $lease)) {
                return false;
            }
            if ($job->cancel_requested_at !== null) {
                $status = 'cancelled';
                $error = null;
            }
            $job->update(['status' => $status, 'stage' => $status, 'error_message' => $error, 'completed_at' => now(), 'lease_token' => null]);

            // Refund the member's reserved tokens for a paid job that never produced a result.
            if ($job->billing_mode === 'tokens') {
                $this->billing->release($job->user_id, ['reference_id' => $job->job_id], 'Media task '.$status);
            }

            return true;
        });
    }

    public function payload(MediaToolJob $job): array
    {
        $source = $job->source_url;

        return [
            'job_id' => $job->job_id, 'kind' => $job->kind, 'status' => $job->status, 'stage' => $job->stage,
            'progress' => $job->progress, 'title' => $job->title,
            'billing_mode' => $job->billing_mode, 'tokens_reserved' => (int) $job->tokens_reserved,
            'input_name' => $job->input_name, 'format' => $job->format, 'options' => $job->options ?: new \stdClass,
            'result_url' => $job->status === 'completed' ? '/api/media-tools/'.$job->job_id.'/asset' : null,
            'mime_type' => $job->mime_type, 'size_bytes' => $job->size_bytes, 'duration' => $job->duration,
            'error' => $job->error_message, 'can_cancel' => in_array($job->status, ['pending', 'processing'], true) && $job->cancel_requested_at === null,
            'created_at' => $job->created_at?->toISOString(), 'updated_at' => $job->updated_at?->toISOString(), 'completed_at' => $job->completed_at?->toISOString(),
        ];
    }

    public function assetPath(MediaToolJob $job): string
    {
        abort_unless(isset(self::MIME_TYPES[$job->format]) && $job->status === 'completed', 404);
        $directory = $this->directory($job->job_id);
        $path = $directory.'/result.'.$job->format;
        abort_unless(is_file($path) && ! is_link($path) && realpath(dirname($path)) === realpath($directory), 404);

        return $path;
    }

    private function directory(string $jobId): string
    {
        if (! Str::isUuid($jobId)) {
            throw new RuntimeException('Invalid private workspace.');
        }
        $base = Storage::disk('local')->path('media-tools');
        if (is_link($base) || is_link($base.'/'.$jobId)) {
            throw new RuntimeException('Invalid private workspace.');
        }

        return $base.'/'.$jobId;
    }

    private function lock(string $jobId)
    {
        $directory = $this->directory($jobId);
        // A failed mkdir (e.g. the storage path is not writable by this worker)
        // must degrade to a null lock, not a thrown warning: the caller records
        // the job as failed instead of leaving it stuck at "queued".
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return null;
        }
        if (is_link($directory.'/work.lock')) {
            return null;
        }
        $lock = fopen($directory.'/work.lock', 'c');
        if ($lock === false) {
            return null;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    private function cleanup(string $jobId, bool $keepResult = false): void
    {
        $directory = $this->directory($jobId);
        if (! is_dir($directory)) {
            return;
        }
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || $entry->getFilename() === 'work.lock' || ($keepResult && preg_match('/^result\.(mp4|webm|mp3|wav|flac|png|jpg|webp|gif)$/D', $entry->getFilename()))) {
                continue;
            }
            $this->remove($entry->getPathname());
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || ! is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (new \DirectoryIterator($path) as $entry) {
            if (! $entry->isDot()) {
                $this->remove($entry->getPathname());
            }
        }
        @rmdir($path);
    }

    private function validateUrl(string $url): string
    {
        $parts = parse_url($url);
        if (strlen($url) > 2048 || preg_match('/[^\x21-\x7e]|\\\\/', $url) || ! is_array($parts)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || ! in_array($parts['port'] ?? 443, [80, 443], true)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['url' => 'Enter a public HTTP(S) URL without credentials or a custom port.']);
        }

        return $url;
    }

    public function youtubeCookies(): ?string
    {
        $cookies = MediaToolSetting::current()->youtube_cookies;

        return is_string($cookies) && trim($cookies) !== '' ? $cookies : null;
    }

    private function runtimePaths(): array
    {
        $paths = [];
        foreach (['python', 'ffmpeg', 'ffprobe', 'node'] as $name) {
            $path = config('media_tools.'.$name);
            $paths[$name] = is_string($path) && is_file($path) ? realpath($path) ?: null : null;
        }

        return $paths;
    }

    private function environment(?string $directory = null): array
    {
        $environment = [];
        foreach (array_keys(array_merge(getenv() ?: [], $_ENV, $_SERVER)) as $key) {
            if (is_string($key)) {
                $environment[$key] = false;
            }
        }
        foreach (['SystemRoot', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP'] as $key) {
            if (($value = getenv($key)) !== false) {
                $environment[$key] = $value;
            }
        }
        $directory ??= storage_path('app/private');

        return [...$environment, 'HOME' => $directory, 'USERPROFILE' => $directory, 'APPDATA' => $directory,
            'PATH' => '', 'YTDLP_NO_PLUGINS' => '1', 'LC_ALL' => 'C.UTF-8'];
    }

    private function inputLimit(): int
    {
        return min(128 * 1024 * 1024, max(1, (int) config('media_tools.max_upload_bytes', 128 * 1024 * 1024)));
    }

    private function outputLimit(): int
    {
        return min(256 * 1024 * 1024, max(1, (int) config('media_tools.max_output_bytes', 256 * 1024 * 1024)));
    }
}
