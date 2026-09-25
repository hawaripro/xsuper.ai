<?php

namespace Tests\Feature\Media;

use App\Exceptions\AiProviderRequestRejected;
use App\Exceptions\AiProxyException;
use App\Models\AiProviderProfile;
use App\Services\AiProviderEndpoint;
use App\Services\AiProviderTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RunwareTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_tasks_are_posted_once_as_a_bearer_authenticated_json_array_to_the_pinned_host(): void
    {
        $task = $this->task();
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(['api.runware.ai:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['allow_redirects']);

            return Http::response([
                // A lone object is one item; the singular `error` form joins the plural list.
                'data' => ['taskType' => 'imageInference', 'taskUUID' => $request->data()[0]['taskUUID']],
                'errors' => [['code' => 'invalidSteps', 'taskUUID' => 'another-task']],
                'error' => ['code' => 'timeoutProvider', 'message' => 'The external provider did not respond.'],
            ]);
        });

        $result = $this->transport()->runwareTasks($this->provider(), [$task]);

        $this->assertSame([['taskType' => 'imageInference', 'taskUUID' => $task['taskUUID']]], $result['data']);
        $this->assertSame(['invalidSteps', 'timeoutProvider'], array_column($result['errors'], 'code'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.runware.ai/v1'
            && $request->hasHeader('Authorization', 'Bearer runware-private-key')
            && str_starts_with($request->body(), '[') && json_decode($request->body(), true) === [$task]);
    }

    /** @return array<string, array{int, ?string}> */
    public static function rejections(): array
    {
        return [
            'insufficient balance' => [402, 'insufficientCredits'],
            'validation' => [400, 'invalidPositivePrompt'],
            'invalid key' => [401, 'invalidApiKey'],
            'forbidden' => [403, null],
        ];
    }

    #[DataProvider('rejections')]
    public function test_client_errors_are_rejections_that_carry_the_runware_code(int $status, ?string $code): void
    {
        Http::fake(['https://api.runware.ai/v1' => Http::response(
            $code === null ? 'Forbidden' : ['errors' => [['code' => $code, 'message' => 'Rejected at https://api.runware.ai with a secret']]], $status)]);
        try {
            $this->transport()->runwareTasks($this->provider(), [$this->task()]);
            $this->fail('A 4xx response must be a rejection.');
        } catch (AiProviderRequestRejected $error) {
            $this->assertSame([$status, $code], [$error->upstreamStatus, $error->providerCode]);
            $this->assertStringNotContainsString('secret', $error->getMessage());
        }
        Http::assertSentCount(1);
    }

    /** @return array<string, array{\Closure(Request): mixed}> */
    public static function unknownOutcomes(): array
    {
        return [
            'server error' => [fn () => Http::response(['errors' => [['code' => 'serverError']]], 503)],
            'gateway timeout' => [fn () => Http::response('', 504)],
            'rate limited' => [fn () => Http::response('', 429, ['Retry-After' => '0'])],
            'request timeout' => [fn () => Http::response('', 408)],
            'conflict' => [fn () => Http::response('', 409)],
            'network failure' => [fn (Request $request) => Http::failedConnection('Operation timed out')($request)],
        ];
    }

    #[DataProvider('unknownOutcomes')]
    public function test_unknown_outcomes_are_never_rejections_and_a_paid_post_is_never_retried(\Closure $first): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls, $first) {
            // A retry would receive an acknowledgement, which is exactly the duplicate paid request to avoid.
            return ++$calls === 1 ? $first($request) : Http::response(['data' => [['taskType' => 'imageInference']]]);
        });
        try {
            $this->transport()->runwareTasks($this->provider(), [$this->task()]);
            $this->fail('An unknown outcome must raise.');
        } catch (AiProviderRequestRejected) {
            $this->fail('An unknown outcome is not a rejection.');
        } catch (AiProxyException $error) {
            $this->assertContains($error->responseStatus(), [502, 503]);
        }
        $this->assertSame(1, $calls);
    }

    public function test_invalid_tasks_and_responses_are_refused(): void
    {
        foreach ([[], [['taskType' => 'imageInference']], [['taskType' => 'image Inference', 'taskUUID' => (string) Str::uuid()]]] as $tasks) {
            try {
                $this->transport()->runwareTasks($this->provider(), $tasks);
                $this->fail('An invalid task list must not be sent.');
            } catch (AiProxyException $error) {
                $this->assertSame(422, $error->responseStatus());
            }
        }
        Http::assertNothingSent();

        Http::fake(['https://api.runware.ai/v1' => Http::response([['taskType' => 'imageInference']])]);
        $this->expectExceptionObject(new AiProxyException('The Runware provider returned an invalid response.', 502));
        $this->transport()->runwareTasks($this->provider(), [$this->task()]);
    }

    public function test_the_connection_is_pinned_to_the_official_runware_endpoint(): void
    {
        $endpoint = new AiProviderEndpoint;
        $this->assertSame('https://api.runware.ai/v1', $endpoint->normalize('https://api.runware.ai', 'runware'));
        $this->assertSame('https://api.runware.ai/v1', $endpoint->normalize('https://api.runware.ai/v1/', 'runware'));
        foreach (['https://api.runware.ai/v2', 'https://evil.example.test/v1', 'http://api.runware.ai/v1', 'https://api.runware.ai:8443/v1'] as $url) {
            try {
                $endpoint->normalize($url, 'runware');
                $this->fail($url.' must be refused for Runware.');
            } catch (InvalidArgumentException) {
            }
        }
        // A stored off-host URL (e.g. saved before this validation existed) never receives the key.
        $provider = new AiProviderProfile(['protocol' => 'runware', 'base_url' => 'https://evil.example.test/v1', 'api_key' => 'runware-private-key']);
        try {
            $this->transport()->runwareTasks($provider, [$this->task()]);
            $this->fail('An off-host Runware connection must not be used.');
        } catch (AiProxyException $error) {
            $this->assertSame(503, $error->responseStatus());
        }
        Http::assertNothingSent();
    }

    public function test_a_loopback_mock_is_reachable_only_behind_the_local_provider_gate(): void
    {
        $provider = new AiProviderProfile(['protocol' => 'runware', 'base_url' => 'http://127.0.0.1:8124/runware/v1', 'api_key' => 'mock-runware-key']);
        config(['media.allow_local_providers' => true]);
        try {
            $this->transport()->runwareTasks($provider, [$this->task()]);
            $this->fail('The testing environment must not open the local gate.');
        } catch (AiProxyException $error) {
            $this->assertSame(503, $error->responseStatus());
        }
        Http::assertNothingSent();

        $this->app['env'] = 'local';
        Http::fake(['http://127.0.0.1:8124/runware/v1' => Http::response(['data' => []])]);
        $this->assertSame(['data' => [], 'errors' => []], $this->transport()->runwareTasks($provider, [$this->task()]));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8124/runware/v1');
    }

    public function test_account_details_are_reduced_to_balance_and_usage(): void
    {
        Http::fake(function (Request $request) {
            $task = $request->data()[0];
            $this->assertSame(['taskType' => 'accountManagement', 'operation' => 'getDetails'], array_diff_key($task, ['taskUUID' => true]));

            return Http::response(['data' => [[
                'taskType' => 'accountManagement', 'taskUUID' => $task['taskUUID'], 'organizationUUID' => 'org-private', 'organizationName' => 'Private Org',
                'balance' => ['amount' => 12.34, 'freeBalance' => 1, 'currency' => 'USD'],
                'team' => [['email' => 'owner@private.test']], 'apiKeys' => [['apiKey' => 'rw-****-private']],
                'usage' => ['today' => ['credits' => 0.5, 'requests' => 3], 'last7Days' => ['credits' => 2.25, 'requests' => 11]],
            ]]]);
        });

        $account = $this->transport()->runwareAccount($this->provider());

        $this->assertSame(['balance' => 12.34, 'free_balance' => 1.0, 'currency' => 'USD', 'usage' => [
            'today' => ['credits' => 0.5, 'requests' => 3], 'last_7_days' => ['credits' => 2.25, 'requests' => 11],
            'last_30_days' => ['credits' => null, 'requests' => null],
        ]], $account);
    }

    public function test_reference_upload_returns_the_media_uuid_and_only_accepts_data_or_links(): void
    {
        $media = (string) Str::uuid();
        Http::fake(fn (Request $request) => Http::response(['data' => [['taskType' => 'mediaStorage', 'taskUUID' => $request->data()[0]['taskUUID'],
            'operation' => 'upload', 'mediaUUID' => $media, 'mediaURL' => 'https://mm.runware.ai/media-storage/ws/2/id/'.$media.'.png']]]));

        $this->assertSame($media, $this->transport()->uploadRunwareReference($this->provider(), 'data:image/png;base64,iVBORw0KGgo='));
        Http::assertSent(fn (Request $request): bool => $request->data()[0]['operation'] === 'upload'
            && $request->data()[0]['media'] === 'data:image/png;base64,iVBORw0KGgo=');
        try {
            $this->transport()->uploadRunwareReference($this->provider(), '/etc/passwd');
            $this->fail('A local path is never a reference.');
        } catch (AiProxyException $error) {
            $this->assertSame(422, $error->responseStatus());
        }
        Http::assertSentCount(1);
    }

    private function task(): array
    {
        return ['taskType' => 'imageInference', 'taskUUID' => (string) Str::uuid(), 'model' => 'runware:101@1', 'positivePrompt' => 'A cup'];
    }

    private function provider(): AiProviderProfile
    {
        return new AiProviderProfile(['protocol' => 'runware', 'base_url' => 'https://api.runware.ai/v1', 'api_key' => 'runware-private-key']);
    }

    private function transport(): AiProviderTransport
    {
        return new AiProviderTransport(new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }
}
