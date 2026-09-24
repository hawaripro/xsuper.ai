<?php

namespace Tests\Feature\Media;

use App\Exceptions\AiProxyException;
use App\Media\Adapters\FalAdapter;
use App\Media\Adapters\OpenAiAdapter;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;
use App\Media\MediaCapability;
use App\Media\MediaReferenceStager;
use App\Media\Enums\MediaState;
use App\Media\Enums\SubmitOutcome;
use App\Models\AiProviderProfile;
use App\Services\AiProviderEndpoint;
use App\Services\AiProviderTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FalQueueTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_queue_poll_uses_application_root_and_preserves_nonfile_results(): void
    {
        $result = ['meshes' => [['url' => 'https://fal.media/mesh.glb', 'content_type' => 'model/gltf-binary']], 'scores' => [0.9, 0.1], 'caption' => 'A chair'];
        Http::fake([
            'https://queue.fal.run/fal-ai/example/image-to-3d' => Http::response([
                'request_id' => 'request-1', 'status_url' => 'https://attacker.test/steal', 'response_url' => 'https://attacker.test/result',
            ]),
            'https://queue.fal.run/fal-ai/example/requests/request-1/status*' => Http::response(['status' => 'COMPLETED']),
            'https://queue.fal.run/fal-ai/example/requests/request-1' => Http::response($result),
        ]);
        $transport = $this->transport();
        $adapter = new FalAdapter($transport, new MediaReferenceStager($transport));
        $bindings = ['adapter' => 'fal_schema_v2', 'endpoint' => 'fal-ai/example/image-to-3d', 'queue_root' => 'fal-ai/example'];
        $submitted = $adapter->submit($this->provider(), ['mode' => 'queue', 'endpoint' => $bindings['endpoint'], 'bindings' => $bindings, 'payload' => ['nested' => ['values' => [1, 2]]], 'assets' => []]);
        $this->assertSame(SubmitOutcome::Accepted, $submitted->outcome);
        $status = $adapter->pollStatus($this->provider(), $submitted->taskId, $bindings);
        $this->assertSame(MediaState::Completed, $status->state);
        $this->assertSame($result, $status->resultData);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'attacker.test'));
    }

    public function test_namespaced_queue_root_and_cancel_acknowledgement_are_not_a_refund_confirmation(): void
    {
        Http::fake(['https://queue.fal.run/workflows/member/workflow/requests/request-2/cancel' => Http::response(['status' => 'CANCELLATION_REQUESTED'])]);
        $transport = $this->transport();
        $adapter = new FalAdapter($transport, new MediaReferenceStager($transport));
        $result = $adapter->cancel($this->provider(), 'request-2', ['adapter' => 'fal_schema_v2', 'endpoint' => 'workflows/member/workflow/run', 'queue_root' => 'workflows/member/workflow']);
        $this->assertTrue($result['requested']);
        $this->assertFalse($result['confirmed']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_successful_submit_without_a_request_id_remains_uncertain_and_is_not_retried(): void
    {
        Http::fake(['https://queue.fal.run/fal-ai/example' => Http::response(['status' => 'IN_QUEUE'])]);
        $transport = $this->transport();
        $adapter = new FalAdapter($transport, new MediaReferenceStager($transport));
        $result = $adapter->submit($this->provider(), ['mode' => 'queue', 'endpoint' => 'fal-ai/example', 'bindings' => ['queue_root' => 'fal-ai/example'], 'payload' => [], 'assets' => []]);
        $this->assertSame(SubmitOutcome::Uncertain, $result->outcome);
        Http::assertSentCount(1);
    }
    public function test_file_staging_streams_bytes_without_api_credentials_on_upload(): void
    {
        Http::fake(function (Request $request, array $options) {
            if (str_starts_with($request->url(), 'https://rest.fal.ai/storage/upload/initiate?')) {
                $this->assertTrue($request->hasHeader('Authorization', 'Key fal-private-key'));
                $this->assertSame(['expiration_duration_seconds' => 86400], json_decode($request->header('X-Fal-Object-Lifecycle')[0], true));

                return Http::response(['upload_url' => 'https://v3.fal.media/files/object?token=signed', 'file_url' => 'https://v3.fal.media/files/object']);
            }
            $this->assertSame('PUT', $request->method());
            $this->assertSame('https://v3.fal.media/files/object?token=signed', $request->url());
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertFalse($request->hasHeader('x-api-key'));
            $this->assertSame("mesh\0bytes", $request->body());
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(['v3.fal.media:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);

            return Http::response('', 200);
        });
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "mesh\0bytes");
        rewind($stream);
        try {
            $url = $this->transport()->uploadFalReference($this->provider(), $stream, 'chair.glb', 'model/gltf-binary', strlen("mesh\0bytes"));
            $this->assertSame('https://v3.fal.media/files/object', $url);
            $this->assertIsResource($stream);
        } finally {
            fclose($stream);
        }
    }

    public function test_multipart_staging_preserves_part_order_and_completes_without_api_credentials(): void
    {
        $size = 90 * 1024 * 1024 + 17;
        $parts = [];
        Http::fake(function (Request $request) use (&$parts, $size) {
            if (str_starts_with($request->url(), 'https://rest.fal.ai/storage/upload/initiate-multipart?')) {
                return Http::response(['upload_url' => 'https://v3.fal.media/files/upload?token=signed', 'file_url' => 'https://v3.fal.media/files/result']);
            }
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertFalse($request->hasHeader('x-api-key'));
            if ($request->method() === 'PUT') {
                $number = count($parts) + 1;
                $this->assertSame('https://v3.fal.media/files/upload/'.$number.'?token=signed', $request->url());
                $this->assertSame(min(10 * 1024 * 1024, $size - ($number - 1) * 10 * 1024 * 1024), strlen($request->body()));
                $parts[] = ['partNumber' => $number, 'etag' => 'part-'.$number];

                return Http::response(['etag' => 'part-'.$number]);
            }
            $this->assertSame('https://v3.fal.media/files/upload/complete?token=signed', $request->url());
            $this->assertSame($parts, $request['parts']);

            return Http::response('', 200);
        });
        $stream = tmpfile();
        ftruncate($stream, $size);
        try {
            $this->assertSame('https://v3.fal.media/files/result',
                $this->transport()->uploadFalReference($this->provider(), $stream, 'weights.bin', 'application/octet-stream', $size));
        } finally {
            fclose($stream);
        }
        $this->assertCount(10, $parts);
        Http::assertSentCount(12);
    }

    public function test_native_openai_preserves_base64_images_and_snapshot_route_without_duplicate_submission(): void
    {
        $result = ['data' => [['b64_json' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR42mP4DwABAQEAG7buVgAAAABJRU5ErkJggg==', 'revised_prompt' => 'A pixel']]];
        Http::fake(['https://compatible.test/v1/native/images' => Http::response($result)]);
        $adapter = new OpenAiAdapter($this->transport());
        $request = $adapter->buildRequest(new MediaCapability('native/image', MediaOperation::TextToImage, OutputKind::Image, 1),
            ['inputs' => ['prompt' => 'A pixel'], 'params' => [], 'generation_config' => ['image_path' => 'native/images']], 'native-image');
        $provider = new AiProviderProfile(['protocol' => 'openai', 'base_url' => 'https://compatible.test/v1', 'api_key' => 'private']);
        $submitted = $adapter->submit($provider, $request);
        $this->assertSame(SubmitOutcome::Immediate, $submitted->outcome);
        $this->assertSame($result, $submitted->resultData);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['model'] === 'native-image'
            && ! array_key_exists('_path', $request->data()));
    }

    public function test_reviewed_direct_binding_does_not_invent_a_queue_job_or_drop_structured_content(): void
    {
        $endpoint = 'perceptron/isaac-01/openai/v1/chat/completions';
        $result = ['choices' => [['message' => ['role' => 'assistant', 'content' => 'A grounded answer']]], 'boxes' => [[1, 2, 3, 4]], 'error' => ['score' => 0.05]];
        Http::fake(['https://fal.run/'.$endpoint => Http::response($result)]);
        $transport = $this->transport();
        $adapter = new FalAdapter($transport, new MediaReferenceStager($transport));
        $capability = new MediaCapability('fal/isaac', MediaOperation::Vision, OutputKind::Data, 2,
            providerBindings: ['adapter' => 'fal_schema_v2', 'transport' => 'direct', 'endpoint' => $endpoint, 'constants' => ['model' => 'perceptron', 'stream' => false]]);
        $request = $adapter->buildRequest($capability, ['inputs' => ['messages' => [['role' => 'user', 'content' => 'Describe']]], 'params' => [], 'asset_paths' => []], $endpoint);
        $submitted = $adapter->submit($this->provider(), $request);
        $this->assertSame(SubmitOutcome::Immediate, $submitted->outcome);
        $this->assertSame($result, $submitted->resultData);
        $this->assertNull($submitted->taskId);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fal.run/'.$endpoint
            && $request['model'] === 'perceptron' && $request['stream'] === false && ! isset($request['extra_body']));
    }

    public function test_wma_session_negotiation_is_fixed_origin_and_not_retried_when_acceptance_is_unknown(): void
    {
        Http::fake(['https://wma.fal.run/session' => Http::sequence()->push([], 503)->push(['session_id' => 'would-duplicate'])]);
        try {
            $this->transport()->postFalRealtime($this->provider(), '/session', [
                'app_id' => 'minimax/h3-max/director', 'sdp' => "v=0\r\n", 'type' => 'offer',
            ]);
            $this->fail('Unknown session acceptance must not be retried.');
        } catch (AiProxyException $error) {
            $this->assertSame(503, $error->responseStatus());
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://wma.fal.run/session'
            && $request->hasHeader('Authorization', 'Key fal-private-key')
            && $request['app_id'] === 'minimax/h3-max/director');
    }

    private function provider(): AiProviderProfile
    {
        return new AiProviderProfile(['protocol' => 'fal', 'base_url' => 'https://fal.run', 'api_key' => 'fal-private-key']);
    }

    private function transport(): AiProviderTransport
    {
        $endpoint = new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        };

        return new AiProviderTransport($endpoint);
    }
}
