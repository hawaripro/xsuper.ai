<?php

namespace App\Media;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/** Inspect local bytes only. Archives, weights and mesh resources are never opened or executed. */
final class AssetUploadPolicy
{
    private const TEXT_BYTES = 2_097_152;

    private const MODEL_TEXT_BYTES = 8_388_608;

    private const KINDS = [
        'image' => ['max_bytes' => 15_728_640, 'accepted_mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'accepted_extensions' => ['jpg', 'jpeg', 'png', 'webp']],
        'audio' => ['max_bytes' => 31_457_280, 'accepted_mimes' => ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/webm', 'audio/ogg', 'audio/flac', 'audio/aac'], 'accepted_extensions' => ['mp3', 'wav', 'm4a', 'weba', 'ogg', 'flac', 'aac']],
        'video' => ['max_bytes' => 104_857_600, 'accepted_mimes' => ['video/mp4', 'video/webm'], 'accepted_extensions' => ['mp4', 'webm']],
        'document' => ['max_bytes' => 15_728_640, 'max_text_bytes' => self::TEXT_BYTES, 'accepted_mimes' => ['application/pdf', 'text/plain', 'text/markdown', 'text/csv', 'application/json'], 'accepted_extensions' => ['pdf', 'txt', 'md', 'markdown', 'csv', 'json']],
        'model3d' => ['max_bytes' => 104_857_600, 'max_text_bytes' => self::MODEL_TEXT_BYTES, 'accepted_mimes' => ['model/gltf-binary', 'model/gltf+json', 'model/obj', 'model/mtl', 'model/stl', 'application/ply', 'application/vnd.autodesk.fbx', 'model/vnd.usda', 'model/vnd.usdc', 'model/vnd.usdz+zip'], 'accepted_extensions' => ['glb', 'gltf', 'obj', 'mtl', 'stl', 'ply', 'fbx', 'usda', 'usdc', 'usdz']],
        'file' => ['max_bytes' => 104_857_600, 'max_text_bytes' => self::TEXT_BYTES, 'accepted_mimes' => ['application/zip', 'application/gzip', 'application/x-tar', 'application/x-7z-compressed', 'application/vnd.rar', 'application/octet-stream', 'image/svg+xml'], 'accepted_extensions' => ['zip', 'gz', 'tgz', 'tar', '7z', 'rar', 'safetensors', 'gguf', 'pt', 'pth', 'ckpt', 'pkl', 'bin', 'svg']],
    ];

    public const ROLE_KINDS = [
        'image_ref' => 'image', 'init_frame' => 'image', 'end_frame' => 'image', 'avatar_photo' => 'image', 'mask_image' => 'image',
        'speech_audio' => 'audio', 'audio_reference' => 'audio', 'reference_video' => 'video',
        'document' => 'document', 'file' => 'file', 'model_reference' => 'model3d',
    ];

    public function policy(): array
    {
        $uploadBytes = $this->iniBytes('upload_max_filesize');
        $requestBytes = $this->iniBytes('post_max_size');
        $transportBytes = min($uploadBytes ?? PHP_INT_MAX, $requestBytes ?? PHP_INT_MAX);
        $roles = [];
        foreach (self::ROLE_KINDS as $role => $kind) {
            $rules = self::KINDS[$kind];
            if ($kind === 'file') {
                foreach (self::KINDS as $other) {
                    $rules['accepted_mimes'] = array_values(array_unique([...$rules['accepted_mimes'], ...$other['accepted_mimes']]));
                    $rules['accepted_extensions'] = array_values(array_unique([...$rules['accepted_extensions'], ...$other['accepted_extensions']]));
                }
                $rules['kind_max_bytes'] = array_map(static fn (array $value): int => $value['max_bytes'], self::KINDS);
            }
            $rules['application_max_bytes'] = $rules['max_bytes'];
            $rules['max_bytes'] = min($rules['max_bytes'], $transportBytes);
            if (isset($rules['max_text_bytes'])) {
                $rules['max_text_bytes'] = min($rules['max_text_bytes'], $rules['max_bytes']);
            }
            $roles[$role] = ['kind' => $kind, 'role' => $role, ...$rules, 'previewable' => in_array($kind, ['image', 'audio', 'video'], true)];
        }

        return ['roles' => $roles, 'max_files' => min(20, max(0, (int) ini_get('max_file_uploads'))),
            'max_request_bytes' => $requestBytes, 'upload_max_bytes' => $uploadBytes];
    }

    /** @return array{kind:string,mime:string,extension:string,size:int,original_name:string,metadata:array} */
    public function inspect(UploadedFile $file, string $role): array
    {
        $kind = self::ROLE_KINDS[$role] ?? throw new InvalidArgumentException('This role does not accept file uploads.');
        if (! $file->isValid() || ! is_string($path = $file->getRealPath())) {
            throw new InvalidArgumentException('The uploaded file is invalid.');
        }
        $size = (int) $file->getSize();
        $actualSize = filesize($path);
        if ($size < 1 || $actualSize === false || $actualSize < 1 || max($size, $actualSize) > self::KINDS[$kind]['max_bytes']) {
            throw new InvalidArgumentException('The file exceeds the upload size limit for this role.');
        }
        $size = $actualSize;
        $extension = strtolower($file->getClientOriginalExtension());
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $header = (string) file_get_contents($path, false, null, 0, 16_384);
        $metadata = ['previewable' => false];
        $mime = null;
        $detectedKind = $kind;

        foreach (['image', 'audio', 'video'] as $candidate) {
            if (($kind !== $candidate && $kind !== 'file') || ! in_array($detected, self::KINDS[$candidate]['accepted_mimes'], true)) {
                continue;
            }
            if ($size > self::KINDS[$candidate]['max_bytes']) {
                throw new InvalidArgumentException('The file exceeds the upload size limit for its media type.');
            }
            $mime = $detected;
            $detectedKind = $candidate;
            $extension = $this->mediaExtension($mime);
            $metadata['previewable'] = true;
            if ($candidate === 'image') {
                $dimensions = @getimagesize($path);
                if (! is_array($dimensions) || ($dimensions['mime'] ?? null) !== $mime || $dimensions[0] < 1 || $dimensions[1] < 1) {
                    throw new InvalidArgumentException('The image signature or dimensions are invalid.');
                }
                $metadata += ['width' => $dimensions[0], 'height' => $dimensions[1], 'pixels' => $dimensions[0] * $dimensions[1]];
            }
            break;
        }
        if ($mime === null && in_array($kind, ['document', 'file'], true)) {
            if ($extension === 'pdf' && str_starts_with($header, '%PDF-') && $detected === 'application/pdf') {
                if ($size > self::KINDS['document']['max_bytes']) {
                    throw new InvalidArgumentException('PDF files must not exceed 15 MiB.');
                }
                $mime = 'application/pdf';
                $detectedKind = 'document';
            } elseif (in_array($extension, ['txt', 'md', 'markdown', 'csv', 'json'], true)) {
                if (! str_starts_with($detected, 'text/') && ! in_array($detected, ['application/json', 'application/csv', 'application/x-empty'], true)) {
                    throw new InvalidArgumentException('This document contains a non-text format. Upload it using its original supported extension.');
                }
                $text = $this->utf8($path, $size, self::TEXT_BYTES);
                if ($extension === 'json') {
                    $this->json($text);
                }
                $mime = match ($extension) {
                    'json' => 'application/json', 'csv' => 'text/csv', 'md', 'markdown' => 'text/markdown', default => 'text/plain',
                };
                $detectedKind = 'document';
                $metadata['text_encoding'] = 'UTF-8';
            }
        }
        if ($mime === null && in_array($kind, ['model3d', 'file'], true)) {
            $mime = $this->modelMime($path, $size, $extension, $header);
            if ($mime !== null) {
                $detectedKind = 'model3d';
                $metadata['preview_reason'] = 'Uploaded models and sidecars are download-only; external mesh resources are not fetched.';
            }
        }
        if ($mime === null && $kind === 'file') {
            $mime = $this->opaqueMime($path, $size, $extension, $header);
            $detectedKind = 'file';
        }
        if ($mime === null) {
            throw new InvalidArgumentException('The file type or content signature is not supported for this role.');
        }

        return ['kind' => $detectedKind, 'mime' => $mime, 'extension' => $extension, 'size' => $size,
            'original_name' => $this->safeName($file->getClientOriginalName(), $extension), 'metadata' => $metadata];
    }

    public function safeName(string $name, string $fallbackExtension = 'bin'): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F\p{Cf}]/u', '', mb_scrub($name, 'UTF-8')) ?? '';
        $name = trim(str_replace(['"', ':'], '_', $name), " .\t\n\r\0\x0B");

        return $name === '' ? 'upload.'.$fallbackExtension : mb_strcut($name, 0, 240, 'UTF-8');
    }

