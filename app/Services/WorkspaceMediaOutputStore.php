<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Media\MediaJsonSchema;
use App\Models\User;
use App\Models\WorkspaceMediaJob;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Stores originals, never executes/parses archives or follows resources referenced by a model. */
class WorkspaceMediaOutputStore
{
    private const MAX_FILE_BYTES = 512 * 1024 * 1024;
    private const MAX_DATA_BYTES = 16 * 1024 * 1024;

    public function __construct(private readonly AiProviderEndpoint $endpoint, private readonly StorageQuotaService $quota) {}

    public static function directory(string $jobId): string
    {
        return 'generated/workspace/'.hash('sha256', $jobId);
    }

    public static function downloadUrl(string $jobId, string $outputId): string
    {
        return '/api/media/workspace/jobs/'.rawurlencode($jobId).'/outputs/'.rawurlencode($outputId).'/download';
    }

    /** Each successful file is checkpointed. A retry only downloads files not already retained. */
    public function persist(WorkspaceMediaJob $job): mixed
    {
        $schema = $job->capability_snapshot['output_schema'] ?? $job->provider_bindings['output_schema'] ?? [];
        $result = $job->provider_result;
        if ($result === null && ! empty($job->provider_result_urls)) {
            $result = ['files' => array_map(static fn (string $url): array => ['url' => $url], $job->provider_result_urls ?? [])];
        }
        $data = MediaJsonSchema::dataObject($schema, $this->walk($job, $result, is_array($schema) ? $schema : [], []));
        // The result document is a first-class original data output, including captions, masks' metadata and scores.
        $content = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->store($job, ['$result'], null, $content, is_string($data) ? 'result.txt' : 'result.json',
            is_string($data) ? 'text/plain' : 'application/json');

        return $data;
    }

