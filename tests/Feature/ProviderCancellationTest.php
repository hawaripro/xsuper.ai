<?php

namespace Tests\Feature;

use App\Exceptions\AiProxyException;
use App\Services\CancellableProviderStream;
use App\Services\ProviderSseStream;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProviderCancellationTest extends TestCase
{
    public function test_stop_interrupts_a_provider_that_has_not_sent_response_headers(): void
    {
        $this->assertSilentTransferStops('headers');
    }

    public function test_stop_interrupts_silent_body_transfer_and_retains_only_observed_partial(): void
    {
        $this->assertSilentTransferStops('body');
    }

    public function test_informational_headers_do_not_open_a_final_response_body(): void
    {
        $this->assertSilentTransferStops('continue');
    }

    public function test_timeout_bounds_silence_so_a_long_active_stream_is_received_whole(): void
    {
        // Six chunks 0.4 s apart outlast the 1 s timeout in total, but never go silent for 1 s.
        [$received, $error] = $this->streamFrom('active', 1);
        $this->assertNull($error);
        $this->assertSame(['1', '2', '3', '4', '5', '6'], $received);
    }

    public function test_a_stream_that_goes_silent_still_times_out(): void
    {
        $started = microtime(true);
        [$received, $error] = $this->streamFrom('silent', 1);
        $this->assertSame(['1'], $received);
        $this->assertSame(504, $error?->responseStatus());
        $this->assertLessThan(4, microtime(true) - $started);
    }

    /** @return array{0: list<string>, 1: ?AiProxyException} */
    private function streamFrom(string $mode, float $timeout): array
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        $port = (int) substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1);
        fclose($reservation);
        $serverCode = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:'.$argv[1], $error, $message);
if (!$server) { exit(2); }
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$client = stream_socket_accept($server, 10);
if (!$client) { exit(3); }
while (($line = fgets($client)) !== false && trim($line) !== '') {}
fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n");
$chunk = static fn (string $text, string $finish): string => 'data: {"choices":[{"index":0,"delta":{"content":"'.$text.'"},"finish_reason":'.$finish.'}]}'."\n\n";
for ($index = 1; $index <= ($argv[2] === 'active' ? 6 : 1); $index++) {
    usleep(400000);
    fwrite($client, $chunk((string) $index, $argv[2] === 'active' && $index === 6 ? '"stop"' : 'null'));
    fflush($client);
}
if ($argv[2] === 'active') {
    fwrite($client, "data: [DONE]\n\n");
} else {
    sleep(10);
}
fclose($client);
fclose($server);
PHP;
        $server = new Process([PHP_BINARY, '-r', $serverCode, (string) $port, $mode]);
        $server->setTimeout(20);
        $body = null;
        $received = [];
        try {
            $server->start();
            $this->assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY')));
            try {
                $response = CancellableProviderStream::send(new Request('POST', 'http://127.0.0.1:'.$port.'/', ['Content-Length' => '0']),
                    ['timeout' => $timeout, 'connect_timeout' => 2, 'allow_redirects' => false, 'proxy' => '', 'curl' => [CURLOPT_PROXY => '']],
                    static fn (): bool => false);
                $body = $response->getBody();
                foreach (ProviderSseStream::openAi($body) as $chunk) {
                    $received[] = $chunk['choices'][0]['delta']['content'];
                }

                return [$received, null];
            } catch (AiProxyException $exception) {
                return [$received, $exception];
            }
        } finally {
            $body?->close();
            $server->stop(0.1);
        }
    }

    private function assertSilentTransferStops(string $phase): void
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        $port = (int) substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1);
        fclose($reservation);
        $serverCode = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:'.$argv[1], $error, $message);
if (!$server) { exit(2); }
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$client = stream_socket_accept($server, 10);
if (!$client) { exit(3); }
while (($line = fgets($client)) !== false && trim($line) !== '') {}
if ($argv[2] === 'body') {
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n");
    fwrite($client, "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"partial\"},\"finish_reason\":null}]}\n\n");
    fflush($client);
} elseif ($argv[2] === 'continue') {
    fwrite($client, "HTTP/1.1 100 Continue\r\n\r\n");
    fflush($client);
}
sleep(10);
fclose($client);
fclose($server);
PHP;
        $server = new Process([PHP_BINARY, '-r', $serverCode, (string) $port, $phase]);
        $server->setTimeout(15);
        $body = null;
        $received = [];
        $openedResponse = false;
        try {
            $server->start();
            $this->assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY')));
            $started = microtime(true);
            $cancelAt = $started + 0.5;
            try {
                $response = CancellableProviderStream::send(new Request('POST', 'http://127.0.0.1:'.$port.'/', ['Content-Length' => '0']),
                    ['timeout' => 5, 'connect_timeout' => 2, 'allow_redirects' => false, 'proxy' => '', 'curl' => [CURLOPT_PROXY => '']],
                    static fn (): bool => microtime(true) >= $cancelAt);
                $openedResponse = true;
                $body = $response->getBody();
                foreach (ProviderSseStream::openAi($body) as $chunk) {
                    $received[] = $chunk['choices'][0]['delta']['content'];
                }
                $this->fail('A silent transfer must be interrupted, not finish successfully.');
            } catch (AiProxyException $exception) {
                $this->assertSame(499, $exception->responseStatus());
            }
            $this->assertLessThan(2.5, microtime(true) - $started);
            $this->assertSame($phase === 'body' ? ['partial'] : [], $received);
            $this->assertSame($phase === 'body', $openedResponse);
        } finally {
            $body?->close();
            $server->stop(0.1);
        }
    }
}
