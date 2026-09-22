<?php

namespace Tests\Feature\Media;

use App\Exceptions\AiProxyException;
use App\Models\ThreeDJob;
use App\Services\AiProviderEndpoint;
use App\Services\GeneratedModel3dStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeneratedModel3dStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_private_dns_redirects_and_oversized_bodies_cannot_create_a_managed_original(): void
    {
        $job = new ThreeDJob(['job_id' => 'private-result-boundary']);
        $private = new GeneratedModel3dStore(new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['127.0.0.1'];
            }
        });
        try {
            $private->persist($job, 'https://cdn.example.com/private.glb');
            $this->fail('A private DNS destination must not be downloaded.');
        } catch (AiProxyException) {
            Http::assertNothingSent();
            Storage::disk('local')->assertMissing(GeneratedModel3dStore::path($job->job_id));
        }
        $store = new GeneratedModel3dStore(new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::fake([
            'https://cdn.example.com/redirect.glb' => Http::response('', 302, ['Location' => 'https://elsewhere.invalid/model.glb']),
            'https://cdn.example.com/large.glb' => Http::response('tiny body', 200, ['Content-Length' => 134217729]),
        ]);
        foreach (['redirect', 'large'] as $case) {
            try {
                $store->persist($job, 'https://cdn.example.com/'.$case.'.glb');
                $this->fail('An unsafe result must not become a managed original.');
            } catch (AiProxyException) {
                Storage::disk('local')->assertMissing(GeneratedModel3dStore::path($job->job_id));
            }
        }
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'elsewhere.invalid'));
        $this->assertSame([], Storage::disk('local')->allFiles(dirname(GeneratedModel3dStore::path($job->job_id))));
    }

    public function test_container_chunks_json_and_embedded_resource_ranges_are_verified_before_preview(): void
    {
        $store = app(GeneratedModel3dStore::class);
        $job = new ThreeDJob(['job_id' => 'glb-container-validation']);
        $valid = $this->glb(['asset' => ['version' => '2.0'], 'buffers' => [['byteLength' => 4]]], 'abcd');
        $cases = [
            'wrong_version' => substr_replace($valid, pack('V', 1), 4, 4),
            'truncated_body' => substr($valid, 0, -1),
            'chunk_exceeds_container' => substr_replace($valid, pack('V', 1048576), 12, 4),
            'json_must_be_first' => substr_replace($valid, pack('V', 0x004E4942), 16, 4),
            'invalid_json' => $this->container('{"asset":', 'abcd'),
            'wrong_asset_version' => $this->glb(['asset' => ['version' => '1.0']], null),
            'buffer_outside_bin' => $this->glb(['asset' => ['version' => '2.0'], 'buffers' => [['byteLength' => 8]]], 'abcd'),
            'embedded_buffer_length_mismatch' => $this->glb(['asset' => ['version' => '2.0'],
                'buffers' => [['byteLength' => 8, 'uri' => 'data:application/octet-stream;base64,YWJjZA==']]], null),
            'view_outside_buffer' => $this->glb(['asset' => ['version' => '2.0'], 'buffers' => [['byteLength' => 4]],
                'bufferViews' => [['buffer' => 0, 'byteOffset' => 2, 'byteLength' => 4]]], 'abcd'),
        ];
        foreach ($cases as $name => $bytes) {
            Storage::disk('local')->put(GeneratedModel3dStore::path($job->job_id), $bytes);
            $this->assertNull($store->existing($job), $name);
        }
    }

    private function glb(array $document, ?string $binary): string
    {
        return $this->container(json_encode($document, JSON_THROW_ON_ERROR), $binary);
    }

    private function container(string $json, ?string $binary): string
    {
        $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);
        $body = pack('VV', strlen($json), 0x4E4F534A).$json;
        if ($binary !== null) {
            $binary .= str_repeat("\x00", (4 - strlen($binary) % 4) % 4);
            $body .= pack('VV', strlen($binary), 0x004E4942).$binary;
        }

        return 'glTF'.pack('VV', 2, 12 + strlen($body)).$body;
    }
}
