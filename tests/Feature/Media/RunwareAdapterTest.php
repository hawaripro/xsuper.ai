<?php

namespace Tests\Feature\Media;

use App\Media\Adapters\RunwareAdapter;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\MediaState;
use App\Media\Enums\OutputKind;
use App\Media\Enums\SubmitOutcome;
use App\Media\MediaCapability;
use App\Media\MediaReferenceStager;
use App\Models\AiProviderProfile;
use App\Services\AiProviderEndpoint;
use App\Services\AiProviderTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class RunwareAdapterTest extends TestCase
{
    private const AIR = 'runware:101@1';

    /** The reply to the next Runware task; one fake serves the whole test (later Http::fake calls would never match). */
    private ?\Closure $reply = null;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->assertSame('https://api.runware.ai/v1', $request->url());
            $task = $request->data()[0];
            $this->sent[] = $task;
            $response = $this->reply === null ? Http::response('', 599) : ($this->reply)($task, $request);

            return is_array($response) ? Http::response($response) : $response;
        });
    }

    public function test_a_request_is_one_async_task_from_the_reviewed_binding_whatever_transport_fields_a_member_sends(): void
    {
        $request = $this->adapter()->buildRequest($this->capability(), ['inputs' => [
            'positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024, 'numberResults' => 2, 'settings' => [],
            'taskType' => 'videoInference', 'taskUUID' => 'member-chosen', 'model' => 'other:1@1', 'webhookURL' => 'https://attacker.test/hook',
            'uploadEndpoint' => 'https://attacker.test/put', 'deliveryMethod' => 'sync', 'outputType' => 'base64Data', 'ttl' => 60, 'includeCost' => false,
        ], 'params' => [], 'owner_id' => 7], self::AIR);

        $task = $request['task'];
        $this->assertTrue(Str::isUuid($task['taskUUID']));
        $this->assertSame($task['taskUUID'], $this->adapter()->taskId($request));
        $this->assertSame(['taskType' => 'imageInference', 'taskUUID' => $task['taskUUID'], 'model' => self::AIR,
            'positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024, 'numberResults' => 2, 'settings' => [],
            'outputFormat' => 'PNG', 'deliveryMethod' => 'async', 'outputType' => 'URL', 'includeCost' => true], $task);
        $this->assertSame(7, $request['owner_id']);
        $this->assertNotSame($task['taskUUID'], $this->adapter()->buildRequest($this->capability(), ['inputs' => ['positivePrompt' => 'A red cup']], self::AIR)['task']['taskUUID'],
            'every job gets its own task identity');
    }

    public function test_undeclared_platform_members_are_left_out_of_the_task(): void
    {
        $capability = $this->capability(['task_type' => 'caption', 'request_schema' => ['type' => 'object', 'properties' => [
            'deliveryMethod' => ['type' => 'string'], 'inputs' => ['type' => 'object'],
        ]]]);

        $task = $this->adapter()->buildRequest($capability, ['inputs' => ['inputs' => ['image' => 'https://example.test/cup.png']]], self::AIR)['task'];

        $this->assertSame(['taskType', 'taskUUID', 'model', 'inputs', 'outputFormat', 'deliveryMethod'], array_keys($task));
    }

    public function test_a_binding_that_does_not_match_the_model_routing_is_never_executed(): void
    {
        foreach ([
            ['adapter' => 'fal_schema_v2'], ['endpoint' => 'runware:100@1'], ['transport' => 'direct'],
            ['task_type' => 'accountManagement'], ['task_type' => 'getResponse'], ['task_type' => 'image Inference'],
            ['request_schema' => ['type' => 'object', 'properties' => ['positivePrompt' => ['type' => 'string']]]],
        ] as $override) {
            try {
                $this->adapter()->buildRequest($this->capability($override), ['inputs' => ['positivePrompt' => 'A cup']], self::AIR);
                $this->fail('Invalid binding accepted: '.json_encode($override));
            } catch (InvalidArgumentException) {
            }
        }
        Http::assertNothingSent();
    }

    public function test_an_acknowledged_task_is_accepted_with_its_own_identity_and_objects_restored(): void
    {
        $this->fakeRunware(fn (array $task) => ['data' => [['taskType' => $task['taskType'], 'taskUUID' => $task['taskUUID']]]]);
        $request = $this->request(['settings' => []]);

        $result = $this->adapter()->submit($this->provider(), $request);

        $this->assertSame(SubmitOutcome::Accepted, $result->outcome);
        $this->assertSame($request['task']['taskUUID'], $result->taskId);
        Http::assertSentCount(1);
        // A declared empty object is sent as {} rather than [].
        Http::assertSent(fn (Request $sent): bool => str_contains($sent->body(), '"settings":{}'));
    }

    public function test_rejections_release_the_reservation_and_an_empty_balance_is_logged_for_the_admin(): void
    {
        $rejections = [
            'task error' => fn (array $task) => ['errors' => [['code' => 'invalidPositivePrompt', 'taskUUID' => $task['taskUUID'], 'message' => 'Too short']]],
            'request error without acknowledgement' => fn () => ['errors' => [['code' => 'invalidApiKey', 'message' => 'Invalid API key']]],
            'validation status' => fn () => Http::response(['errors' => [['code' => 'invalidWidth']]], 400),
        ];
        foreach ($rejections as $case => $reply) {
            $this->fakeRunware($reply);
            $result = $this->adapter()->submit($this->provider(), $this->request());
            $this->assertSame(SubmitOutcome::Rejected, $result->outcome, $case);
        }

        Log::spy();
        $this->fakeRunware(fn () => Http::response(['errors' => [['code' => 'insufficientCredits', 'message' => 'Top up']]], 402));
        $result = $this->adapter()->submit($this->provider(), $this->request());
        $this->assertSame(SubmitOutcome::Rejected, $result->outcome);
        $this->assertSame('The media provider is temporarily unavailable. No generation was submitted.', $result->publicError);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'balance is insufficient')
            && $context['http_status'] === 402 && $context['code'] === 'insufficientCredits' && ! array_key_exists('api_key', $context));
    }

    public function test_an_unconfirmed_acceptance_is_uncertain_and_never_resent(): void
    {
        $outcomes = [
            'server error' => fn () => Http::response('', 503),
            'success without acknowledgement' => fn () => ['data' => []],
            'network failure' => fn (array $task, Request $request) => Http::failedConnection()($request),
        ];
        foreach ($outcomes as $case => $reply) {
            $this->fakeRunware($reply);
            $result = $this->adapter()->submit($this->provider(), $this->request());
            $this->assertSame(SubmitOutcome::Uncertain, $result->outcome, $case);
            $this->assertCount(1, $this->sent, $case);
        }
    }

    public function test_a_failure_before_anything_is_sent_releases_instead_of_staying_uncertain(): void
    {
        $this->fakeRunware(fn (array $task) => ['data' => [['taskType' => $task['taskType'], 'taskUUID' => $task['taskUUID']]]]);

        // DNS answers with a private address: the pinned connection refuses before any byte leaves the server.
        $result = $this->adapter(['10.0.0.8'])->submit($this->provider(), $this->request());

        $this->assertSame(SubmitOutcome::Rejected, $result->outcome);
        $this->assertSame([], $this->sent);
    }

    public function test_status_reports_progress_success_and_explicit_failure(): void
    {
        $id = (string) Str::uuid();
        $this->fakeRunware(fn () => ['data' => [['taskType' => 'imageInference', 'taskUUID' => $id, 'status' => 'processing', 'progress' => 47]]]);
        $processing = $this->adapter()->pollStatus($this->provider(), $id, ['quantity' => 1]);
        $this->assertSame([MediaState::Processing, 47], [$processing->state, $processing->progress]);

        $item = $this->success($id);
        $this->fakeRunware(fn () => ['data' => [$item]]);
        $completed = $this->adapter()->pollStatus($this->provider(), $id, ['quantity' => 1]);
        $this->assertSame(MediaState::Completed, $completed->state);
        $this->assertSame([$item['imageURL']], $completed->resultUrls);
        // The actual USD cost is kept in the private provider result; members never receive it (see the output store).
        $this->assertSame([$item], $completed->resultData);

        $this->fakeRunware(fn () => ['data' => [], 'errors' => [['code' => 'timeoutProvider', 'status' => 'error', 'taskUUID' => $id,
            'message' => 'The external provider did not respond within the timeout window.']]]);
        $failed = $this->adapter()->pollStatus($this->provider(), $id, ['quantity' => 1]);
        $this->assertSame([MediaState::Failed, 'The media provider could not complete this request.'], [$failed->state, $failed->publicError]);

        $this->fakeRunware(fn () => ['data' => [['taskUUID' => $id, 'status' => 'error', 'error' => ['code' => 'contentPolicyViolation']]]]);
        $this->assertSame(MediaState::Failed, $this->adapter()->pollStatus($this->provider(), $id, ['quantity' => 1])->state);
    }

    public function test_an_unknown_task_or_empty_status_is_never_a_failure(): void
    {
        $id = (string) Str::uuid();
        foreach ([
            'task not found' => [fn () => ['data' => [], 'errors' => [['code' => 'taskNotFound', 'taskUUID' => $id]]], 404],
            'no items yet' => [fn () => ['data' => []], 502],
        ] as $case => [$reply, $status]) {
            $this->fakeRunware($reply);
            try {
                $this->adapter()->pollStatus($this->provider(), $id, ['quantity' => 1]);
                $this->fail($case.' must not produce a final state.');
            } catch (\App\Exceptions\AiProxyException $error) {
                $this->assertSame($status, $error->responseStatus(), $case);
            }
        }
    }

    public function test_a_multi_result_task_completes_only_when_every_requested_result_succeeded(): void
    {
        $id = (string) Str::uuid();
        $pending = ['taskType' => 'imageInference', 'taskUUID' => $id, 'status' => 'processing'];
        $this->fakeRunware(fn () => ['data' => [$this->success($id), $pending, $pending]]);
        $partial = $this->adapter()->pollStatus($this->provider(), $id, ['quantity' => 3]);
        $this->assertSame([MediaState::Processing, 33], [$partial->state, $partial->progress]);

        $this->fakeRunware(fn () => ['data' => [$this->success($id)]]);
        $this->assertSame(MediaState::Processing, $this->adapter()->pollStatus($this->provider(), $id, ['quantity' => 3])->state,
            'a single early result does not complete a three-result task');

        $this->fakeRunware(fn () => ['data' => [$this->success($id), $this->success($id), $this->success($id)]]);
        $done = $this->adapter()->pollStatus($this->provider(), $id, ['quantity' => 3]);
        $this->assertSame(MediaState::Completed, $done->state);
        $this->assertCount(3, $done->resultUrls);
    }

    public function test_a_partially_failed_multi_result_task_fails_as_a_whole(): void
    {
        $id = (string) Str::uuid();
        $this->fakeRunware(fn () => ['data' => [$this->success($id), $this->success($id)],
            'errors' => [['code' => 'nsfwContentDetected', 'status' => 'error', 'taskUUID' => $id]]]);

        // Billing settles or releases a reservation as a whole, so the member is refunded in full.
        $this->assertSame(MediaState::Failed, $this->adapter()->pollStatus($this->provider(), $id, ['quantity' => 3])->state);
    }

    public function test_runware_offers_no_cancellation(): void
    {
        $this->assertSame(['requested' => false, 'confirmed' => false], $this->adapter()->cancel($this->provider(), (string) Str::uuid()));
        $this->assertFalse($this->adapter()->support()->cancel);
        Http::assertNothingSent();
    }

    private function fakeRunware(\Closure $reply): void
    {
        $this->reply = $reply;
        $this->sent = [];
    }

    private function success(string $id): array
    {
        $image = (string) Str::uuid();

        return ['taskType' => 'imageInference', 'taskUUID' => $id, 'status' => 'success', 'imageUUID' => $image,
            'imageURL' => 'https://im.runware.ai/image/os/a06dlim3/ws/3/ii/'.$image.'.png', 'seed' => 1956386241, 'cost' => 0.0013];
    }

    private function request(array $inputs = []): array
    {
        return $this->adapter()->buildRequest($this->capability(), ['inputs' => ['positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024, ...$inputs]], self::AIR);
    }

    private function capability(array $bindings = []): MediaCapability
    {
        $input = ['type' => 'object', 'properties' => [
            'positivePrompt' => ['type' => 'string'], 'width' => ['type' => 'integer'], 'height' => ['type' => 'integer'],
            'numberResults' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 1],
            'settings' => ['type' => 'object', 'properties' => ['quality' => ['type' => 'string']]],
        ]];
        $platform = ['taskType' => ['type' => 'string'], 'taskUUID' => ['type' => 'string'], 'model' => ['type' => 'string'],
            'deliveryMethod' => ['type' => 'string'], 'outputType' => ['type' => 'string'], 'includeCost' => ['type' => 'boolean'],
            'outputFormat' => ['type' => 'string'], 'webhookURL' => ['type' => 'string'], 'uploadEndpoint' => ['type' => 'string']];

        return new MediaCapability('runware/bfl-flux-1-dev', MediaOperation::TextToImage, OutputKind::Image, 2, providerBindings: [
            'adapter' => 'runware_v1', 'endpoint' => self::AIR, 'task_type' => 'imageInference', 'transport' => 'async',
            'request_schema' => ['type' => 'object', 'properties' => [...$input['properties'], ...$platform]],
            'output_schema' => ['type' => 'object'], 'quantity_input' => 'numberResults',
            'constants' => ['outputFormat' => 'PNG', 'webhookURL' => 'https://constant.test/hook', 'deliveryMethod' => 'sync'],
            ...$bindings,
        ], inputSchema: $input, outputSchema: ['type' => 'object']);
    }

    private function provider(): AiProviderProfile
    {
        return new AiProviderProfile(['protocol' => 'runware', 'base_url' => 'https://api.runware.ai/v1', 'api_key' => 'runware-private-key']);
    }

    private function adapter(array $addresses = ['93.184.216.34']): RunwareAdapter
    {
        $transport = new AiProviderTransport(new class($addresses) extends AiProviderEndpoint
        {
            public function __construct(private readonly array $addresses) {}

            protected function resolveAddresses(string $host): array
            {
                return $this->addresses;
            }
        });

        return new RunwareAdapter($transport, new MediaReferenceStager($transport));
    }
}
