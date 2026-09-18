<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Models\VideoJob;
use finfo;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class GeneratedVideoStore
{
    private const MAX_BYTES = 512 * 1024 * 1024;

    public function __construct(private readonly AiProviderEndpoint $endpoint) {}

    public function persist(VideoJob $job, string $url): string
    {
        if (! GeneratedImageStore::validResultUrl($url)) {
            throw new AiProxyException('The video provider returned an invalid result URL.', 502);
        }
        $parts = parse_url($url);

        $disk = Storage::disk('local');
        $path = self::path($job->job_id);
        if ($disk->exists($path) && $this->validVideo($disk->path($path))) {
            return '/api/v/'.$job->job_id.'/asset';
        }

        $directory = dirname($path);
        if (! $disk->exists($directory) && ! $disk->makeDirectory($directory)) {
            throw new AiProxyException('The generated video could not be saved.', 503);
        }
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.part';
        $resource = fopen($disk->path($temporary), 'x+b');
        if ($resource === false) {
            throw new AiProxyException('The generated video could not be saved.', 503);
        }
        $sink = Utils::streamFor($resource);
        $written = 0;
        $bounded = FnStream::decorate($sink, [
            'write' => function (string $chunk) use ($sink, &$written): int {
                if ($written + strlen($chunk) > self::MAX_BYTES) {
                    throw new AiProxyException('The generated video is too large.', 502);
                }
                $written += strlen($chunk);

                return $sink->write($chunk);
            },
        ]);
        $body = null;
        try {
            $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            $options = $this->endpoint->requestOptions($origin);
            $handler = new CurlHandler;
            $response = Http::accept('video/mp4')->timeout(180)->connectTimeout(10)
                ->setHandler(static fn ($request, array $options) => $handler($request, [...$options, 'sink' => $bounded]))
                ->withOptions([...$options, 'stream' => false, 'cookies' => false, 'allow_redirects' => false])
                ->get($url);
            if (! $response->successful() || (int) $response->header('Content-Length') > self::MAX_BYTES) {
                throw new AiProxyException('The generated video could not be downloaded.', 502);
            }
            $body = $response->toPsrResponse()->getBody();
            if ($written === 0) {
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                while (! $body->eof()) {
                    $chunk = $body->read(65536);
                    if ($chunk === '' && ! $body->eof()) {
                        throw new AiProxyException('The generated video download was interrupted.', 502);
                    }
                    $bounded->write($chunk);
                }
            }
            $body->close();
            $body = null;
            $bounded->close();
            $bounded = null;
            clearstatcache(true, $disk->path($temporary));
            if (! $this->validVideo($disk->path($temporary))) {
                throw new AiProxyException('The video provider returned an invalid video.', 502);
            }
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
            if (! $disk->move($temporary, $path)) {
                throw new AiProxyException('The generated video could not be saved.', 503);
            }

            return '/api/v/'.$job->job_id.'/asset';
        } catch (AiProxyException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new AiProxyException('The generated video could not be downloaded.', 502);
        } finally {
            $body?->close();
            $bounded?->close();
            $disk->delete($temporary);
        }
    }

    public static function path(string $jobId): string
    {
        return 'generated/videos/'.hash('sha256', $jobId).'.mp4';
    }

    private function validVideo(string $path): bool
    {
        if (! is_file($path) || ($size = filesize($path)) === false || $size < 12 || $size > self::MAX_BYTES) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $header = fread($handle, 12);
        } finally {
            fclose($handle);
        }

        return is_string($header) && substr($header, 4, 4) === 'ftyp'
            && (new finfo(FILEINFO_MIME_TYPE))->file($path) === 'video/mp4';
    }
}
