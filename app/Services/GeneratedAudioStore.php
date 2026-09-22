<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Models\AudioJob;
use finfo;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GeneratedAudioStore
{
    private const MAX_BYTES = 128 * 1024 * 1024;

    // The supported Suno music contract returns two tracks; other audio paths return one.
    private const MAX_OUTPUTS = 2;

    public function __construct(private readonly AiProviderEndpoint $endpoint) {}

    /** Persist the entire ordered result before the job can be settled. */
    public function persist(AudioJob $job, array $urls): array
    {
        if ($urls === [] || ! array_is_list($urls) || count($urls) > self::MAX_OUTPUTS) {
            throw new AiProxyException('The audio provider returned an invalid result collection.', 502);
        }
        $outputs = [];
        try {
            foreach ($urls as $index => $url) {
                if (! is_string($url)) {
                    throw new AiProxyException('The audio provider returned an invalid result URL.', 502);
                }
                $outputs[] = $this->persistTrack($job, $url, $index);
            }
        } catch (Throwable $exception) {
            Storage::disk('local')->deleteDirectory(self::directory($job->job_id));
            throw $exception;
        }

        return $outputs;
    }

    private function persistTrack(AudioJob $job, string $url, int $index): array
    {
        if (! GeneratedImageStore::validResultUrl($url)) {
            throw new AiProxyException('The audio provider returned an invalid result URL.', 502);
        }
        $parts = parse_url($url);
        $disk = Storage::disk('local');
        $path = self::path($job->job_id, $index);
        if ($disk->exists($path) && ($metadata = $this->inspect($disk->path($path))) !== null) {
            return ['path' => $path, ...$metadata];
        }
        $directory = dirname($path);
        if (! $disk->exists($directory) && ! $disk->makeDirectory($directory)) {
            throw new AiProxyException('The generated audio could not be saved.', 503);
        }
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.part';
        $resource = fopen($disk->path($temporary), 'x+b');
        if ($resource === false) {
            throw new AiProxyException('The generated audio could not be saved.', 503);
        }
        $sink = Utils::streamFor($resource);
        $written = 0;
        $bounded = FnStream::decorate($sink, [
            'write' => function (string $chunk) use ($sink, &$written): int {
                if ($written + strlen($chunk) > self::MAX_BYTES) {
                    throw new AiProxyException('The generated audio is too large.', 502);
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
            $response = Http::accept('audio/wav, audio/mpeg, audio/flac, audio/ogg')->timeout(180)->connectTimeout(10)
                ->setHandler(static fn ($request, array $options) => $handler($request, [...$options, 'sink' => $bounded]))
                ->withOptions([...$options, 'stream' => false, 'cookies' => false, 'allow_redirects' => false])
                ->get($url);
            if (! $response->successful() || (int) $response->header('Content-Length') > self::MAX_BYTES) {
                throw new AiProxyException('The generated audio could not be downloaded.', 502);
            }
            $body = $response->toPsrResponse()->getBody();
            if ($written === 0) {
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                while (! $body->eof()) {
                    $chunk = $body->read(65536);
                    if ($chunk === '' && ! $body->eof()) {
                        throw new AiProxyException('The generated audio download was interrupted.', 502);
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
                throw new AiProxyException('The audio provider returned invalid or unsupported audio.', 502);
            }
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
            if (! $disk->move($temporary, $path)) {
                throw new AiProxyException('The generated audio could not be saved.', 503);
            }

            return ['path' => $path, ...$metadata];
        } catch (AiProxyException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiProxyException('The generated audio could not be downloaded or saved.', 502);
        } finally {
            $body?->close();
            $bounded?->close();
            $disk->delete($temporary);
        }
    }

    public static function directory(string $jobId): string
    {
        return 'generated/audio/'.hash('sha256', $jobId);
    }

    public static function path(string $jobId, int $index): string
    {
        return self::directory($jobId).'/'.$index.'.audio';
    }

    /** Historical single-track paths remain private without copying their bytes. */
    public static function outputPath(AudioJob $job, int $index): ?string
    {
        $path = $job->outputs[$index]['path'] ?? null;
        if ($index < 0 || ! is_string($path)) {
            return null;
        }

        return $path === self::path($job->job_id, $index)
            || ($index === 0 && $path === self::directory($job->job_id).'/output.audio')
                ? $path : null;
    }

    public static function extensionForMime(string $mime): ?string
    {
        return match ($mime) {
            'audio/wav' => 'wav',
            'audio/mpeg' => 'mp3',
            'audio/flac' => 'flac',
            'audio/ogg' => 'ogg',
            default => null,
        };
    }

    private function inspect(string $path): ?array
    {
        if (! is_file($path) || ($size = filesize($path)) === false || $size < 32 || $size > self::MAX_BYTES) {
            return null;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $header = fread($handle, 64);
            if (! is_string($header)) {
                return null;
            }
            $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            $mime = match (true) {
                in_array($detected, ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'], true)
                    && $this->validWave($handle, $header, $size) => 'audio/wav',
                $detected === 'audio/mpeg' && $this->validMpeg($handle, $header, $size) => 'audio/mpeg',
                in_array($detected, ['audio/flac', 'audio/x-flac'], true)
                    && substr($header, 0, 4) === 'fLaC' && (ord($header[4]) & 0x7F) === 0
                    && substr($header, 5, 3) === "\x00\x00\x22" && $size > 42 => 'audio/flac',
                in_array($detected, ['audio/ogg', 'application/ogg'], true)
                    && substr($header, 0, 5) === "OggS\x00"
                    && (str_contains($header, 'OpusHead') || str_contains($header, "\x01vorbis")) => 'audio/ogg',
                default => null,
            };

            return $mime === null ? null : ['mime_type' => $mime, 'size_bytes' => $size];
        } finally {
            fclose($handle);
        }
    }

    private function validWave($handle, string $header, int $size): bool
    {
        if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE'
            || unpack('Vsize', substr($header, 4, 4))['size'] !== $size - 8) {
            return false;
        }
        $offset = 12;
        $blockAlign = null;
        $dataBytes = null;
        for ($chunks = 0; $offset + 8 <= $size && $chunks < 4096; $chunks++) {
            if (fseek($handle, $offset) !== 0 || strlen($chunk = (string) fread($handle, 8)) !== 8) {
                return false;
            }
            $length = unpack('Vsize', substr($chunk, 4, 4))['size'];
            if ($length > $size - $offset - 8) {
                return false;
            }
            $kind = substr($chunk, 0, 4);
            if ($kind === 'fmt ') {
                if ($length < 16 || strlen($format = (string) fread($handle, 16)) !== 16) {
                    return false;
                }
                $fields = unpack('vencoding/vchannels/Vsample_rate/Vbyte_rate/vblock_align/vbits', $format);
                if (! in_array($fields['encoding'], [1, 3, 65534], true) || $fields['channels'] < 1 || $fields['channels'] > 8
                    || $fields['sample_rate'] < 8000 || $fields['sample_rate'] > 192000
                    || ! in_array($fields['bits'], [8, 16, 24, 32, 64], true)
                    || $fields['block_align'] !== $fields['channels'] * intdiv($fields['bits'], 8)
                    || $fields['byte_rate'] !== $fields['sample_rate'] * $fields['block_align']) {
                    return false;
                }
                $blockAlign = $fields['block_align'];
            } elseif ($kind === 'data') {
                $dataBytes = $length;
            }
            $offset += 8 + $length + ($length % 2);
        }

        return $offset === $size && $blockAlign !== null && $dataBytes !== null && $dataBytes > 0
            && $dataBytes % $blockAlign === 0;
    }

    private function validMpeg($handle, string $header, int $size): bool
    {
        $offset = 0;
        if (substr($header, 0, 3) === 'ID3') {
            $tag = array_values(unpack('C4', substr($header, 6, 4)));
            if (max($tag) > 127) {
                return false;
            }
            $offset = 10 + ($tag[0] << 21) + ($tag[1] << 14) + ($tag[2] << 7) + $tag[3];
            if (ord($header[3]) === 4 && (ord($header[5]) & 0x10) !== 0) {
                $offset += 10;
            }
        }
        if ($offset > $size - 4 || fseek($handle, $offset) !== 0 || strlen($frame = (string) fread($handle, 4)) !== 4) {
            return false;
        }
        $second = ord($frame[1]);
        $third = ord($frame[2]);

        return ord($frame[0]) === 0xFF && ($second & 0xE0) === 0xE0
            && ($second & 0x18) !== 0x08 && ($second & 0x06) !== 0
            && ($third & 0xF0) !== 0 && ($third & 0xF0) !== 0xF0 && ($third & 0x0C) !== 0x0C;
    }
}
