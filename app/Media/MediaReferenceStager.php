<?php

namespace App\Media;

use App\Exceptions\AiProxyException;
use App\Models\AiProviderProfile;
use App\Models\MediaAsset;
use App\Services\AiProviderTransport;
use Illuminate\Support\Facades\Storage;

/** Stages a validated owned asset: Fal/Kinovi by stream, Runware through mediaStorage (a bounded data URI or a signed URL). */
final class MediaReferenceStager
{
    /** Larger images/audio, and every video, reach Runware as a signed, time-limited URL instead of inline bytes. */
    private const RUNWARE_INLINE_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly AiProviderTransport $transport, private readonly ?AssetService $assets = null) {}

    public function stage(AiProviderProfile $provider, string $assetId, ?int $ownerId = null): string
    {
        $asset = MediaAsset::query()->find($assetId);
        if ($asset === null || ($ownerId !== null && (int) $asset->user_id !== $ownerId)
            || ! $asset->signature_ok || $asset->retention_status !== 'active'
            || ($asset->expires_at !== null && $asset->expires_at->isPast())) {
            throw new AiProxyException('The reference file is no longer available.', 422);
        }
        $disk = Storage::disk($asset->storage_disk);
        if (! $disk->exists($asset->storage_path) || (int) $disk->size($asset->storage_path) !== (int) $asset->size_bytes) {
            throw new AiProxyException('The reference file is no longer available.', 422);
        }
        if ($provider->protocol === 'runware') {
            // mediaStorage returns a reusable mediaUUID that replaces the owned asset ID in the task.
            return $this->transport->uploadRunwareReference($provider, $this->runwareMedia($asset));
        }
        $stream = $disk->readStream($asset->storage_path);
        if (! is_resource($stream)) {
            throw new AiProxyException('The reference file could not be read.', 503);
        }
        // Stored paths have server-issued filenames and safe extensions, unlike user filenames.
        $name = basename($asset->storage_path);
        try {
            return match ($provider->protocol) {
                'fal' => $this->transport->uploadFalReference($provider, $stream, $name, $asset->mime, (int) $asset->size_bytes),
                'kinovi' => $this->transport->uploadKinoviReference($provider, $stream, $name, $asset->mime, (int) $asset->size_bytes),
                default => throw new AiProxyException('Reference staging is not supported by this provider.', 422),
            };
        } finally {
            fclose($stream);
        }
    }

    private function runwareMedia(MediaAsset $asset): string
    {
        $assets = $this->assets ?? app(AssetService::class);
        $inline = in_array($asset->media_type, ['image', 'audio'], true) && (int) $asset->size_bytes <= self::RUNWARE_INLINE_BYTES;

        return $inline ? $assets->dataUri($asset) : $assets->signedUrl($asset);
    }
}