    private function walk(WorkspaceMediaJob $job, mixed $value, array $schema, array $path, array $parent = []): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $childSchema = $this->childSchema($schema, $key);
                if (in_array($key, ['url', 'uri'], true) && isset($schema['x-workspace-output'])) {
                    $childSchema['x-workspace-output'] = $schema['x-workspace-output'];
                }
                $out[$key] = $this->walk($job, $child, $childSchema, [...$path, $key], $value);
            }

            return $out;
        }
        if (! is_string($value)) {
            return $value;
        }
        $field = (string) ($path[array_key_last($path)] ?? '');
        $name = $parent['file_name'] ?? $parent['filename'] ?? $parent['name'] ?? null;
        $mime = $parent['content_type'] ?? $parent['mime_type'] ?? $parent['mime'] ?? null;
        if ($this->isFileUrl($value, $field, $schema, $parent)) {
            $asset = $this->store($job, $path, $value, null, is_string($name) ? $name : null, is_string($mime) ? $mime : null);

            return self::downloadUrl($job->job_id, $asset['id']);
        }
        if (str_starts_with($value, 'data:') && preg_match('/^data:([a-z0-9.+-]+\/[a-z0-9.+-]+);base64,([A-Za-z0-9+\/=\r\n]+)$/D', $value, $match)) {
            $bytes = base64_decode($match[2], true);
            if ($bytes === false || strlen($bytes) > self::MAX_DATA_BYTES) {
                throw new AiProxyException('The provider returned an invalid inline output.', 502);
            }
            $asset = $this->store($job, $path, null, $bytes, is_string($name) ? $name : null, $match[1]);

            return self::downloadUrl($job->job_id, $asset['id']);
        }
        if (($schema['x-workspace-output']['encoding'] ?? null) === 'base64' && $value !== '' && ! preg_match('~^https?://~i', $value)) {
            // Declared raw base64 file bytes become a private original; a non-base64 value stays data as provided.
            $bytes = strlen($value) <= self::MAX_DATA_BYTES * 2 ? base64_decode($value, true) : false;
            if (is_string($bytes) && strlen($bytes) > self::MAX_DATA_BYTES || strlen($value) > self::MAX_DATA_BYTES * 2) {
                throw new AiProxyException('The inline provider output exceeds the storage limit.', 413);
            }
            if (is_string($bytes) && $bytes !== '') {
                $asset = $this->store($job, $path, null, $bytes, is_string($name) ? $name : null, is_string($mime) ? $mime : null);

                return self::downloadUrl($job->job_id, $asset['id']);
            }
        }
        if ($field === 'content' && (str_contains(strtolower((string) $mime), 'svg') || preg_match('/^\s*(?:<\?xml[^>]*>\s*)?<svg\b/i', $value))) {
            $asset = $this->store($job, $path, null, $value, is_string($name) ? $name : 'output.svg', 'image/svg+xml');

            return self::downloadUrl($job->job_id, $asset['id']);
        }

        return $value;
    }

    private function isFileUrl(string $value, string $field, array $schema, array $parent): bool
    {
        if (! preg_match('~^https?://~i', $value)) {
            return false;
        }
        return isset($schema['x-workspace-output'])
            || preg_match('/(?:^|_)(?:file|image|mask|video|audio|mesh|texture|model|archive|weights|checkpoint|animation|depth|normal|albedo)(?:_|$)/i', $field) === 1
            || (in_array($field, ['url', 'uri'], true) && (isset($parent['content_type']) || isset($parent['file_size']) || isset($parent['file_name'])
                || array_keys($parent) === ['url']));
    }

    private function childSchema(array $schema, string|int $key): array
    {
        $child = $schema['properties'][$key] ?? (is_int($key)
            ? ($schema['prefixItems'][$key] ?? $schema['items'] ?? []) : ($schema['additionalProperties'] ?? []));
        $child = is_array($child) ? $child : [];
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $branch) {
                if (is_array($branch)) {
                    $child = array_replace_recursive($child, $this->childSchema($branch, $key));
                    if (in_array($key, ['url', 'uri'], true) && isset($branch['x-workspace-output'])) {
                        $child['x-workspace-output'] = $branch['x-workspace-output'];
                    }
                }
            }
        }

        return $child;
    }

    private function store(WorkspaceMediaJob $job, array $sourcePath, ?string $url, ?string $content, ?string $name, ?string $declaredMime): array
    {
        $identity = $url !== null ? ['url' => hash('sha256', $url)] : ['field' => $sourcePath];
        $digest = hash('sha256', json_encode([$job->job_id, $identity], JSON_THROW_ON_ERROR));
        $id = substr($digest, 0, 8).'-'.substr($digest, 8, 4).'-5'.substr($digest, 13, 3).'-a'.substr($digest, 17, 3).'-'.substr($digest, 20, 12);
        $disk = Storage::disk('local');
        $path = self::directory($job->job_id).'/'.$id.'.bin';
        foreach ($job->asset_paths ?? [] as $existing) {
            if (($existing['id'] ?? null) === $id && $disk->exists($path)) {
                return $existing;
            }
        }
        if (! $disk->exists(dirname($path)) && ! $disk->makeDirectory(dirname($path))) {
            throw new AiProxyException('The generated output could not be saved.', 503);
        }
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.part';
        try {
            // A process may have died between atomic promotion and its metadata commit.
            if (! $disk->exists($path)) {
                if ($url !== null) {
                    $downloadMime = $this->download($url, $disk->path($temporary));
                    $declaredMime ??= $downloadMime;
                } else {
                    if ($content === null || strlen($content) > self::MAX_DATA_BYTES || ! $disk->put($temporary, $content)) {
                        throw new AiProxyException('The structured output exceeds the storage limit or could not be saved.', 503);
                    }
                }
            }
            $absolute = $disk->path($disk->exists($path) ? $path : $temporary);
            $metadata = $this->inspect($absolute, $name, $declaredMime, $url);
            $asset = ['id' => $id, 'path' => $path, 'source_path' => $sourcePath, ...$metadata];
            DB::transaction(function () use ($job, $asset, $path, $temporary, $disk): void {
                $owner = User::query()->lockForUpdate()->findOrFail($job->user_id);
                $locked = WorkspaceMediaJob::query()->lockForUpdate()->findOrFail($job->id);
                if ($locked->status !== 'processing' || $locked->stage !== 'saving'
                    || ! is_string($locked->processing_token) || ! hash_equals($locked->processing_token, (string) $job->processing_token)) {
                    throw new AiProxyException('This output save is no longer active.', 409);
                }
                $assets = $locked->asset_paths ?? [];
                $existingIndex = null;
                foreach ($assets as $index => $existing) {
                    if (($existing['id'] ?? null) === $asset['id']) {
                        if ($disk->exists($path)) {
                            $job->asset_paths = $assets;

                            return;
                        }
                        $existingIndex = $index;
                        break;
                    }
                }
                $this->quota->assertCanStore($owner, $asset['bytes']);
                if (! $disk->exists($path) && ! $disk->move($temporary, $path)) {
                    throw new AiProxyException('The generated output could not be saved.', 503);
                }
                if ($existingIndex === null) {
                    $assets[] = $asset;
                } else {
                    $assets[$existingIndex] = $asset;
                }
                $locked->update(['asset_paths' => $assets, 'processing_started_at' => now()]);
                $job->asset_paths = $assets;
            });

            return $asset;
        } finally {
            $disk->delete($temporary);
        }
    }

    /** Public HTTPS, DNS-pinned, no credentials, no redirects, bounded streaming; original bytes stay unchanged. */
    private function download(string $url, string $path): ?string
    {
        if (! GeneratedModel3dStore::validResultUrl($url)) {
            throw new AiProxyException('The provider returned an unsafe output location.', 502);
        }
        $resource = fopen($path, 'x+b');
        if ($resource === false) {
            throw new AiProxyException('The generated output could not be saved.', 503);
        }
        $sink = Utils::streamFor($resource);
        $written = 0;
        $bounded = FnStream::decorate($sink, ['write' => function (string $chunk) use ($sink, &$written): int {
            if ($written + strlen($chunk) > self::MAX_FILE_BYTES) {
                throw new AiProxyException('The generated file exceeds the storage limit.', 413);
            }
            $count = $sink->write($chunk);
            if ($count !== strlen($chunk)) {
                throw new AiProxyException('The generated file could not be saved.', 503);
            }
            $written += $count;

            return $count;
        }]);
        $body = null;
        try {
            $parts = parse_url($url);
            $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            $options = $this->endpoint->requestOptions($origin);
            $handler = new CurlHandler;
            $response = Http::accept('*/*')->timeout(180)->connectTimeout(10)
                ->setHandler(static fn ($request, array $options) => $handler($request, [...$options, 'sink' => $bounded]))
                ->withOptions([...$options, 'stream' => false, 'cookies' => false, 'allow_redirects' => false,
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_FILE_BYTES) {
                            throw new AiProxyException('The generated file exceeds the storage limit.', 413);
                        }
                    },
                ])->get($url);
            if (! $response->successful() || (int) $response->header('Content-Length') > self::MAX_FILE_BYTES) {
                throw new AiProxyException('The generated output could not be downloaded.', 502);
            }
            $body = $response->toPsrResponse()->getBody();
            if ($written === 0) {
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                while (! $body->eof()) {
                    $chunk = $body->read(65536);
                    if ($chunk === '' && ! $body->eof()) {
                        throw new AiProxyException('The output download was interrupted.', 503);
                    }
                    $bounded->write($chunk);
                }
            }

            return explode(';', $response->header('Content-Type') ?? '')[0] ?: null;
        } catch (AiProxyException|HttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiProxyException('The generated output could not be downloaded or saved.', 503);
        } finally {
            $body?->close();
            $bounded->close();
        }
    }

    private function inspect(string $path, ?string $name, ?string $declaredMime, ?string $url): array
    {
        clearstatcache(true, $path);
        $bytes = filesize($path);
        if ($bytes === false || $bytes > self::MAX_FILE_BYTES) {
            throw new AiProxyException('The generated output exceeds the storage limit.', 413);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new AiProxyException('The generated output could not be read.', 503);
        }
        try {
            $prefix = fread($handle, 65536);
        } finally {
            fclose($handle);
        }
        $prefix = is_string($prefix) ? $prefix : '';
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $extension = strtolower(pathinfo((string) ($name ?: parse_url((string) $url, PHP_URL_PATH)), PATHINFO_EXTENSION));
        $kind = 'file';
        $preview = false;
        $image = @getimagesize($path);
        if ($image !== false && in_array($image['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif'], true)) {
            $mime = $image['mime'];
            $kind = 'image';
            $preview = $image[0] > 0 && $image[1] > 0 && $image[0] <= intdiv(50_000_000, $image[1]);
        } elseif (str_starts_with($prefix, 'glTF') && $bytes >= 20) {
            $header = unpack('Vversion/Vlength', substr($prefix, 4, 8));
            if (($header['version'] ?? null) !== 2 || ($header['length'] ?? null) !== $bytes) {
                throw new AiProxyException('The provider returned an invalid GLB container.', 502);
            }
            $mime = 'model/gltf-binary';
            $kind = 'model3d';
            // Embedded-resource checks are required before allowing a viewer to fetch this model.
            $preview = (bool) (app(GeneratedModel3dStore::class)->inspect($path)['previewable'] ?? false);
        } elseif (preg_match('/^\s*(?:<\?xml[^>]*>\s*)?<svg\b/i', $prefix)) {
            $mime = 'image/svg+xml';
            $kind = 'image';
        } elseif (substr($prefix, 4, 4) === 'ftyp') {
            $mime = in_array($extension, ['m4a', 'aac'], true) || str_starts_with((string) $declaredMime, 'audio/') ? 'audio/mp4' : 'video/mp4';
            $kind = str_starts_with($mime, 'audio/') ? 'audio' : 'video';
            $preview = true;
        } elseif (str_starts_with($prefix, "\x1a\x45\xdf\xa3")) {
            $mime = str_starts_with((string) $declaredMime, 'audio/') ? 'audio/webm' : 'video/webm';
            $kind = str_starts_with($mime, 'audio/') ? 'audio' : 'video';
            $preview = true;
        } elseif ((str_starts_with($prefix, 'RIFF') && substr($prefix, 8, 4) === 'WAVE') || str_starts_with($prefix, 'fLaC')
            || str_starts_with($prefix, 'OggS') || str_starts_with($prefix, 'ID3') || ($mime === 'audio/mpeg' && strlen($prefix) > 2 && ord($prefix[0]) === 255)) {
            $mime = match (true) {
                str_starts_with($prefix, 'RIFF') => 'audio/wav', str_starts_with($prefix, 'fLaC') => 'audio/flac',
                str_starts_with($prefix, 'OggS') => 'audio/ogg', default => 'audio/mpeg',
            };
            $kind = 'audio';
            $preview = true;
        } elseif (in_array($extension, ['gltf', 'obj', 'stl', 'ply', 'fbx', 'usdz'], true)) {
            $kind = 'model3d';
            $mime = match ($extension) { 'gltf' => 'model/gltf+json', 'obj' => 'model/obj', 'stl' => 'model/stl', 'ply' => 'application/ply', 'fbx' => 'application/octet-stream', default => 'model/vnd.usdz+zip' };
        } elseif ($extension === 'json' || $declaredMime === 'application/json') {
            $kind = 'data';
            $mime = 'application/json';
        } elseif (str_starts_with($mime, 'text/')) {
            $kind = 'data';
        }
        // No browser-active output is previewable; downloadable unknown binary formats retain original bytes.
        $suffix = match ($mime) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif', 'image/svg+xml' => 'svg',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'audio/mp4' => 'm4a', 'audio/webm' => 'webm', 'audio/wav' => 'wav',
            'audio/mpeg' => 'mp3', 'audio/flac' => 'flac', 'audio/ogg' => 'ogg', 'model/gltf-binary' => 'glb',
            'model/gltf+json' => 'gltf', 'application/json' => 'json', 'application/zip' => 'zip', 'application/pdf' => 'pdf',
            'text/plain' => 'txt', default => preg_match('/^[a-z0-9]{1,12}$/D', $extension) ? $extension : 'bin',
        };
        $safeName = preg_replace('/[\x00-\x1f\x7f\\\\\/<>:"|?*]+/u', '-', basename(str_replace('\\', '/', (string) $name)));
        $safeName = trim(mb_substr((string) $safeName, 0, 180), '. ');
        if ($safeName === '') {
            $safeName = 'output.'.$suffix;
        } elseif (pathinfo($safeName, PATHINFO_EXTENSION) === '') {
            $safeName .= '.'.$suffix;
        }

        return ['name' => $safeName, 'kind' => $kind, 'mime' => $mime, 'bytes' => $bytes, 'previewable' => $preview];
    }

}
