<?php

namespace App\Media;

use App\Media\Enums\InputRole;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

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

    private const ALLOWED_MIME = [
        'image' => ['image/jpeg', 'image/png', 'image/webp'],
        'audio' => ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/webm'],
        'video' => ['video/mp4', 'video/webm'],
    ];

    private const ROLE_MEDIA = [
        'image_ref' => 'image', 'init_frame' => 'image', 'end_frame' => 'image', 'avatar_photo' => 'image',
        'speech_audio' => 'audio', 'reference_video' => 'video',
    ];

    public function store(User $user, UploadedFile $file, InputRole $role): MediaAsset
    {
        $mediaType = self::ROLE_MEDIA[$role->value] ?? throw new InvalidArgumentException('Peran ini tidak menerima unggahan aset.');
        if (! $file->isValid()) {
            throw new InvalidArgumentException('Berkas unggahan tidak valid.');
        }
        $size = (int) ($file->getSize() ?: 0);
        if ($size <= 0 || $size > self::MAX_BYTES[$mediaType]) {
            throw new InvalidArgumentException('Ukuran berkas melebihi batas yang diizinkan.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($mime, self::ALLOWED_MIME[$mediaType], true)) {
            throw new InvalidArgumentException('Jenis berkas tidak didukung untuk peran ini.');
        }

        $id = (string) Str::uuid();
        $path = "media-assets/{$id}.".$this->extensionFor($mime);
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        return MediaAsset::create([
            'id' => $id, 'user_id' => $user->id, 'media_type' => $mediaType, 'role' => $role->value,
            'storage_disk' => 'local', 'storage_path' => $path, 'size_bytes' => $size, 'mime' => $mime,
            'signature_ok' => true, 'retention_status' => 'active',
        ]);
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

    public function deliver(MediaAsset $asset): StreamedResponse
    {
        if ($asset->retention_status !== 'active' || ($asset->expires_at !== null && $asset->expires_at->isPast())) {
            abort(404);
        }
        $disk = Storage::disk($asset->storage_disk);
        if (! $disk->exists($asset->storage_path)) {
            abort(404);
        }

        return $disk->response($asset->storage_path, null, [
            'Content-Type' => $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Base64 data-uri of an active asset, for providers that consume an inline reference
     * (e.g. fal image_url) instead of fetching a URL. Bytes stay private — no public URL.
     */
    public function dataUri(MediaAsset $asset): string
    {
        if (! $asset->signature_ok || $asset->retention_status !== 'active' || ($asset->expires_at !== null && $asset->expires_at->isPast())) {
            throw new InvalidArgumentException('Aset referensi tidak lagi tersedia.');
        }
        $disk = Storage::disk($asset->storage_disk);
        if (! $disk->exists($asset->storage_path)) {
            throw new InvalidArgumentException('Berkas aset referensi tidak ditemukan.');
        }

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

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'audio/mpeg' => 'mp3', 'audio/wav', 'audio/x-wav' => 'wav', 'audio/mp4' => 'm4a', 'audio/webm' => 'weba',
            'video/mp4' => 'mp4', 'video/webm' => 'webm',
            default => 'bin',
        };
    }
}