    private function modelMime(string $path, int $size, string $extension, string $header): ?string
    {
        if ($extension === 'glb' && str_starts_with($header, 'glTF') && $size >= 24) {
            $container = unpack('Vversion/Vlength/VjsonLength/VjsonType', substr($header, 4, 16));
            if ($container['version'] !== 2 || $container['length'] !== $size || $container['jsonType'] !== 0x4E4F534A
                || $container['jsonLength'] < 2 || $container['jsonLength'] > self::MODEL_TEXT_BYTES || $container['jsonLength'] + 20 > $size) {
                return null;
            }
            $json = $this->json((string) file_get_contents($path, false, null, 20, $container['jsonLength']));

            return is_array($json) && ($json['asset']['version'] ?? null) === '2.0' ? 'model/gltf-binary' : null;
        }
        if ($extension === 'gltf') {
            $json = $this->json($this->utf8($path, $size, self::MODEL_TEXT_BYTES));

            return is_array($json) && ($json['asset']['version'] ?? null) === '2.0' ? 'model/gltf+json' : null;
        }
        if (in_array($extension, ['obj', 'mtl', 'usda'], true)) {
            $text = $this->utf8($path, $size, self::MODEL_TEXT_BYTES);

            return match (true) {
                $extension === 'obj' && preg_match('/^\h*v\h+[-+.\d]+\h+[-+.\d]+\h+[-+.\d]+/m', $text) === 1 => 'model/obj',
                $extension === 'mtl' && preg_match('/^\h*newmtl\h+\S+/m', $text) === 1 => 'model/mtl',
                $extension === 'usda' && str_starts_with($text, '#usda ') => 'model/vnd.usda',
                default => null,
            };
        }
        if ($extension === 'stl') {
            if ($size >= 84 && strlen($header) >= 84 && 84 + 50 * unpack('Vcount', substr($header, 80, 4))['count'] === $size) {
                return 'model/stl';
            }
            $text = $this->utf8($path, $size, self::MODEL_TEXT_BYTES);

            return preg_match('/^\s*solid\b[\s\S]*\bfacet\s+normal\b[\s\S]*\bendsolid\b/', $text) === 1 ? 'model/stl' : null;
        }
        if ($extension === 'ply' && preg_match('/\Aply\r?\nformat (?:ascii|binary_little_endian|binary_big_endian) 1\.0\r?\n/', $header) === 1
            && preg_match('/(?:\r?\n)end_header\r?\n/', $header) === 1) {
            return 'application/ply';
        }
        if ($extension === 'fbx') {
            if (str_starts_with($header, "Kaydara FBX Binary  \0\x1a\0")) {
                return 'application/vnd.autodesk.fbx';
            }
            $text = $this->utf8($path, $size, self::MODEL_TEXT_BYTES);

            return preg_match('/^; FBX [0-9.]+ project file/m', $text) === 1 && str_contains($text, 'FBXHeaderExtension:') ? 'application/vnd.autodesk.fbx' : null;
        }
        if ($extension === 'usdc' && str_starts_with($header, 'PXR-USDC')) {
            return 'model/vnd.usdc';
        }
        if ($extension === 'usdz' && $this->zipSignature($header)) {
            return 'model/vnd.usdz+zip';
        }

        return null;
    }

