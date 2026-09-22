<?php

namespace Tests\Feature\Media;

use App\Exceptions\AiProxyException;
use App\Models\AiProviderProfile;
use App\Services\AiProviderEndpoint;
use App\Services\AiProviderTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KinoviReferenceUploadTest extends TestCase
{
    private const API = 'https://kinovi.example.test/api/v1/uploads';

    private const UPLOAD = 'https://storage.example.test/inputs/reference.png?X-Amz-Signature=upload-secret';

    private const MEDIA = 'https://media.example.test/inputs/reference.png';

    private const BYTES = "reference\0bytes";

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_only_confirmed_bytes_are_returned_without_sending_api_credentials_to_storage(): void
    {
        $requests = [];
        Http::fake(function (Request $request, array $options) use (&$requests) {
            $requests[] = [$request->method(), $request->url()];
            if ($request->method() === 'POST' && $request->url() === self::API) {
                $this->assertTrue($request->hasHeader('Authorization', 'Bearer kinovi-private-key'));
                $this->assertSame(['fileName' => 'reference.png', 'contentType' => 'image/png'], $request->data());

                // The provider's confirmUrl is never trusted as a credential destination.
                return Http::response($this->metadata(['confirmUrl' => 'https://untrusted.example.test/steal']));
            }
            if ($request->method() === 'PUT' && $request->url() === self::UPLOAD) {
                $this->assertFalse($request->hasHeader('Authorization'));
                $this->assertFalse($request->hasHeader('x-api-key'));
                $this->assertTrue($request->hasHeader('Content-Type', 'image/png'));
                $this->assertSame(self::BYTES, $request->body());
                $this->assertFalse($options['allow_redirects']);
                $this->assertSame('', $options['proxy']);
                $this->assertFalse($options['cookies']);
                $this->assertSame(['storage.example.test:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);

                return Http::response('', 200);
            }
            if ($request->method() === 'GET' && $request->url() === self::API.'?path=inputs%2Freference.png') {
                $this->assertTrue($request->hasHeader('Authorization', 'Bearer kinovi-private-key'));

                return Http::response($this->confirmation());
            }

            $this->fail('Reference staging contacted an unexpected endpoint.');
        });

        $stream = $this->stream();
        try {
            $url = $this->transport()->uploadKinoviReference($this->provider(), $stream, 'reference.png', 'image/png', strlen(self::BYTES));
            $this->assertSame(self::MEDIA, $url);
            $this->assertIsResource($stream);
            rewind($stream);
            $this->assertSame(self::BYTES, stream_get_contents($stream));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->assertSame([
            ['POST', self::API],
            ['PUT', self::UPLOAD],
            ['GET', self::API.'?path=inputs%2Freference.png'],
        ], $requests);
    }

    #[DataProvider('confirmationMismatches')]
    public function test_confirmation_must_match_the_staged_object(array $replacement): void
    {
        Http::fake([
            self::API.'*' => Http::sequence()->push($this->metadata())->push($this->confirmation($replacement)),
            self::UPLOAD => Http::response('', 200),
        ]);

        $this->assertUploadRejected($this->transport());
        Http::assertSentCount(3);
    }

    public static function confirmationMismatches(): array
    {
        return [
            'another object key' => [['path' => 'inputs/other.png']],
            'another public URL' => [['url' => 'https://media.example.test/inputs/other.png']],
            'truncated bytes' => [['size' => 1]],
            'missing byte count' => [['size' => null]],
            'different MIME' => [['contentType' => 'image/jpeg']],
            'unknown MIME' => [['contentType' => null]],
            'already expired asset' => [['expiresAt' => '2020-01-01T00:00:00Z']],
            'malformed asset expiry' => [['expiresAt' => 'not-a-date']],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function test_unsafe_storage_or_media_destinations_are_rejected_before_upload(array $replacement, array $dns): void
    {
        Http::fake([self::API => Http::response($this->metadata($replacement))]);

        $this->assertUploadRejected($this->transport($dns));
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'POST');
    }

    public static function unsafeTargets(): array
    {
        return [
            'plaintext upload' => [['uploadUrl' => 'http://storage.example.test/reference.png'], []],
            'embedded credentials' => [['uploadUrl' => 'https://user:password@storage.example.test/reference.png'], []],
            'fragment' => [['uploadUrl' => self::UPLOAD.'#secret'], []],
            'private address' => [['uploadUrl' => 'https://127.0.0.1/reference.png'], []],
            'mixed public and private DNS' => [[], ['storage.example.test' => ['93.184.216.34', '10.0.0.1']]],
            'private public-media DNS' => [[], ['media.example.test' => ['192.168.1.1']]],
            'credential-bearing public URL' => [['url' => 'https://user:password@media.example.test/reference.png'], []],
        ];
    }

    #[DataProvider('invalidMetadata')]
    public function test_unusable_upload_metadata_never_sends_asset_bytes(array $replacement): void
    {
        Http::fake([self::API => Http::response($this->metadata($replacement))]);

        $this->assertUploadRejected($this->transport());
        Http::assertSentCount(1);
    }

    public static function invalidMetadata(): array
    {
        return [
            'wrong method' => [['method' => 'POST']],
            'empty storage key' => [['path' => '']],
            'control character in storage key' => [['path' => "inputs/reference.png\n"]],
            'wrong upload MIME' => [['contentType' => 'audio/mpeg']],
            'expired upload' => [['expiresIn' => 0]],
            'malformed upload expiry' => [['expiresIn' => 'later']],
            'expired asset lifetime' => [['assetTtlSeconds' => 0]],
            'stale presigned URL despite positive metadata TTL' => [[
                'uploadUrl' => self::UPLOAD.'&X-Amz-Date=20200101T000000Z&X-Amz-Expires=900',
            ]],
            'malformed signature expiry' => [[
                'uploadUrl' => self::UPLOAD.'&X-Amz-Date=20260923T000000Z&X-Amz-Expires=invalid',
            ]],
        ];
    }

    #[DataProvider('upstreamFailures')]
    public function test_upstream_failure_does_not_retry_or_return_an_unconfirmed_url(string $stage, int $status, int $sent): void
    {
        Http::fake(function (Request $request) use ($stage, $status) {
            if ($request->method() === $stage) {
                return Http::response([
                    'error' => 'kinovi-private-key rejected '.self::UPLOAD,
                ], $status, ['Location' => 'https://untrusted.example.test/redirect']);
            }
            if ($request->method() === 'POST' && $request->url() === self::API) {
                return Http::response($this->metadata());
            }
            if ($request->method() === 'PUT' && $request->url() === self::UPLOAD) {
                return Http::response('', 200);
            }

            $this->fail('A failed upload must not advance to confirmation.');
        });

        $this->assertUploadRejected($this->transport());
        Http::assertSentCount($sent);
    }

    public static function upstreamFailures(): array
    {
        return [
            'staging POST unavailable' => ['POST', 503, 1],
            'PUT denied' => ['PUT', 403, 2],
            'PUT redirect' => ['PUT', 307, 2],
            'confirmation missing' => ['GET', 404, 3],
        ];
    }

    #[DataProvider('loopbackGates')]
    public function test_loopback_staging_requires_both_local_fixture_gates(string $environment, bool $enabled, bool $allowed): void
    {
        $this->app['env'] = $environment;
        config(['media.allow_local_providers' => $enabled]);
        $upload = 'http://127.0.0.1:9000/storage/reference.png?signature=fixture';
        $media = 'http://127.0.0.1:9000/media/reference.png';
        Http::fake([
            self::API.'*' => Http::sequence()
                ->push($this->metadata(['uploadUrl' => $upload, 'url' => $media]))
                ->push($this->confirmation(['url' => $media])),
            $upload => Http::response('', 200),
        ]);

        if (! $allowed) {
            $this->assertUploadRejected($this->transport());
            Http::assertSentCount(1);

            return;
        }
        $stream = $this->stream();
        try {
            $this->assertSame($media, $this->transport()->uploadKinoviReference(
                $this->provider(), $stream, 'reference.png', 'image/png', strlen(self::BYTES),
            ));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        Http::assertSentCount(3);
    }

    public static function loopbackGates(): array
    {
        return [
            'local fixture opted in' => ['local', true, true],
            'local without opt in' => ['local', false, false],
            'production cannot opt in' => ['production', true, false],
            'testing cannot opt in' => ['testing', true, false],
        ];
    }

    private function assertUploadRejected(AiProviderTransport $transport): void
    {
        $stream = $this->stream();
        try {
            $transport->uploadKinoviReference($this->provider(), $stream, 'reference.png', 'image/png', strlen(self::BYTES));
            $this->fail('An unsafe or unconfirmed reference must not be returned.');
        } catch (AiProxyException $exception) {
            $this->assertContains($exception->responseStatus(), [502, 503]);
            foreach (['kinovi-private-key', 'upload-secret', 'example.test', '127.0.0.1'] as $secret) {
                $this->assertStringNotContainsString($secret, $exception->getMessage());
            }
            $this->assertNull($exception->getPrevious());
            $this->assertIsResource($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function metadata(array $replacement = []): array
    {
        return array_replace([
            'uploadUrl' => self::UPLOAD,
            'url' => self::MEDIA,
            'path' => 'inputs/reference.png',
            'method' => 'PUT',
            'contentType' => 'image/png',
            'expiresIn' => 900,
            'confirmUrl' => '/api/v1/uploads?path=inputs%2Freference.png',
            'assetTtlSeconds' => 86400,
        ], $replacement);
    }

    private function confirmation(array $replacement = []): array
    {
        return array_replace([
            'path' => 'inputs/reference.png',
            'url' => self::MEDIA,
            'size' => strlen(self::BYTES),
            'contentType' => 'image/png',
            'uploadedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
            'assetTtlSeconds' => 86400,
        ], $replacement);
    }

    /** @return resource */
    private function stream(): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, self::BYTES);
        rewind($stream);

        return $stream;
    }

    private function provider(): AiProviderProfile
    {
        return new AiProviderProfile([
            'name' => 'Kinovi', 'slug' => 'kinovi-upload', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.example.test/api/v1', 'api_key' => 'kinovi-private-key',
            'is_enabled' => true,
        ]);
    }

    private function transport(array $dns = []): AiProviderTransport
    {
        $endpoint = new class($dns) extends AiProviderEndpoint
        {
            public function __construct(private readonly array $dns) {}

            protected function resolveAddresses(string $host): array
            {
                return $this->dns[$host] ?? ['93.184.216.34'];
            }
        };

        return new AiProviderTransport($endpoint);
    }
}
