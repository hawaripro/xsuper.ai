<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Models\ImageJob;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class GeneratedImageStore
{
    private const MAX_BYTES = 30 * 1024 * 1024;

    public function __construct(private readonly AiProviderEndpoint $endpoint) {}

    public function persist(ImageJob $job, array $items, ?\Closure $heartbeat = null): array
    {
        if (! array_is_list($items) || $items === [] || count($items) > 10
            || preg_match('/^[A-Za-z0-9_-]+$/D', (string) $job->job_id) !== 1) {
            throw new AiProxyException('The provider returned an invalid image collection.', 502);
        }
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new AiProxyException('The provider returned an invalid image collection.', 502);
            }
        }
        $urls = [];
        $paths = [];
        try {
            foreach ($items as $item) {
                $index = count($paths);
                $heartbeat?->__invoke();
                if (is_string($item['b64_json'] ?? null)) {
                    $encoded = $item['b64_json'];
                    if (strlen($encoded) > (int) ceil(self::MAX_BYTES * 4 / 3) + 4) {
                        throw new AiProxyException('The generated image is too large.', 502);
                    }
                    $bytes = base64_decode($encoded, true);
                } elseif (is_string($item['url'] ?? null) && self::validResultUrl($item['url'])) {
                    $bytes = $this->download($item['url']);
                } else {
                    throw new AiProxyException('The provider returned an invalid image.', 502);
                }
                $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
                $mime = $info['mime'] ?? null;
                $extension = match ($mime) {
                    'image/png' => 'png',
                    'image/jpeg' => 'jpg',
                    'image/webp' => 'webp',
                    default => null,
                };
                if ($bytes === false || $extension === null || strlen($bytes) > self::MAX_BYTES) {
                    throw new AiProxyException('The provider returned an invalid image.', 502);
                }
                $path = 'generated/images/'.$job->job_id.'/'.$index.'.'.$extension;
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw new AiProxyException('The generated image could not be saved.', 503);
                }
                $paths[(string) $index] = ['path' => $path, 'mime' => $mime];
                $urls[] = '/api/images/'.$job->job_id.'/assets/'.$index;
            }
        } catch (\Throwable $exception) {
            foreach ($paths as $asset) {
                Storage::disk('local')->delete($asset['path']);
            }
            throw $exception;
        }
        $job->asset_paths = $paths;

        return $urls;
    }

    /** Only server-owned canonical output paths may be served, counted or deleted. */
    public static function outputs(ImageJob $job): array
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', (string) $job->job_id) !== 1) {
            return [];
        }
        $outputs = [];
        foreach ($job->asset_paths ?? [] as $index => $asset) {
            if (! is_int($index) || $index < 0 || ! is_array($asset)) {
                continue;
            }
            $extension = match ($asset['mime'] ?? null) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => null,
            };
            if ($extension !== null && ($asset['path'] ?? null) === 'generated/images/'.$job->job_id.'/'.$index.'.'.$extension) {
                $outputs[$index] = ['path' => $asset['path'], 'mime' => $asset['mime']];
            }
        }

        return $outputs;
    }

    public static function discard(ImageJob $job): void
    {
        foreach (self::outputs($job) as $asset) {
            Storage::disk('local')->delete($asset['path']);
        }
    }

    private function download(string $url): string
    {
        $parts = parse_url($url);
        $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $sink = Utils::streamFor(fopen('php://temp/maxmemory:2097152', 'w+b'));
        $written = 0;
        $bounded = FnStream::decorate($sink, [
            'write' => function (string $chunk) use ($sink, &$written): int {
                if ($written + strlen($chunk) > self::MAX_BYTES) {
                    throw new AiProxyException('The generated image is too large.', 502);
                }
                $written += strlen($chunk);

                return $sink->write($chunk);
            },
        ]);
        $body = null;
        try {
            $options = $this->endpoint->requestOptions($origin);
            // Guzzle's default streaming handler ignores CURLOPT_RESOLVE. Force
            // cURL with a bounded sink so DNS pinning applies to the asset too.
            $handler = new CurlHandler;
            $response = Http::accept('image/png,image/jpeg,image/webp')->timeout(60)->connectTimeout(10)
                ->setHandler(static fn ($request, array $options) => $handler($request, [...$options, 'sink' => $bounded]))
                ->withOptions([...$options, 'stream' => false, 'cookies' => false, 'allow_redirects' => false])
                ->get($url);
            if (! $response->successful() || (int) $response->header('Content-Length') > self::MAX_BYTES) {
                throw new AiProxyException('The generated image could not be downloaded.', 502);
            }
            $body = $response->toPsrResponse()->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $bytes = '';
            while (! $body->eof()) {
                $chunk = $body->read(min(65536, self::MAX_BYTES + 1 - strlen($bytes)));
                if ($chunk === '' && ! $body->eof()) {
                    throw new AiProxyException('The generated image download was interrupted.', 502);
                }
                $bytes .= $chunk;
                if (strlen($bytes) > self::MAX_BYTES) {
                    throw new AiProxyException('The generated image is too large.', 502);
                }
            }

            return $bytes;
        } catch (\Throwable) {
            throw new AiProxyException('The generated image could not be downloaded.', 502);
        } finally {
            $body?->close();
            $bounded->close();
        }
    }

    public static function validResultUrl(string $url): bool
    {
        if (strlen($url) > 8192 || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($url))) {
            return false;
        }
        if (self::loopbackResultAllowed($url)) {
            return true;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $host = trim($parts['host'] ?? '', '[]');
        if (str_ends_with($host, '.')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && str_contains($host, '.')
            && preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $host) !== 1
            && preg_match('/(?:^|\.)(?:localhost|localdomain|local|internal|intranet|lan|home|onion)$/i', rtrim($host, '.')) !== 1;
    }

    // Accept a loopback result URL only under the double-gated local dev affordance
    // (APP_ENV=local AND media.allow_local_providers); production keeps the strict guard above.
    private static function loopbackResultAllowed(string $url): bool
    {
        if (! (app()->environment('local') && (bool) config('media.allow_local_providers', false))) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        $host = strtolower(trim($parts['host'], '[]'));

        return $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
    }
}