    private function opaqueMime(string $path, int $size, string $extension, string $header): ?string
    {
        $archive = match (true) {
            $extension === 'zip' && $this->zipSignature($header) => 'application/zip',
            in_array($extension, ['gz', 'tgz'], true) && str_starts_with($header, "\x1f\x8b\x08") => 'application/gzip',
            $extension === 'tar' && $size >= 512 && substr($header, 257, 5) === 'ustar' => 'application/x-tar',
            $extension === '7z' && str_starts_with($header, "7z\xbc\xaf\x27\x1c") => 'application/x-7z-compressed',
            $extension === 'rar' && (str_starts_with($header, "Rar!\x1a\x07\0") || str_starts_with($header, "Rar!\x1a\x07\x01\0")) => 'application/vnd.rar',
            default => null,
        };
        if ($archive !== null) {
            return $archive;
        }
        if ($extension === 'safetensors' && $size > 8) {
            $length = unpack('Vlow/Vhigh', substr($header, 0, 8));
            if ($length['high'] !== 0 || $length['low'] < 2 || $length['low'] > self::MODEL_TEXT_BYTES || $length['low'] + 8 > $size) {
                return null;
            }
            $tensors = $this->json((string) file_get_contents($path, false, null, 8, $length['low']));
            if (! is_array($tensors) || $tensors === []) {
                return null;
            }
            foreach ($tensors as $name => $tensor) {
                if ($name === '__metadata__') {
                    continue;
                }
                $offsets = is_array($tensor) ? ($tensor['data_offsets'] ?? null) : null;
                if (! is_array($offsets) || count($offsets) !== 2 || ! is_int($offsets[0] ?? null) || ! is_int($offsets[1] ?? null)
                    || $offsets[0] < 0 || $offsets[1] < $offsets[0] || $offsets[1] > $size - $length['low'] - 8) {
                    return null;
                }
            }

            return 'application/octet-stream';
        }
        if (in_array($extension, ['gguf', 'bin'], true) && strlen($header) >= 8 && substr($header, 0, 4) === 'GGUF'
            && in_array(unpack('Vversion', substr($header, 4, 4))['version'], [2, 3], true)) {
            return 'application/octet-stream';
        }
        if (in_array($extension, ['pt', 'pth', 'ckpt', 'pkl', 'bin'], true)
            && ($this->zipSignature($header) || (strlen($header) >= 2 && $header[0] === "\x80" && in_array(ord($header[1]), [2, 3, 4, 5], true)))) {
            return 'application/octet-stream';
        }
        if ($extension === 'svg') {
            $text = $this->utf8($path, $size, self::TEXT_BYTES);
            if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $text) === 1 || ! class_exists(\DOMDocument::class)) {
                return null;
            }
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                return $document->loadXML($text, LIBXML_NONET) && $document->documentElement?->localName === 'svg' ? 'image/svg+xml' : null;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        return null;
    }

    private function utf8(string $path, int $size, int $limit): string
    {
        if ($size > $limit) {
            throw new InvalidArgumentException('This text format exceeds its bounded upload size limit.');
        }
        $text = (string) file_get_contents($path);
        if (! mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            throw new InvalidArgumentException('Text files must contain valid UTF-8 text, not binary data.');
        }

        return $text;
    }

    private function json(string $text): mixed
    {
        try {
            return json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('The file does not contain valid bounded JSON.');
        }
    }

    private function iniBytes(string $setting): ?int
    {
        $value = trim((string) ini_get($setting));
        if ($value === '' || (float) $value <= 0) {
            return null;
        }
        $factor = match (strtolower(substr($value, -1))) {
            'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, default => 1,
        };

        return (int) ((float) $value * $factor);
    }

    private function zipSignature(string $header): bool
    {
        return str_starts_with($header, "PK\x03\x04") || str_starts_with($header, "PK\x05\x06");
    }

    private function mediaExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'audio/mpeg' => 'mp3', 'audio/wav', 'audio/x-wav' => 'wav', 'audio/mp4' => 'm4a', 'audio/webm' => 'weba',
            'audio/ogg' => 'ogg', 'audio/flac' => 'flac', 'audio/aac' => 'aac', 'video/mp4' => 'mp4', 'video/webm' => 'webm',
        };
    }
}
