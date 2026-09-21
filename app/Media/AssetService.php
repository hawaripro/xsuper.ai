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
        if ($asset->retention_status !== 'active') {
            abort(404);
        }
        $disk = Storage::disk($asset->storage_disk);
        if (! $disk->exists($asset->storage_path)) {
            abort(404);
        }

        return $disk->response($asset->storage_path, null, [
            'Content-Type' => $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
