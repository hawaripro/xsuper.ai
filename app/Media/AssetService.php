<?php

namespace App\Media;

use App\Media\Enums\InputRole;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\StorageQuotaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Controlled media asset service. Validates size + allowed type + content signature
 * (never trusts the extension/Content-Type), stores privately, checks ownership before
 * issuing access, and mints cookie-independent time-limited signed URLs for providers
 * that must fetch a reference. Results keep their existing private stores; this backs
 * uploaded references and the owner-scoped registry.
 */
final class AssetService
{
    private const MAX_BYTES = ['image' => 15_728_640, 'audio' => 31_457_280, 'video' => 104_857_600];

    public function store(User $user, UploadedFile $file, InputRole $role): MediaAsset
    {
        return $this->storeMany($user, [$file], $role)[0];
    }

    /** Atomic multi-upload; quota admission is serialized with every other owned writer. */
    public function storeMany(User $user, array $files, InputRole $role): array
    {
        if ($files === [] || count($files) > 20) {
            throw new InvalidArgumentException('Upload between one and twenty files at a time.');
        }
        $files = array_values($files);
        $policy = app(AssetUploadPolicy::class);
        $inspected = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw new InvalidArgumentException('The uploaded file is invalid.');
            }
            $inspected[] = $policy->inspect($file, $role->value);
        }
        $written = [];
        try {
            return DB::transaction(function () use ($user, $files, $role, $inspected, &$written): array {
                $owner = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                app(StorageQuotaService::class)->assertCanStore($owner, array_sum(array_column($inspected, 'size')));
                $result = [];
                foreach ($files as $index => $file) {
                    $info = $inspected[$index];
                    $id = (string) Str::uuid();
                    $path = "media-assets/{$id}.".$info['extension'];
                    $stream = fopen($file->getRealPath(), 'rb');
                    if ($stream === false) {
                        throw new \RuntimeException('The uploaded file could not be read.');
                    }
                    $written[] = $path;
                    try {
                        if (! Storage::disk('local')->put($path, $stream, ['visibility' => 'private'])) {
                            throw new \RuntimeException('The uploaded file could not be stored.');
                        }
                    } finally {
                        fclose($stream);
                    }
                    $asset = new MediaAsset([
                        'user_id' => $owner->id, 'media_type' => $info['kind'], 'role' => $role->value,
                        'storage_disk' => 'local', 'storage_path' => $path, 'size_bytes' => $info['size'],
                        'mime' => $info['mime'], 'original_name' => $info['original_name'],
                        'signature_ok' => true, 'retention_status' => 'active', 'metadata' => $info['metadata'],
                    ]);
                    $asset->id = $id;
                    $asset->save();
                    $result[] = $asset;
                }

                return $result;
            });
        } catch (Throwable $exception) {
            foreach ($written as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function policy(): array
    {
        return app(AssetUploadPolicy::class)->policy();
    }

    public function present(MediaAsset $asset): array
    {
        $previewable = in_array($asset->media_type, ['image', 'audio', 'video'], true)
            && ($asset->metadata['previewable'] ?? true);
        $url = '/api/media/assets/'.$asset->id;

        return [
            'id' => $asset->id, 'media_type' => $asset->media_type, 'kind' => $asset->media_type,
            'role' => $asset->role, 'original_name' => $this->filename($asset),
            'size_bytes' => $asset->size_bytes, 'mime' => $asset->mime, 'previewable' => $previewable,
            'preview_url' => $previewable ? $url : null, 'download_url' => $url.'?download=1',
            'created_at' => $asset->created_at?->toISOString(), 'policy' => $this->policy()['roles'][$asset->role] ?? null,
        ];
    }

    public function assertUsable(MediaAsset $asset): void
    {
        if (! $asset->signature_ok || $asset->retention_status !== 'active'
            || ($asset->expires_at !== null && $asset->expires_at->isPast())
            || ! Storage::disk($asset->storage_disk)->exists($asset->storage_path)) {
            throw new InvalidArgumentException('This reference is no longer available. Upload it again.');
        }
    }

    public function assertSourceConstraints(MediaAsset $asset, array $constraints): void
    {
        $this->assertUsable($asset);
        foreach (['max_file_size', 'max_pixels'] as $key) {
            $annotated = $constraints['x-fal'][$key] ?? null;
            if (is_numeric($annotated)) {
                $constraints[$key] = is_numeric($constraints[$key] ?? null)
                    ? min((float) $constraints[$key], (float) $annotated) : $annotated;
            }
        }
        $size = Storage::disk($asset->storage_disk)->size($asset->storage_path);
        if (is_numeric($constraints['max_file_size'] ?? null) && $size > (float) $constraints['max_file_size']) {
            throw new InvalidArgumentException('The file exceeds this model input size limit.');
        }
        if (is_numeric($constraints['max_pixels'] ?? null)) {
            $pixels = $asset->metadata['pixels'] ?? null;
            if ($pixels === null && $asset->media_type === 'image') {
                $stream = Storage::disk($asset->storage_disk)->readStream($asset->storage_path);
                try {
                    $bytes = is_resource($stream) ? stream_get_contents($stream, self::MAX_BYTES['image'] + 1) : false;
                    $dimensions = is_string($bytes) && strlen($bytes) <= self::MAX_BYTES['image'] ? @getimagesizefromstring($bytes) : false;
                    $pixels = is_array($dimensions) ? $dimensions[0] * $dimensions[1] : null;
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
            if ($pixels === null || $pixels > (float) $constraints['max_pixels']) {
                throw new InvalidArgumentException('The image exceeds this model input pixel limit or cannot be inspected.');
            }
        }
    }

    private function filename(MediaAsset $asset): string
    {
        return app(AssetUploadPolicy::class)->safeName(
            $asset->original_name ?: 'asset-'.$asset->id.'.'.pathinfo($asset->storage_path, PATHINFO_EXTENSION),
        );
    }

    public function assertOwner(User $user, MediaAsset $asset): void
    {
        if ($asset->user_id !== $user->id) {
            throw new AuthorizationException('Aset ini bukan milik Anda.');
        }
    }

    /** Cookie-independent, time-limited URL a provider can fetch. TTL sized to provider queue+fetch. */
    public function signedUrl(MediaAsset $asset, int $ttlSeconds = 3600): string
    {
        return URL::temporarySignedRoute('media.asset.deliver', now()->addSeconds($ttlSeconds), ['asset' => $asset->id]);
    }

    public function deliver(MediaAsset $asset, bool $download = false): StreamedResponse
    {
        try {
            $this->assertUsable($asset);
        } catch (InvalidArgumentException) {
            abort(404);
        }
        $previewable = in_array($asset->media_type, ['image', 'audio', 'video'], true)
            && ($asset->metadata['previewable'] ?? true);

        return Storage::disk($asset->storage_disk)->response($asset->storage_path, $this->filename($asset), [
            'Content-Type' => $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'private, no-store',
        ], $download || ! $previewable ? 'attachment' : 'inline');
    }

    /**
     * Base64 data-uri of an active asset, for providers that consume an inline reference
     * (e.g. fal image_url) instead of fetching a URL. Bytes stay private — no public URL.
     */
    public function dataUri(MediaAsset $asset): string
    {
        $this->assertUsable($asset);
        if ($asset->size_bytes > self::MAX_BYTES['audio']) {
            throw new InvalidArgumentException('This file is too large for inline transport. Use the streamed upload transport.');
        }
        $disk = Storage::disk($asset->storage_disk);

        return 'data:'.$asset->mime.';base64,'.base64_encode((string) $disk->get($asset->storage_path));
    }

    /** Decode a bounded initial interval using a private seek cache, never a container duration or remote/file protocol. */
    public function audioHasDuration(MediaAsset $asset, int $minimumSeconds): bool
    {
        $binary = config('media_tools.ffprobe');
        if (! is_string($binary) || ! is_file($binary)) {
            throw new \RuntimeException('Audio inspection is unavailable.');
        }
        if ($asset->media_type !== 'audio' || $minimumSeconds < 1 || ! $asset->signature_ok
            || $asset->retention_status !== 'active' || ($asset->expires_at !== null && $asset->expires_at->isPast())) {
            throw new InvalidArgumentException('This audio reference is no longer available.');
        }
        $stream = Storage::disk($asset->storage_disk)->readStream($asset->storage_path);
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('The audio reference could not be read.');
        }
        $process = null;
        try {
            $process = app(Process::class, ['command' => [
                $binary, '-v', 'error', '-protocol_whitelist', 'cache,pipe',
                // Include the boundary frame despite codec priming or a nonzero initial timestamp.
                '-select_streams', 'a:0', '-read_intervals', '%+'.($minimumSeconds + 1),
                '-read_ahead_limit', (string) min((int) $asset->size_bytes, self::MAX_BYTES['audio']),
                '-show_entries', 'stream=sample_rate:frame=nb_samples', '-of', 'json', 'cache:pipe:0',
            ]]);
            $outputBytes = 0;
            $exit = $process->setInput($stream)->setTimeout(5)->run(function (string $type, string $chunk) use (&$outputBytes): void {
                $outputBytes += strlen($chunk);
                if ($outputBytes > 65_536) {
                    throw new \RuntimeException('Audio inspection exceeded its output limit.');
                }
            });
            $result = json_decode($process->getOutput(), true, 32);
            $rate = $result['streams'][0]['sample_rate'] ?? null;
            if ($exit !== 0 || ! is_string($rate) || ! ctype_digit($rate) || (int) $rate < 1
                || ! is_array($result['frames'] ?? null) || $result['frames'] === []) {
                throw new InvalidArgumentException('The audio could not be decoded. Upload another audio file.');
            }
            $samples = 0;
            foreach ($result['frames'] as $frame) {
                if (! is_int($frame['nb_samples'] ?? null) || $frame['nb_samples'] < 0) {
                    throw new InvalidArgumentException('The audio could not be decoded. Upload another audio file.');
                }
                $samples += $frame['nb_samples'];
            }

            return $samples >= $minimumSeconds * (int) $rate;
        } finally {
            if ($process?->isRunning()) {
                $process->stop(0.1);
            }
            fclose($stream);
        }
    }
}
