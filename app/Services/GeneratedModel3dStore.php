<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Models\ThreeDJob;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class GeneratedModel3dStore
{
    private const MAX_BYTES = 128 * 1024 * 1024;

    private const MAX_JSON_BYTES = 8 * 1024 * 1024;

    private const EXTERNAL_REASON = 'This model references external resources. Download the original to open it in a compatible 3D application.';

    private const EXTENSION_REASON = 'This model uses an unsupported extension. Download the original to open it in a compatible 3D application.';

    private const MESH_REASON = 'This model does not contain a supported previewable mesh. Download the original to open it in a compatible 3D application.';

    // These require neither a network decoder nor a custom resource loader in the pinned viewer.
    private const PREVIEW_EXTENSIONS = ['KHR_materials_unlit', 'KHR_texture_transform', 'KHR_lights_punctual', 'EXT_texture_webp'];

    public function __construct(private readonly AiProviderEndpoint $endpoint) {}

    public static function path(string $jobId): string
    {
        return 'generated/model3d/'.hash('sha256', $jobId).'/output.glb';
    }

    public static function validResultUrl(string $url): bool
    {
        if (! GeneratedImageStore::validResultUrl($url) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && preg_match('/(?:^|\.)(?:localhost|localdomain|local|internal|intranet|lan|home|onion)$/i', $host) !== 1;
    }

    /** Recover bytes saved before a worker died, without depending on an expiring provider URL. */
    public function existing(ThreeDJob $job): ?array
    {
        $path = self::path($job->job_id);
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }
        $metadata = $this->inspect($disk->path($path));

        return $metadata === null ? null : ['path' => $path, ...$metadata];
    }

    public function persist(ThreeDJob $job, string $url): array
    {
        if (($existing = $this->existing($job)) !== null) {
            return $existing;
        }
        if (! self::validResultUrl($url)) {
            throw new AiProxyException('The 3D provider returned an invalid result URL.', 502);
        }
        $disk = Storage::disk('local');
        $path = self::path($job->job_id);
        if (! $disk->exists(dirname($path)) && ! $disk->makeDirectory(dirname($path))) {
            throw new AiProxyException('The generated model could not be saved.', 503);
        }
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.part';
        $resource = fopen($disk->path($temporary), 'x+b');
        if ($resource === false) {
            throw new AiProxyException('The generated model could not be saved.', 503);
        }
        $sink = Utils::streamFor($resource);
        $written = 0;
        $bounded = FnStream::decorate($sink, [
            'write' => function (string $chunk) use ($sink, &$written): int {
                if ($written + strlen($chunk) > self::MAX_BYTES) {
                    throw new AiProxyException('The generated model exceeds the storage limit.', 502);
                }
                $count = $sink->write($chunk);
                if ($count !== strlen($chunk)) {
                    throw new AiProxyException('The generated model could not be saved.', 503);
                }
                $written += $count;

                return $count;
            },
        ]);
        $body = null;
        try {
            $parts = parse_url($url);
            $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            $options = $this->endpoint->requestOptions($origin);
            $handler = new CurlHandler;
            $response = Http::accept('model/gltf-binary, application/octet-stream')->timeout(180)->connectTimeout(10)
                ->setHandler(static fn ($request, array $options) => $handler($request, [...$options, 'sink' => $bounded]))
                ->withOptions([...$options, 'stream' => false, 'cookies' => false, 'allow_redirects' => false,
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            throw new AiProxyException('The generated model exceeds the storage limit.', 502);
                        }
                    },
                ])->get($url);
            if (! $response->successful()) {
                throw new AiProxyException('The generated model could not be downloaded.',
                    $response->serverError() || $response->status() === 429 ? 503 : 502);
            }
            if ((int) $response->header('Content-Length') > self::MAX_BYTES) {
                throw new AiProxyException('The generated model exceeds the storage limit.', 502);
            }
            $body = $response->toPsrResponse()->getBody();
            if ($written === 0) {
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                while (! $body->eof()) {
                    $chunk = $body->read(65536);
                    if ($chunk === '' && ! $body->eof()) {
                        throw new AiProxyException('The generated model download was interrupted.', 503);
                    }
                    $bounded->write($chunk);
                }
            }
            $body->close();
            $body = null;
            $bounded->close();
            $bounded = null;
            clearstatcache(true, $disk->path($temporary));
            $metadata = $this->inspect($disk->path($temporary));
            if ($metadata === null) {
                throw new AiProxyException('The 3D provider returned an invalid GLB file.', 502);
            }
            if (! $disk->move($temporary, $path)) {
                throw new AiProxyException('The generated model could not be saved.', 503);
            }

            return ['path' => $path, ...$metadata];
        } catch (AiProxyException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiProxyException('The generated model could not be downloaded or saved.', 503);
        } finally {
            $body?->close();
            $bounded?->close();
            $disk->delete($temporary);
        }
    }

    /** Container/JSON/resource validation only; original bytes are never rewritten or expanded. */
    private function inspect(string $path): ?array
    {
        if (! is_file($path) || ($size = filesize($path)) === false || $size < 24 || $size > self::MAX_BYTES) {
            return null;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $header = fread($handle, 12);
            if (strlen($header) !== 12 || substr($header, 0, 4) !== 'glTF') {
                return null;
            }
            $container = unpack('Vversion/Vlength', substr($header, 4));
            if ($container['version'] !== 2 || $container['length'] !== $size) {
                return null;
            }
            $offset = 12;
            $document = null;
            $binaryLength = null;
            $unknownChunk = false;
            $chunkIndex = 0;
            while ($offset < $size) {
                if ($size - $offset < 8 || strlen($chunkHeader = fread($handle, 8)) !== 8) {
                    return null;
                }
                $chunk = unpack('Vlength/Vtype', $chunkHeader);
                $length = $chunk['length'];
                $offset += 8;
                if ($length % 4 !== 0 || $length > $size - $offset) {
                    return null;
                }
                if ($chunkIndex === 0) {
                    if ($chunk['type'] !== 0x4E4F534A || $length < 4 || $length > self::MAX_JSON_BYTES) {
                        return null;
                    }
                    $json = fread($handle, $length);
                    if (strlen($json) !== $length || ! str_starts_with(ltrim($json), '{')) {
                        return null;
                    }
                    $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
                    if (! is_array($document) || ($document['asset']['version'] ?? null) !== '2.0') {
                        return null;
                    }
                } else {
                    if ($chunk['type'] === 0x4E4F534A) {
                        return null;
                    }
                    if ($chunk['type'] === 0x004E4942) {
                        if ($binaryLength !== null || $chunkIndex !== 1) {
                            return null;
                        }
                        $binaryLength = $length;
                    } else {
                        $unknownChunk = true;
                    }
                    if (fseek($handle, $length, SEEK_CUR) !== 0) {
                        return null;
                    }
                }
                $offset += $length;
                $chunkIndex++;
            }
            if ($document === null || ! $this->validResources($document, $binaryLength)) {
                return null;
            }
            $reason = $this->previewReason($document);
            if ($reason === null && ($unknownChunk || empty($document['meshes'])
                || isset($document['asset']['minVersion']) && $document['asset']['minVersion'] !== '2.0')) {
                $reason = self::MESH_REASON;
            }

            return ['mime_type' => 'model/gltf-binary', 'size_bytes' => $size,
                'previewable' => $reason === null, 'preview_unavailable_reason' => $reason];
        } catch (Throwable) {
            return null;
        } finally {
            fclose($handle);
        }
    }

    private function validResources(array $document, ?int $binaryLength): bool
    {
        $buffers = $document['buffers'] ?? [];
        $views = $document['bufferViews'] ?? [];
        $images = $document['images'] ?? [];
        if (! is_array($buffers) || ! array_is_list($buffers) || ! is_array($views) || ! array_is_list($views)
            || ! is_array($images) || ! array_is_list($images)) {
            return false;
        }
        foreach ($buffers as $index => $buffer) {
            if (! is_array($buffer) || ! is_int($buffer['byteLength'] ?? null) || $buffer['byteLength'] < 1) {
                return false;
            }
            if (! array_key_exists('uri', $buffer)) {
                if ($index !== 0 || $binaryLength === null || $buffer['byteLength'] > $binaryLength
                    || $binaryLength - $buffer['byteLength'] > 3) {
                    return false;
                }
            } elseif (! is_string($buffer['uri']) || $buffer['uri'] === '') {
                return false;
            }
            if (isset($buffer['uri']) && preg_match('#^data:application/(?:octet-stream|gltf-buffer);base64,(.*)$#Ds', $buffer['uri'], $encoded)) {
                $bytes = base64_decode($encoded[1], true);
                if ($bytes === false || strlen($bytes) !== $buffer['byteLength']) {
                    return false;
                }
                unset($bytes);
            }
        }
        if ($binaryLength !== null && ($buffers === [] || array_key_exists('uri', $buffers[0]))) {
            return false;
        }
        foreach ($views as $view) {
            if (! is_array($view) || ! is_int($view['buffer'] ?? null) || ! isset($buffers[$view['buffer']])
                || ! is_int($view['byteLength'] ?? null) || $view['byteLength'] < 1
                || ! is_int($view['byteOffset'] ?? 0) || ($view['byteOffset'] ?? 0) < 0
                || ($view['byteOffset'] ?? 0) > $buffers[$view['buffer']]['byteLength'] - $view['byteLength']) {
                return false;
            }
        }
        foreach ($images as $image) {
            if (! is_array($image) || array_key_exists('uri', $image) === array_key_exists('bufferView', $image)) {
                return false;
            }
            if (array_key_exists('uri', $image) && (! is_string($image['uri']) || $image['uri'] === '')) {
                return false;
            }
            if (array_key_exists('bufferView', $image) && (! is_int($image['bufferView']) || ! isset($views[$image['bufferView']])
                || ! is_string($image['mimeType'] ?? null))) {
                return false;
            }
        }

        return true;
    }

    private function previewReason(array $value): ?string
    {
        foreach ($value as $key => $item) {
            if ($key === 'uri') {
                // Embedded data is self-contained; SVG and other active/unsupported MIME types are not previewed.
                if (! is_string($item) || ! preg_match('#^data:(?:application/(?:octet-stream|gltf-buffer)|image/(?:png|jpeg|webp));base64,[A-Za-z0-9+/]*={0,2}$#D', $item)) {
                    return is_string($item) && str_starts_with($item, 'data:') ? self::MESH_REASON : self::EXTERNAL_REASON;
                }
            }
            if ($key === 'mimeType' && ! in_array($item, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                return self::MESH_REASON;
            }
            if (in_array($key, ['extensionsRequired', 'extensionsUsed', 'extensions'], true)) {
                if (! is_array($item)) {
                    return self::EXTENSION_REASON;
                }
                $extensions = $key === 'extensions' ? array_keys($item) : $item;
                foreach ($extensions as $extension) {
                    if (! in_array($extension, self::PREVIEW_EXTENSIONS, true)) {
                        return self::EXTENSION_REASON;
                    }
                }
            }
            if (is_array($item) && ($reason = $this->previewReason($item)) !== null) {
                return $reason;
            }
        }

        return null;
    }
}
