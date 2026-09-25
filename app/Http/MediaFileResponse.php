<?php

namespace App\Http;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class MediaFileResponse
{
    /** The caller must first resolve the output through its owner-scoped service. */
    public static function make(array $output, bool $preview = false): BinaryFileResponse
    {
        $safePreview = in_array($output['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif',
            'video/mp4', 'video/webm', 'audio/mp4', 'audio/webm', 'audio/wav', 'audio/mpeg', 'audio/flac', 'audio/ogg', 'model/gltf-binary'], true);
        abort_if($preview && (! $output['previewable'] || ! $safePreview), 415, 'This original is available as a download only.');
        $response = response()->file(Storage::disk($output['disk'])->path($output['path']), [
            'Content-Type' => $output['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Cross-Origin-Resource-Policy' => 'same-origin', 'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
        [$name, $fallback] = self::dispositionNames((string) $output['name']);
        $response->setContentDisposition($preview ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, $fallback);

        return $response;
    }

    /** @return array{0: string, 1: string} UTF-8 filename* and a printable-ASCII fallback. */
    private static function dispositionNames(string $name): array
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', '-', mb_scrub($name, 'UTF-8')), ' .');
        $name = mb_substr($name, 0, 180) ?: 'output';
        $dot = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);
        $extension = $dot === false ? '' : (string) preg_replace('/[^A-Za-z0-9]+/', '', Str::ascii(substr($name, $dot + 1)));
        $fallback = trim((string) preg_replace('/[^\x20-\x7e]|[%"\\\\\/]/', '_', Str::ascii($stem)), ' .');
        if (preg_match('/[A-Za-z0-9]/', $fallback) !== 1) {
            $fallback = 'output';
        }

        return [$name, $fallback.($extension === '' ? '' : '.'.$extension)];
    }
}
