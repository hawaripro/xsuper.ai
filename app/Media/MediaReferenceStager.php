<?php

namespace App\Media;

use App\Exceptions\AiProxyException;
use App\Models\AiProviderProfile;
use App\Models\MediaAsset;
use App\Services\AiProviderTransport;
use Illuminate\Support\Facades\Storage;

/** Stages a validated owned asset by stream; never encodes a reference into JSON. */
final class MediaReferenceStager
{
    public function __construct(private readonly AiProviderTransport $transport) {}

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
}
