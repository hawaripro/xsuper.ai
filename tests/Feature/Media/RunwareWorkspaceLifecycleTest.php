<?php

namespace Tests\Feature\Media;

use App\Jobs\PollWorkspaceMediaJob;
use App\Media\AssetService;
use App\Media\CapabilityResolver;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use App\Models\UserToken;
use App\Models\WorkspaceMediaJob;
use App\Services\AiProviderEndpoint;
use App\Services\WorkspaceMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RunwareWorkspaceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const AIR = 'runware:101@1';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';

    /** @var array<string, list<\Closure|array>> replies per Runware task type, consumed in order */
    private array $replies = [];

    /** @var list<array<string, mixed>> every task sent to Runware, in order */
    private array $tasks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        config(['media.coordinator_restricted' => false, 'media.kill_switch' => false]);
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (preg_match('~^(?:https://im\.runware\.ai|http://127\.0\.0\.1:8124/runware/files|https://files\.example\.test)/~', $request->url())) {
                return Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']);
            }
            if (str_starts_with($request->url(), 'https://queue.fal.run/')) {
                return Http::response('', 503);
            }
            $this->assertSame('https://api.runware.ai/v1', $request->url());
            // The wire body rather than the PHP payload, in which declared JSON objects are stdClass.
            $task = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR)[0];
            $this->tasks[] = $task;
            $type = (string) ($task['taskType'] ?? '');
            if (($this->replies[$type] ?? []) === []) {
                return Http::response(['errors' => [['code' => 'unexpectedTask', 'message' => $type]]], 599);
            }
            $reply = array_shift($this->replies[$type]);
            $reply = $reply instanceof \Closure ? $reply($task) : $reply;

            return is_array($reply) ? Http::response($reply) : $reply;
        });
    }

    public function test_the_task_identity_is_stored_before_the_only_submission_and_results_are_saved_without_provider_cost(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024], 10))[0];
        $seen = null;
        $this->replies['imageInference'] = [function (array $task) use ($job, &$seen) {
            $seen = [$job->fresh()->stage, $job->fresh()->upstream_job_id];

            return $this->ack($task);
        }];

        $service->process($job->id);

        $job->refresh();
        $sent = $this->tasks[0];
        $this->assertSame(['submitting', $sent['taskUUID']], $seen, 'the task identity is durable before the paid request');
        $this->assertSame([$sent['taskUUID'], 'processing', 'rendering'], [$job->upstream_job_id, $job->status, $job->stage]);
        $this->assertSame(['imageInference', self::AIR, 'A red cup', 1, 'async', true, 'URL'], [$sent['taskType'], $sent['model'],
            $sent['positivePrompt'], $sent['numberResults'], $sent['deliveryMethod'], $sent['includeCost'], $sent['outputType']]);
        Queue::assertPushed(PollWorkspaceMediaJob::class);

        $this->replies['getResponse'] = [fn (array $task) => ['data' => [$this->processing($task['taskUUID'], 40)]],
            fn (array $task) => ['data' => [$this->success($task['taskUUID'])]]];
        $this->travel(10)->seconds();
        $service->poll($job->id);
        $running = $service->payload($job->fresh());
        $this->assertSame(40, $running['progress']);
        $this->assertSame(['model_id' => 'runware/bfl-flux-1-dev', 'operation' => 'text_to_image',
            'inputs' => ['positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024, 'numberResults' => 1]],
            json_decode(json_encode($running['request']), true));
        $this->assertSame('A red cup', $running['details']['prompt']);

        $this->travel(10)->seconds();
        $service->poll($job->id);

        $saved = $job->fresh();
        $this->assertSame(['completed', 'settled'], [$saved->status, $saved->billing_status]);
        $this->assertSame(90, UserToken::getBalance($user->id));
        $this->assertSame(0.0038, $saved->provider_result[0]['cost'], 'the actual USD cost stays in the private provider result');
        $payload = json_decode(json_encode($service->payload($saved)), true);
        $image = collect($payload['outputs'])->firstWhere('kind', 'image');
        $this->assertSame(base64_decode(self::PNG), Storage::disk('local')->get($service->resolveOwnedOutput($user, $saved->job_id, $image['id'])['path']));
        $this->assertSame([['imageUUID' => $saved->provider_result[0]['imageUUID'], 'imageURL' => $image['download_url'], 'seed' => 1956386241, 'NSFWContent' => false]],
            $payload['result_data']);
        $this->assertNull($payload['progress']);
        $document = collect($payload['outputs'])->firstWhere('name', 'result.json');
        $documents = [json_encode($payload), Storage::disk('local')->get($service->resolveOwnedOutput($user, $saved->job_id, $document['id'])['path'])];
        foreach ($documents as $member) {
            foreach (['cost', '0.0038', 'im.runware.ai', self::AIR, 'imageInference', 'runware_v1', 'runware-private-key', 'taskUUID', $sent['taskUUID']] as $private) {
                $this->assertStringNotContainsString($private, $member);
            }
        }
        $this->assertSame(['imageInference', 'getResponse', 'getResponse'], array_column($this->tasks, 'taskType'));
    }

    public function test_owned_references_are_uploaded_through_media_storage_before_the_submission_marker(): void
    {
        [$user, $model] = $this->fixture();
        $asset = app(AssetService::class)->store($user, UploadedFile::fake()->image('seed.png', 8, 8), InputRole::ImageRef);
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024,
            'inputs' => ['seedImage' => $asset->id]], 10))[0];
        $media = (string) Str::uuid();
        $this->replies['mediaStorage'] = [function (array $task) use ($job, $media) {
            // An upload is not a paid generation: a worker dying here leaves a recoverable, unsubmitted job.
            $this->assertSame(['preparing', null], [$job->fresh()->stage, $job->fresh()->submitted_at]);
            $this->assertSame('upload', $task['operation']);
            $this->assertStringStartsWith('data:image/png;base64,', $task['media']);

            return ['data' => [['taskType' => 'mediaStorage', 'taskUUID' => $task['taskUUID'], 'mediaUUID' => $media,
                'mediaURL' => 'https://mm.runware.ai/media-storage/ws/2/id/'.$media.'.png']]];
        }];
        $this->replies['imageInference'] = [fn (array $task) => $this->ack($task)];

        $service->process($job->id);

        $this->assertSame(['mediaStorage', 'imageInference'], array_column($this->tasks, 'taskType'));
        $this->assertSame($media, $this->tasks[1]['inputs']['seedImage']);
        $this->assertSame($asset->id, $job->fresh()->normalized_inputs['inputs']['seedImage']);
        $this->assertSame('rendering', $job->fresh()->stage);
    }

    public function test_explicit_failures_and_rejections_refund_the_member(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $failed = $this->submitted($user, $model, 10);
        $this->replies['getResponse'] = [fn (array $task) => ['data' => [], 'errors' => [['code' => 'timeoutProvider', 'status' => 'error',
            'taskUUID' => $task['taskUUID'], 'message' => 'The external provider did not respond within the timeout window.']]]];
        $this->travel(10)->seconds();
        $service->poll($failed->id);
        $this->assertSame(['failed', 'released'], [$failed->fresh()->status, $failed->fresh()->billing_status]);
        $this->assertSame('The provider could not complete this request. Reserved tokens have been returned.', $failed->fresh()->error_message);

        $rejected = $service->create($user, $this->request($model, ['positivePrompt' => 'saldo habis', 'width' => 1024, 'height' => 1024], 10))[0];
        $this->replies['imageInference'] = [fn () => Http::response(['errors' => [['code' => 'insufficientCredits', 'message' => 'Top up']]], 402)];
        $service->process($rejected->id);
        $this->assertSame(['failed', 'released'], [$rejected->fresh()->status, $rejected->fresh()->billing_status]);
        $this->assertSame(100, UserToken::getBalance($user->id));
        $this->assertSame(1, collect($this->tasks)->where('taskType', 'imageInference')->where('positivePrompt', 'saldo habis')->count());
    }

    public function test_the_quote_shown_before_submission_is_exactly_the_reservation_for_several_results(): void
    {
        [$user, $model] = $this->fixture();
        $capability = $this->actingAs($user)->getJson('/api/media/workspace/capabilities?model='.urlencode($model->model_id))
            ->assertOk()->json('capabilities.text_to_image');
        $billing = $capability['billing'];
        $this->assertSame(['per_output', 'generation', 'numberResults', 4], [$billing['mode'], $billing['price_unit'], $billing['quantity_input'], $billing['max_quantity']]);

        // The documented client quote: price_tokens × max(1, inputs[quantity_input]).
        $inputs = ['positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024, 'numberResults' => 3];
        $quote = $capability['price_tokens'] * max(1, $inputs[$billing['quantity_input']]);
        $this->actingAs($user)->postJson('/api/media/workspace/jobs', [...$this->request($model, $inputs, $capability['price_tokens'])])
            ->assertStatus(409);
        $job = $this->actingAs($user)->postJson('/api/media/workspace/jobs', $this->request($model, $inputs, $quote))->assertStatus(202)->json('job');

        $this->assertSame([30, 30], [$quote, $job['price_tokens']]);
        $this->assertSame(30, WorkspaceMediaJob::query()->where('job_id', $job['id'])->value('tokens_reserved'));
        $this->assertSame(70, UserToken::getBalance($user->id));
    }

    public function test_a_per_second_tariff_is_multiplied_by_the_requested_results(): void
    {
        [$user, $model] = $this->fixture(['kind' => 'video', 'unit' => 'second', 'token_cost' => 2]);
        $service = app(WorkspaceMediaService::class);
        $billing = $service->capabilities($user, $model->model_id)['capabilities']['text_to_video']['billing'];
        $this->assertSame(['per_second', 'second', 'duration', 'numberResults'], [$billing['mode'], $billing['price_unit'], $billing['duration_field'], $billing['quantity_input']]);

        $job = $service->create($user, $this->request($model, ['positivePrompt' => 'A drone shot', 'duration' => 5, 'numberResults' => 2], 20, 'text_to_video'))[0];

        $this->assertSame([20, 20], [$job->price_tokens, $job->tokens_reserved]);
        $this->assertSame(80, UserToken::getBalance($user->id));
    }

    public function test_a_three_result_task_is_complete_only_after_every_result_and_a_partial_failure_refunds_in_full(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $job = $this->submitted($user, $model, 30, ['numberResults' => 3]);
        $this->replies['getResponse'] = [
            fn (array $task) => ['data' => [$this->success($task['taskUUID']), $this->processing($task['taskUUID']), $this->processing($task['taskUUID'])]],
            // Only finished results listed: the job's requested count, not the item count, decides completion.
            fn (array $task) => ['data' => [$this->success($task['taskUUID'])]],
            fn (array $task) => ['data' => [$this->success($task['taskUUID']), $this->success($task['taskUUID']), $this->success($task['taskUUID'])]],
        ];
        foreach (['one of three listed as finished', 'only the finished one listed'] as $case) {
            $this->travel(10)->seconds();
            $service->poll($job->id);
            $this->assertSame(['processing', 'rendering', 'reserved', null], [$job->fresh()->status, $job->fresh()->stage,
                $job->fresh()->billing_status, $job->fresh()->provider_result], $case);
            $this->assertSame(33, $service->payload($job->fresh())['progress'], $case);
        }

        $this->travel(10)->seconds();
        $service->poll($job->id);
        $saved = $service->payload($job->fresh());
        $this->assertSame(['completed', 'settled'], [$saved['status'], $saved['billing_status']]);
        $this->assertCount(3, collect($saved['outputs'])->where('kind', 'image'));
        $this->assertCount(3, $saved['result_data']);
        $this->assertSame(70, UserToken::getBalance($user->id));

        // Billing settles or releases a whole reservation, so one failed result refunds the entire request.
        $partial = $this->submitted($user, $model, 30, ['numberResults' => 3]);
        $this->replies['getResponse'] = [fn (array $task) => ['data' => [$this->success($task['taskUUID']), $this->success($task['taskUUID'])],
            'errors' => [['code' => 'nsfwContentDetected', 'status' => 'error', 'taskUUID' => $task['taskUUID']]]]];
        $this->travel(10)->seconds();
        $service->poll($partial->id);
        $this->assertSame(['failed', 'released', null], [$partial->fresh()->status, $partial->fresh()->billing_status, $partial->fresh()->asset_paths]);
        $this->assertSame(70, UserToken::getBalance($user->id));
    }

    public function test_an_unknown_submission_is_read_back_and_saved_without_submitting_again(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $job = $this->uncertainJob($user, $model);
        $this->assertSame(['uncertain', 'submission_uncertain', 'reserved'], [$job->status, $job->stage, $job->billing_status]);
        $this->assertSame($this->tasks[0]['taskUUID'], $job->upstream_job_id);
        $this->assertSame(0, $service->recover()['reconciling'], 'acceptance gets a moment to become visible');

        $this->travel(61)->seconds();
        $this->assertSame(1, $service->recover()['reconciling']);
        Queue::assertPushed(PollWorkspaceMediaJob::class, fn (PollWorkspaceMediaJob $poll): bool => $poll->workspaceMediaJobId === $job->id);
        $this->replies['getResponse'] = [fn (array $task) => ['data' => [$this->success($task['taskUUID'])]]];
        $service->poll($job->id);

        $saved = $job->fresh();
        $this->assertSame(['completed', 'settled', null], [$saved->status, $saved->billing_status, $saved->error_message]);
        $this->assertSame(90, UserToken::getBalance($user->id));
        $this->assertSame(['imageInference', 'getResponse'], array_column($this->tasks, 'taskType'));
    }

    public function test_reconciliation_refunds_an_explicit_failure_and_resumes_polling_a_running_task(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $failed = $this->uncertainJob($user, $model);
        $running = $this->uncertainJob($user, $model);
        $this->replies['getResponse'] = [
            fn (array $task) => ['data' => [], 'errors' => [['code' => 'invalidReferenceImagesCount', 'status' => 'error', 'taskUUID' => $task['taskUUID']]]],
            fn (array $task) => ['data' => [$this->processing($task['taskUUID'], 25)]],
            fn (array $task) => ['data' => [$this->success($task['taskUUID'])]],
        ];
        $this->travel(61)->seconds();

        $service->poll($failed->id);
        $this->assertSame(['failed', 'released'], [$failed->fresh()->status, $failed->fresh()->billing_status]);

        $service->poll($running->id);
        $this->assertSame(['processing', 'rendering', 'reserved', null], [$running->fresh()->status, $running->fresh()->stage,
            $running->fresh()->billing_status, $running->fresh()->error_message]);
        $this->assertSame(25, $service->payload($running->fresh())['progress']);
        $this->travel(10)->seconds();
        $service->poll($running->id);
        $this->assertSame(['completed', 'settled'], [$running->fresh()->status, $running->fresh()->billing_status]);
        $this->assertSame(90, UserToken::getBalance($user->id));
        $this->assertSame(2, collect($this->tasks)->where('taskType', 'imageInference')->count(), 'one submission per job, never repeated');
    }

    public function test_an_inconclusive_read_back_keeps_the_reservation_for_review(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $job = $this->uncertainJob($user, $model);
        $this->replies['getResponse'] = [
            fn (array $task) => ['data' => [], 'errors' => [['code' => 'taskNotFound', 'taskUUID' => $task['taskUUID']]]],
            fn () => Http::response('', 503),
        ];
        foreach (['task not found', 'transport failure'] as $case) {
            $this->travel(61)->seconds();
            $service->poll($job->id);
            $job->refresh();
            $this->assertSame(['uncertain', 'submission_uncertain', 'reserved'], [$job->status, $job->stage, $job->billing_status], $case);
            $this->assertTrue($job->next_poll_at->isFuture(), $case.': the next read-back is deferred');
            $this->assertSame(0, $service->recover()['reconciling'], $case);
        }
        $this->assertSame(90, UserToken::getBalance($user->id));

        // Another account must never be asked about this request.
        $model->provider->fresh()->fill(['api_key' => 'another-account-key'])->save();
        $this->travel(2)->hours();
        $this->assertSame(1, $service->recover()['reconciling']);
        $service->poll($job->id);
        $this->assertSame(['uncertain', 'reserved'], [$job->fresh()->status, $job->fresh()->billing_status]);
        $this->assertSame(['imageInference', 'getResponse', 'getResponse'], array_column($this->tasks, 'taskType'));
    }

    public function test_unknown_outcomes_of_other_protocols_stay_under_manual_review(): void
    {
        [$user, $model] = $this->falFixture();
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, ['model' => $model->model_id, 'operation' => 'text_to_image', 'inputs' => ['prompt' => 'A cup'],
            'expected_capability_hash' => app(CapabilityResolver::class)->resolve($model, MediaOperation::TextToImage, schemaContracts: true)->sourceHash,
            'expected_price_tokens' => 10, 'idempotency_key' => (string) Str::uuid()])[0];
        $service->process($job->id);
        $this->assertSame(['uncertain', 'submission_uncertain', null], [$job->fresh()->status, $job->fresh()->stage, $job->fresh()->next_poll_at]);

        $this->travel(2)->hours();
        $this->assertSame(0, $service->recover()['reconciling']);
        $service->poll($job->id);
        $this->assertSame(['uncertain', 'reserved'], [$job->fresh()->status, $job->fresh()->billing_status]);
        Http::assertSentCount(1);
    }

    public function test_runware_outputs_are_fetched_only_from_runware_storage_or_a_gated_loopback_mock(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024], 10))[0];
        $item = fn (string $url): array => [['taskType' => 'imageInference', 'status' => 'success', 'imageUUID' => (string) Str::uuid(), 'imageURL' => $url, 'cost' => 0.0038]];
        $job->update(['status' => 'save_failed', 'stage' => 'save_failed', 'result_received_at' => now(), 'submitted_at' => now(),
            'provider_result' => $item('https://files.example.test/image.png')]);
        $service->retrySave($user, $job->job_id);
        $service->poll($job->id);
        $this->assertSame('save_failed', $job->fresh()->status, 'an output on another host is never downloaded');

        $job->update(['provider_result' => $item('http://127.0.0.1:8124/runware/files/'.Str::uuid().'.png')]);
        $service->retrySave($user, $job->job_id);
        $service->poll($job->id);
        $this->assertSame('save_failed', $job->fresh()->status, 'a loopback mock needs the local-provider gate');

        $this->app['env'] = 'local';
        config(['media.allow_local_providers' => true]);
        $service->retrySave($user, $job->job_id);
        $service->poll($job->id);
        $this->assertSame(['completed', 'settled'], [$job->fresh()->status, $job->fresh()->billing_status]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'files.example.test'));
    }

    public function test_member_catalog_payloads_never_carry_bindings_provider_pricing_or_credentials(): void
    {
        [$user, $model] = $this->fixture();
        $model->update(['description_id' => 'Model gambar cepat dari Black Forest Labs.', 'description_en' => 'Fast image model from Black Forest Labs.']);
        $bodies = [
            $this->actingAs($user)->getJson('/api/media/workspace/models?kind=image')->assertOk()
                ->assertJsonPath('models.0.description', 'Model gambar cepat dari Black Forest Labs.')->getContent(),
            $this->actingAs($user)->getJson('/api/media/workspace/models?locale=en')->assertOk()
                ->assertJsonPath('models.0.description', 'Fast image model from Black Forest Labs.')->getContent(),
            $this->actingAs($user)->getJson('/api/media/workspace/capabilities?locale=en&model='.urlencode($model->model_id))->assertOk()
                ->assertJsonPath('model.description', 'Fast image model from Black Forest Labs.')
                ->assertJsonPath('capabilities.text_to_image.execution', ['transport' => 'queue'])->getContent(),
        ];
        $this->actingAs($user)->getJson('/api/media/workspace/models?locale=fr')->assertUnprocessable();
        foreach ($bodies as $body) {
            foreach ([self::AIR, 'runware_v1', 'request_schema', 'task_type', 'imageInference', 'source_metadata', 'schema_url',
                '0.0038', '"cost"', 'runware-private-key', 'api.runware.ai', 'deliveryMethod', 'includeCost'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }
        }
    }

    public function test_the_other_category_lists_and_clears_only_data_and_file_results(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $image = $service->create($user, $this->request($model, ['positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024], 10))[0];
        $caption = $service->create($user, $this->request($model, ['positivePrompt' => 'A caption', 'width' => 1024, 'height' => 1024], 10))[0];
        foreach ([$image, $caption] as $job) {
            $job->update(['status' => 'failed', 'stage' => 'failed', 'billing_status' => 'released']);
        }
        $caption->update(['output_kind' => 'data']);

        $this->actingAs($user)->getJson('/api/media/workspace/jobs?kind=other')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('jobs.0.id', $caption->job_id);
        $this->actingAs($user)->deleteJson('/api/media/workspace/jobs?kind=other')->assertOk()->assertJsonPath('deleted_count', 1);

        $this->assertModelMissing($caption);
        $this->assertModelExists($image);
    }

    private function uncertainJob(User $user, AiModelProfile $model): WorkspaceMediaJob
    {
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024], 10))[0];
        $this->replies['imageInference'][] = fn () => Http::response('', 504);
        $service->process($job->id);

        return $job->fresh();
    }

    private function submitted(User $user, AiModelProfile $model, int $price, array $inputs = []): WorkspaceMediaJob
    {
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['positivePrompt' => 'A red cup', 'width' => 1024, 'height' => 1024, ...$inputs], $price))[0];
        $this->replies['imageInference'][] = fn (array $task) => $this->ack($task);
        $service->process($job->id);
        $this->assertSame('rendering', $job->fresh()->stage);

        return $job->fresh();
    }

    private function ack(array $task): array
    {
        return ['data' => [['taskType' => $task['taskType'], 'taskUUID' => $task['taskUUID']]]];
    }

    private function processing(string $taskId, ?int $progress = null): array
    {
        return ['taskType' => 'imageInference', 'taskUUID' => $taskId, 'status' => 'processing', ...($progress === null ? [] : ['progress' => $progress])];
    }

    private function success(string $taskId): array
    {
        $image = (string) Str::uuid();

        return ['taskType' => 'imageInference', 'taskUUID' => $taskId, 'status' => 'success', 'imageUUID' => $image,
            'imageURL' => 'https://im.runware.ai/image/os/a06dlim3/ws/3/ii/'.$image.'.png', 'seed' => 1956386241, 'NSFWContent' => false, 'cost' => 0.0038];
    }

    private function request(AiModelProfile $model, array $inputs, int $price, string $operation = 'text_to_image'): array
    {
        return ['model' => $model->model_id, 'operation' => $operation, 'inputs' => $inputs,
            'expected_capability_hash' => app(CapabilityResolver::class)->resolve($model, MediaOperation::from($operation), schemaContracts: true)->sourceHash,
            'expected_price_tokens' => $price, 'idempotency_key' => (string) Str::uuid()];
    }

    /** A published runware_v1 revision as the catalog importer produces it (C2), built inline. */
    private function fixture(array $options = []): array
    {
        $video = ($options['kind'] ?? 'image') === 'video';
        $user = User::factory()->create(['permissions' => ['image_generator' => true, 'video_generator' => true]]);
        UserToken::topup($user->id, 100);
        $provider = AiProviderProfile::create(['slug' => 'runware', 'name' => 'Runware', 'protocol' => 'runware',
            'base_url' => 'https://api.runware.ai/v1', 'api_key' => 'runware-private-key', 'is_enabled' => true,
            'status' => 'healthy', 'authenticated_at' => now()]);
        $air = $video ? 'klingai:kling-video@2.6-pro' : self::AIR;
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => $video ? 'runware/klingai-video-2-6-pro' : 'runware/bfl-flux-1-dev',
            'upstream_model_id' => $air, 'display_name' => $video ? 'Kling VIDEO 2.6 Pro' : 'FLUX.1 [dev]', 'category' => $video ? 'video' : 'image',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => $options['token_cost'] ?? 10]);
        $asset = ['type' => 'string', 'minLength' => 1, 'maxLength' => 2048, 'x-workspace-asset' => ['kind' => 'image', 'role' => 'image_ref', 'accepts_url' => true]];
        $properties = [
            'positivePrompt' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 3000, 'x-workspace-group' => 'core'],
            'seed' => ['type' => 'integer', 'minimum' => 0, 'x-workspace-group' => 'core'],
            'inputs' => ['type' => 'object', 'additionalProperties' => false, 'x-workspace-group' => 'inputs', 'properties' => [$video ? 'frameImages' : 'seedImage' => $asset]],
            ...($video ? ['duration' => ['type' => 'integer', 'enum' => [5, 10], 'x-workspace-group' => 'core']] : [
                'width' => ['type' => 'integer', 'minimum' => 128, 'maximum' => 2048, 'multipleOf' => 64, 'x-workspace-group' => 'core'],
                'height' => ['type' => 'integer', 'minimum' => 128, 'maximum' => 2048, 'multipleOf' => 64, 'x-workspace-group' => 'core'],
            ]),
            'numberResults' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 1, 'x-workspace-group' => 'advanced'],
        ];
        $input = ['type' => 'object', 'additionalProperties' => false, 'required' => $video ? ['positivePrompt', 'duration'] : ['positivePrompt', 'width', 'height'],
            'properties' => $properties];
        $field = $video ? 'video' : 'image';
        $output = ['type' => 'object', 'properties' => [
            $field.'UUID' => ['type' => 'string', 'format' => 'uuid'],
            $field.'URL' => ['type' => 'string', 'format' => 'uri', 'x-workspace-output' => ['kind' => $field]],
            'seed' => ['type' => 'integer'], 'NSFWContent' => ['type' => 'boolean'],
        ]];
        $taskType = $video ? 'videoInference' : 'imageInference';
        $platform = ['taskType' => ['type' => 'string', 'const' => $taskType], 'taskUUID' => ['type' => 'string', 'format' => 'uuid'],
            'model' => ['type' => 'string', 'const' => $air], 'deliveryMethod' => ['type' => 'string', 'enum' => $video ? ['async'] : ['sync', 'async']],
            'includeCost' => ['type' => 'boolean'], 'outputType' => ['type' => 'string', 'enum' => ['URL']], 'webhookURL' => ['type' => 'string', 'format' => 'uri'],
            'uploadEndpoint' => ['type' => 'string', 'format' => 'uri'], 'ttl' => ['type' => 'integer', 'minimum' => 60]];
        $operation = $video ? 'text_to_video' : 'text_to_image';
        $revision = MediaCapabilityRevision::create(['ai_model_profile_id' => $model->id, 'operation' => $operation,
            'contract_version' => 2, 'revision' => 1, 'status' => 'published', 'published_at' => now(),
            'source_hash' => str_repeat('b', 64), 'source_schema' => ['fixture' => true],
            'source_metadata' => ['air' => $air, 'task_type' => $taskType, 'pricing' => ['overview' => 'Each image generation costs $0.0038 at 1024x1024.',
                'basis' => null, 'rates' => [], 'measured' => [['configuration' => '1024x1024 · 28 steps', 'price' => 0.0038]], 'examples' => [], 'catalog_unit' => 'generation']],
            'definition' => ['model_public_id' => $model->model_id, 'operation' => $operation, 'output_kind' => $video ? 'video' : 'image',
                'contract_version' => 2, 'inputs' => [], 'params' => [], 'input_schema' => $input, 'output_schema' => $output],
            'provider_bindings' => ['adapter' => 'runware_v1', 'endpoint' => $air, 'task_type' => $taskType, 'transport' => 'async',
                'request_schema' => [...$input, 'required' => [...$input['required'], 'taskType', 'taskUUID', 'model'], 'properties' => [...$properties, ...$platform]],
                'output_schema' => [...$output, 'properties' => [...$output['properties'], 'taskType' => ['type' => 'string'], 'taskUUID' => ['type' => 'string'],
                    'status' => ['type' => 'string'], 'cost' => ['type' => 'number']]],
                'constants' => ['outputType' => 'URL', 'includeCost' => true, 'deliveryMethod' => 'async'],
                'quantity_input' => 'numberResults', 'source' => ['model_id' => 'bfl-flux-1-dev', 'schema_url' => 'https://runware.ai/docs/models/bfl-flux-1-dev/schema.json']],
            'curation_overrides' => ['pricing' => ['token_cost' => $options['token_cost'] ?? 10, 'unit' => $options['unit'] ?? 'generation',
                'reviewed_by' => $user->id, 'reviewed_at' => now()->toISOString(), 'variable_configuration' => true]],
        ]);

        return [$user, $model, $revision];
    }

    private function falFixture(): array
    {
        $user = User::factory()->create(['permissions' => ['image_generator' => true]]);
        UserToken::topup($user->id, 100);
        $provider = AiProviderProfile::create(['slug' => 'workspace-fal', 'name' => 'Fal', 'protocol' => 'fal',
            'base_url' => 'https://fal.run', 'api_key' => 'test-only-key', 'is_enabled' => true, 'status' => 'healthy', 'authenticated_at' => now()]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'public-workspace-model',
            'upstream_model_id' => 'fal-ai/workspace-fixture', 'display_name' => 'Workspace fixture', 'category' => 'image',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 10]);
        $schema = ['type' => 'object', 'properties' => ['prompt' => ['type' => 'string']], 'required' => ['prompt']];
        MediaCapabilityRevision::create(['ai_model_profile_id' => $model->id, 'operation' => 'text_to_image',
            'contract_version' => 2, 'revision' => 1, 'status' => 'published', 'published_at' => now(),
            'source_hash' => str_repeat('a', 64), 'source_schema' => ['fixture' => true],
            'definition' => ['model_public_id' => $model->model_id, 'operation' => 'text_to_image', 'output_kind' => 'image',
                'contract_version' => 2, 'inputs' => [], 'params' => [], 'input_schema' => $schema, 'output_schema' => ['type' => 'object']],
            'provider_bindings' => ['adapter' => 'fal_schema_v2', 'endpoint' => 'fal-ai/workspace-fixture', 'transport' => 'queue',
                'queue_root' => 'fal-ai/workspace-fixture', 'request_schema' => $schema, 'output_schema' => ['type' => 'object']],
            'curation_overrides' => ['pricing' => ['token_cost' => 10, 'unit' => 'request', 'reviewed_by' => $user->id,
                'reviewed_at' => now()->toISOString(), 'variable_configuration' => true]],
        ]);

        return [$user, $model];
    }
}
