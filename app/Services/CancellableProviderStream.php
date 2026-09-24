<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/** cURL multi keeps DNS/TLS pinning and allows stops while headers or body bytes are silent. */
final class CancellableProviderStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private ?EasyHandle $easy = null;

    private ?\CurlMultiHandle $multi = null;

    private CurlFactory $factory;

    private bool $done = false;

    private bool $closed = false;

    private int $error = 0;

    private int $position = 0;

    private int $readOffset = 0;

    private float $deadline;

    /** Seconds a transfer may stay silent; any received byte restarts it (the socket read timeout the streamed path had). */
    private float $idle;

    private \Closure $isCancelled;

    private function __construct(RequestInterface $request, array $options, callable $isCancelled)
    {
        $this->isCancelled = \Closure::fromCallable($isCancelled);
        $this->assertActive();
        if (! extension_loaded('curl')) {
            throw new AiProxyException('Interruptible provider transport is unavailable.', 503);
        }
        $this->idle = max(1, (float) ($options['timeout'] ?? 120));
        $this->deadline = microtime(true) + $this->idle;
        $this->stream = new Stream(fopen('php://temp/maxmemory:2097152', 'w+b'));
        $sink = FnStream::decorate($this->stream, [
            'write' => function (string $bytes): int {
                $this->deadline = microtime(true) + $this->idle;
                $this->stream->seek(0, SEEK_END);

                return $this->stream->write($bytes);
            },
        ]);
        $this->factory = new CurlFactory(0);
        // cURL's 'timeout' caps the whole transfer; a long answer that keeps streaming must not be cut off.
        unset($options['timeout']);
        $this->easy = $this->factory->create($request, [...$options, 'stream' => false, 'sink' => $sink]);
        $this->multi = curl_multi_init();
        curl_multi_add_handle($this->multi, $this->easy->handle);
    }

    public static function send(RequestInterface $request, array $options, callable $isCancelled): ResponseInterface
    {
        $body = new self($request, $options, $isCancelled);
        try {
            while (($body->easy->response?->getStatusCode() ?? 0) < 200 && ! $body->done) {
                $body->pump();
            }
            if (($body->easy->response?->getStatusCode() ?? 0) < 200) {
                throw new AiProxyException('The AI provider is unavailable.', 503);
            }

            return $body->easy->response->withBody($body);
        } catch (Throwable $error) {
            $body->close();
            throw $error;
        }
    }

    private function assertActive(): void
    {
        if (($this->isCancelled)()) {
            $this->close();
            throw new AiProxyException('The AI request was stopped.', 499);
        }
    }

    private function pump(): void
    {
        $this->assertActive();
        if ($this->closed || $this->done) {
            return;
        }
        if (microtime(true) >= $this->deadline) {
            throw new AiProxyException('The AI provider stream timed out.', 504);
        }
        do {
            $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        if ($status !== CURLM_OK) {
            throw new AiProxyException('The AI provider stream failed.', 502);
        }
        while ($info = curl_multi_info_read($this->multi)) {
            if ($info['handle'] === $this->easy->handle) {
                $this->error = $info['result'];
                $this->done = true;
            }
        }
        $this->assertActive();
        if (! $this->done && $this->available() === 0) {
            // A short select also covers providers that send no bytes at all. No blocking fread.
            if (curl_multi_select($this->multi, 0.1) === -1) {
                usleep(10000);
            }
        }
    }

    private function available(): int
    {
        return max(0, (int) $this->stream->getSize() - $this->readOffset);
    }

    public function read($length): string
    {
        $this->assertActive();
        if ($length < 0 || $this->closed) {
            throw new \RuntimeException('The provider response stream is not readable.');
        }
        if ($length === 0) {
            return '';
        }
        while ($this->available() === 0 && ! $this->done) {
            $this->pump();
        }
        if ($this->available() === 0 && $this->error !== CURLE_OK) {
            throw new AiProxyException('The AI provider stream was interrupted.', 502);
        }
        $this->stream->seek($this->readOffset);
        $bytes = $this->stream->read(min($length, $this->available()));
        $this->readOffset += strlen($bytes);
        $this->position += strlen($bytes);

        return $bytes;
    }

    public function eof(): bool
    {
        $this->assertActive();
        // A transfer error is surfaced by read(), never silently converted into clean EOF.
        if ($this->done && $this->error === CURLE_OK && $this->available() === 0) {
            $this->close();
        }

        return $this->closed;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new \RuntimeException('The provider response stream cannot be rewound.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('The provider response stream is read-only.');
    }

    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->multi !== null && $this->easy !== null) {
            curl_multi_remove_handle($this->multi, $this->easy->handle);
            curl_multi_close($this->multi);
            $this->factory->release($this->easy);
            $this->multi = null;
            $this->easy = null;
        }
        if (isset($this->stream)) {
            $this->stream->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
