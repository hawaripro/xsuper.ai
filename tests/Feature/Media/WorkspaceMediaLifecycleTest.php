<?php

namespace Tests\Feature\Media;

use App\Exceptions\ImageGenerationException;
use App\Jobs\PollWorkspaceMediaJob;
use App\Media\AssetService;
use App\Media\CapabilityResolver;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\MediaGenerationCoordinator;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\MediaAsset;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use App\Models\UserToken;
use App\Models\WorkspaceMediaJob;
use App\Services\AiProviderEndpoint;
use App\Services\ImageGenerationService;
use App\Services\WorkspaceMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceMediaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['media.coordinator_restricted' => false, 'media.kill_switch' => false]);
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    public function test_replay_uses_original_normalization_and_never_reserves_again_after_price_or_activation_changes(): void
    {
        [$user, $model] = $this->fixture();
        $request = $this->request($model, ['prompt' => 'A cup', 'settings' => ['seed' => 0]]);
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $request)[0];
        $model->update(['token_cost' => 91, 'is_enabled' => false]);
        $replay = $service->create($user, $request)[0];
        $this->assertSame($job->job_id, $replay->job_id);
        $this->assertSame(90, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('token_reservations', 1);
        $this->assertSame(['prompt' => 'A cup', 'settings' => ['seed' => 0]], $job->normalized_inputs);
        try {
            $service->create($user, [...$request, 'inputs' => ['prompt' => 'A different cup']]);
            $this->fail('Changed input must not replay another generation.');
        } catch (ImageGenerationException $exception) {
            $this->assertSame(409, $exception->responseStatus());
        }
    }

    public function test_nested_foreign_asset_is_rejected_before_a_reservation_or_provider_submission(): void
    {
        [$user, $model, $revision] = $this->fixture();
        $definition = $revision->definition;
        $definition['input_schema']['properties']['references'] = [
            'type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'image' => ['type' => 'string', 'format' => 'uuid', 'x-workspace-asset' => ['kind' => 'image', 'role' => 'image_ref']],
            ]],
        ];
        $revision->update(['definition' => $definition]);
        $other = User::factory()->create();
        $asset = MediaAsset::create(['user_id' => $other->id, 'media_type' => 'image', 'role' => 'image_ref',
            'storage_disk' => 'local', 'storage_path' => 'foreign.png', 'size_bytes' => 1, 'mime' => 'image/png',
            'signature_ok' => true, 'retention_status' => 'active']);
        try {
            app(WorkspaceMediaService::class)->create($user, $this->request($model, [
                'prompt' => 'A cup', 'references' => [['image' => $asset->id]],
            ]));
            $this->fail('Foreign references must not be admitted.');
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Illuminate\Validation\ValidationException $exception) {
            $this->assertDatabaseCount('workspace_media_jobs', 0);
            $this->assertSame(100, UserToken::getBalance($user->id));
            Http::assertNothingSent();
        }
    }

    public function test_an_ambiguous_file_field_is_a_validation_error_before_any_reservation(): void
    {
        [$user, $model, $revision] = $this->fixture();
        $definition = $revision->definition;
        $leaf = fn (string $kind): array => ['type' => 'string', 'format' => 'uuid', 'x-workspace-asset' => ['kind' => $kind, 'role' => $kind.'_ref']];
        $definition['input_schema']['properties']['media'] = ['anyOf' => [$leaf('image'), $leaf('audio')]];
        $revision->update(['definition' => $definition]);
        $asset = app(AssetService::class)->store($user, UploadedFile::fake()->image('ref.png', 8, 8), InputRole::ImageRef);
        // A 5xx here would read as a possibly accepted paid request in the studio.
        try {
            app(WorkspaceMediaService::class)->create($user, $this->request($model, ['prompt' => 'A cup', 'media' => $asset->id]));
            $this->fail('An ambiguous file field must not be admitted.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('media', $exception->errors());
        }
        $this->assertDatabaseCount('workspace_media_jobs', 0);
        $this->assertSame(100, UserToken::getBalance($user->id));
    }

    public function test_interrupted_submission_becomes_uncertain_and_cannot_be_requeued_or_refunded_as_cancelled(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['prompt' => 'A cup']))[0];
        $job->update(['status' => 'processing', 'stage' => 'submitting', 'submitted_at' => now()->subMinutes(20),
            'processing_started_at' => now()->subMinutes(20), 'processing_token' => (string) Str::uuid()]);
        $service->recover();
        $service->process($job->id);
        $this->assertSame('uncertain', $job->fresh()->status);
        $this->assertFalse($service->payload($job->fresh())['can_cancel']);
        $this->assertSame('reserved', $job->fresh()->billing_status);
        $this->assertSame(90, UserToken::getBalance($user->id));
        Http::assertNothingSent();
    }

    public function test_reference_files_are_staged_before_the_request_is_marked_as_submitting(): void
    {
        [$user, $model, $revision] = $this->fixture();
        $definition = $revision->definition;
        $definition['input_schema']['properties']['image'] = ['type' => 'string', 'format' => 'uuid', 'x-workspace-asset' => ['kind' => 'image', 'role' => 'image_ref']];
        $revision->update(['definition' => $definition]);
        $asset = app(AssetService::class)->store($user, UploadedFile::fake()->image('ref.png', 8, 8), InputRole::ImageRef);
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['prompt' => 'A cup', 'image' => $asset->id]))[0];
        $seen = null;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($job, &$seen) {
            if (str_contains($request->url(), '/storage/upload/initiate')) {
                $seen = [$job->fresh()->stage, $job->fresh()->submitted_at];

                return Http::response('', 503);
            }

            return Http::response(['request_id' => 'never'], 200);
        });
        $service->process($job->id);

        // An upload that hangs or dies here has not asked for a paid generation, so it must stay recoverable.
        $this->assertSame(['preparing', null], $seen);
        $job->refresh();
        $this->assertSame(['failed', 'released', null], [$job->status, $job->billing_status, $job->submitted_at]);
        $this->assertSame(100, UserToken::getBalance($user->id));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'queue.fal.run'));
    }

    public function test_a_request_the_provider_never_finishes_goes_to_review_instead_of_polling_forever(): void
    {
        [$user, $model] = $this->fixture();
        Http::fake(['https://queue.fal.run/*' => Http::response(['status' => 'IN_QUEUE'])]);
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['prompt' => 'A cup']))[0];
        $job->update(['status' => 'processing', 'stage' => 'rendering', 'upstream_job_id' => 'request-1', 'submitted_at' => now()->subMinutes(5)]);
        $service->poll($job->id);
        $this->assertSame(['processing', 'rendering'], [$job->fresh()->status, $job->fresh()->stage], 'a young request keeps polling');
        Queue::assertPushed(PollWorkspaceMediaJob::class);

        $this->travel(7)->hours();
        $service->poll($job->id);
        $job->refresh();
        $this->assertSame(['uncertain', 'result_uncertain', 'reserved'], [$job->status, $job->stage, $job->billing_status]);
        $this->assertNull($job->next_poll_at);
        $this->assertSame(90, UserToken::getBalance($user->id), 'tokens stay reserved for review, neither refunded nor settled');
        $service->recover();
        $this->assertSame('result_uncertain', $job->fresh()->stage, 'recovery does not revive a job under review');
    }

    public function test_a_changed_provider_connection_sends_an_accepted_request_to_review(): void
    {
        [$user, $model] = $this->fixture();
        Http::fake(['https://queue.fal.run/*' => Http::response(['status' => 'IN_QUEUE'])]);
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['prompt' => 'A cup']))[0];
        $job->update(['status' => 'processing', 'stage' => 'rendering', 'upstream_job_id' => 'request-1', 'submitted_at' => now()]);
        // Saving the same key again (as the admin form does) is not a connection change.
        $model->provider->fill(['api_key' => 'test-only-key'])->save();
        $service->poll($job->id);
        $this->assertSame(['processing', 'rendering'], [$job->fresh()->status, $job->fresh()->stage]);

        $model->provider->fresh()->fill(['api_key' => 'another-account-key'])->save();
        $this->travel(1)->minutes();
        $service->poll($job->id);
        $job->refresh();
        $this->assertSame(['uncertain', 'result_uncertain', 'reserved'], [$job->status, $job->stage, $job->billing_status]);
        $this->assertSame(1, collect(Http::recorded())->count(), 'the other account is never asked about this request');
    }

    public function test_save_only_retry_preserves_structured_outputs_and_settles_once_without_a_provider_request(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['prompt' => 'A cup']))[0];
        $result = ['boxes' => [[1, 2, 3, 4]], 'scores' => [0.92], 'text' => 'An intact original caption'];
        $job->update(['status' => 'save_failed', 'stage' => 'save_failed', 'provider_result' => $result,
            'result_received_at' => now(), 'submitted_at' => now(), 'error_message' => 'Storage was full.']);
        $service->retrySave($user, $job->job_id);
        $service->poll($job->id);
        $service->poll($job->id);
        $saved = $job->fresh();
        $this->assertSame('completed', $saved->status);
        $this->assertSame('settled', $saved->billing_status);
        $this->assertSame($result, (array) $service->payload($saved)['result_data']);
        $this->assertSame(90, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('token_reservations', 1);
        Http::assertNothingSent();
    }

    public function test_nested_files_are_private_originals_and_unsafe_svg_is_download_only(): void
    {
        [$user, $model] = $this->fixture();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path d="M0 0"/></svg>';
        $archive = "PK\x03\x04".str_repeat("\0", 64);
        Http::fake([
            'https://files.example.test/mask.svg' => Http::response($svg, 200, ['Content-Type' => 'image/svg+xml']),
            'https://files.example.test/meshes.zip' => Http::response($archive, 200, ['Content-Type' => 'application/zip']),
        ]);
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['prompt' => 'A cup']))[0];
        $job->update(['status' => 'save_failed', 'stage' => 'save_failed', 'result_received_at' => now(),
            'provider_result' => ['masks' => [['url' => 'https://files.example.test/mask.svg', 'file_name' => 'mask.svg', 'content_type' => 'image/svg+xml']],
                'meshes' => ['archive' => ['url' => 'https://files.example.test/meshes.zip', 'file_name' => 'meshes.zip']],
                'boxes' => [[1, 2, 3, 4]], 'citation' => ['url' => 'https://source.example.test/article', 'title' => 'Evidence']]]);
        $service->retrySave($user, $job->job_id);
        $service->poll($job->id);
        $saved = $job->fresh();
        $this->assertSame('completed', $saved->status);
        $payload = json_decode(json_encode($service->payload($saved), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $mask = collect($payload['outputs'])->firstWhere('name', 'mask.svg');
        $mesh = collect($payload['outputs'])->firstWhere('name', 'meshes.zip');
        $this->assertFalse($mask['previewable']);
        $this->assertNull($mask['url']);
        $this->assertSame($mask['download_url'], $payload['result_data']['masks'][0]['url']);
        $this->assertSame([[1, 2, 3, 4]], $payload['result_data']['boxes']);
        $this->assertSame('https://source.example.test/article', $payload['result_data']['citation']['url']);
        $this->assertSame($svg, Storage::disk('local')->get($service->resolveOwnedOutput($user, $job->job_id, $mask['id'])['path']));
        $this->assertSame($archive, Storage::disk('local')->get($service->resolveOwnedOutput($user, $job->job_id, $mesh['id'])['path']));
        $this->actingAs($user)->get($mask['download_url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create())->get($mask['download_url'])->assertNotFound();
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' || str_contains($request->url(), 'source.example.test'));
    }

    public function test_full_quota_keeps_original_result_for_save_only_recovery(): void
    {
        [$user, $model] = $this->fixture();
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['prompt' => 'A cup']))[0];
        $result = ['caption' => str_repeat('Intact provider output ', 20)];
        $job->update(['status' => 'save_failed', 'stage' => 'save_failed', 'result_received_at' => now(), 'provider_result' => $result]);
        config(['storage_quota.base_bytes' => 1]);
        $service->retrySave($user, $job->job_id);
        $service->poll($job->id);
        $this->assertSame('save_failed', $job->fresh()->status);
        $this->assertSame($result, $job->fresh()->provider_result);
        $this->assertSame('reserved', $job->fresh()->billing_status);
        config(['storage_quota.base_bytes' => 100000]);
        $service->retrySave($user, $job->job_id);
        $service->poll($job->id);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame($result, $job->fresh()->result_data);
        $this->assertSame(90, UserToken::getBalance($user->id));
        Http::assertNothingSent();
    }

    public function test_native_openai_inline_image_is_saved_without_losing_the_original_bytes(): void
    {
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $provider = AiProviderProfile::create(['slug' => 'workspace-openai', 'name' => 'Images', 'protocol' => 'openai',
            'base_url' => 'https://images.example.test/v1', 'api_key' => 'test-only-key', 'is_enabled' => true]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'native-image-fixture',
            'upstream_model_id' => 'gpt-image-1', 'display_name' => 'Native image', 'category' => 'image',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 10]);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true);
        Http::fake(['https://images.example.test/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode($bytes)]]])]);
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['prompt' => 'A cup']))[0];
        app(ImageGenerationService::class)->process($job->id);
        $saved = $job->fresh();
        $this->assertSame('completed', $saved->status);
        $this->assertSame($bytes, Storage::disk('local')->get($saved->asset_paths[0]['path']));
        $this->assertSame('settled', $saved->billing_status);
        $this->assertSame(90, UserToken::getBalance($user->id));
    }

    public function test_a_multi_image_native_request_is_presented_as_one_set_of_variations(): void
    {
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $provider = AiProviderProfile::create(['slug' => 'workspace-openai', 'name' => 'Images', 'protocol' => 'openai',
            'base_url' => 'https://images.example.test/v1', 'api_key' => 'test-only-key', 'is_enabled' => true]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'native-image-fixture',
            'upstream_model_id' => 'dall-e-2', 'display_name' => 'Native image', 'category' => 'image',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 10]);
        $service = app(WorkspaceMediaService::class);

        // The coordinator runs one generation per job, so three images are three jobs: they must read as one set.
        $jobs = $service->create($user, [...$this->request($model, ['prompt' => 'A cup']), 'count' => 3, 'expected_price_tokens' => 10]);
        $this->assertCount(3, $jobs);
        $ids = array_map(fn (ImageJob $job): string => 'image:'.$job->job_id, $jobs);
        foreach ($jobs as $index => $job) {
            $this->assertSame(['jobs' => $ids, 'index' => $index], $service->payload($job->fresh())['batch']);
        }
        $single = $service->create($user, [...$this->request($model, ['prompt' => 'Tea']), 'expected_price_tokens' => 10])[0];
        $this->assertNull($service->payload($single->fresh())['batch']);
    }

    public function test_result_document_preserves_empty_object_and_array_shapes(): void
    {
        [$user, $model, $revision] = $this->fixture();
        $definition = $revision->definition;
        $definition['output_schema'] = ['type' => 'object', 'properties' => [
            'metadata' => ['type' => 'object'], 'masks' => ['type' => 'array', 'items' => ['type' => 'object']],
        ]];
        $revision->update(['definition' => $definition]);
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['prompt' => 'A cup']))[0];
        $job->update(['status' => 'save_failed', 'stage' => 'save_failed', 'result_received_at' => now(),
            'provider_result' => ['metadata' => [], 'masks' => []]]);
        $service->retrySave($user, $job->job_id);
        $service->poll($job->id);
        $saved = $job->fresh();
        $this->assertSame('completed', $saved->status);
        $output = collect($service->payload($saved)['outputs'])->firstWhere('name', 'result.json');
        $original = $service->resolveOwnedOutput($user, $saved->job_id, $output['id']);
        $document = json_decode(Storage::disk('local')->get($original['path']), false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $document->metadata);
        $this->assertSame([], $document->masks);
        $this->assertInstanceOf(\stdClass::class, $service->payload($saved)['result_data']->metadata);
    }

    public function test_native_image_unknown_acceptance_is_never_refunded_or_resubmitted_by_stale_reconciliation(): void
    {
        [$user, , $model] = $this->nativeImage();
        // A successful HTTP response without a task handle is unknown acceptance, not a rejection.
        Http::fake(['https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['status' => 'queued'])]);
        $images = app(ImageGenerationService::class);
        $unacknowledged = $this->startNativeImage($user, $model);
        $images->process($unacknowledged->id);
        $interrupted = $this->startNativeImage($user, $model);
        $interrupted->update(['status' => 'processing', 'stage' => 'submitting', 'processing_started_at' => now(),
            'processing_token' => (string) Str::uuid()]);
        $this->travel(10)->minutes();
        $this->assertSame(1, $images->reconcileStaleReservations(3));
        $this->assertSame(0, $images->reconcileStaleReservations(3));
        $this->assertSame(0, $images->redispatchStalePending(3));
        foreach ([$unacknowledged, $interrupted] as $job) {
            $images->process($job->id);
            $images->poll($job->id);
            $job->refresh();
            $this->assertSame(['processing', 'submission_uncertain', 'reserved'], [$job->status, $job->stage, $job->billing_status]);
            $payload = app(WorkspaceMediaService::class)->payload($job);
            $this->assertSame('uncertain', $payload['status']);
            $this->assertFalse($payload['can_delete']);
        }
        $this->assertSame(80, UserToken::getBalance($user->id));
        Http::assertSentCount(1);
    }

    public function test_native_image_late_acceptance_after_stale_reconciliation_still_polls_the_original_task(): void
    {
        [$user, , $model] = $this->nativeImage();
        $images = app(ImageGenerationService::class);
        Http::fake(['https://kinovi.ai/api/v1/jobs/createTask' => function () use ($images) {
            // The submitting worker outlives its lease; reconciliation must not refund or strand its outcome.
            $this->travel(6)->minutes();
            $images->reconcileStaleReservations(3);

            return Http::response(['taskId' => 'late-task']);
        }]);
        $job = $this->startNativeImage($user, $model);
        $images->process($job->id);
        $job->refresh();
        $this->assertSame(['rendering', 'late-task', 'reserved'], [$job->stage, $job->upstream_job_id, $job->billing_status]);
        $this->assertNull($job->error_message);
        $this->assertSame(90, UserToken::getBalance($user->id));
        Http::assertSentCount(1);
    }

    public function test_native_image_with_a_changed_connection_is_refunded_without_a_provider_request(): void
    {
        [$user, $provider, $model] = $this->nativeImage();
        $job = $this->startNativeImage($user, $model);
        $provider->update(['api_key' => 'rotated-test-only-key']);
        app(ImageGenerationService::class)->process($job->id);
        $job->refresh();
        $this->assertSame(['failed', 'released'], [$job->status, $job->billing_status]);
        $this->assertStringContainsString('No generation was submitted', (string) $job->error_message);
        $this->assertSame(100, UserToken::getBalance($user->id));
        Http::assertNothingSent();
    }

    public function test_download_names_keep_the_utf8_original_with_an_ascii_fallback(): void
    {
        [$user, $model] = $this->fixture();
        Http::fake(['https://files.example.test/archive' => Http::response("PK\x03\x04".str_repeat("\0", 64), 200)]);
        $service = app(WorkspaceMediaService::class);
        $job = $service->create($user, $this->request($model, ['prompt' => 'A cup']))[0];
        $job->update(['status' => 'save_failed', 'stage' => 'save_failed', 'result_received_at' => now(),
            'provider_result' => ['archive' => ['url' => 'https://files.example.test/archive', 'file_name' => 'Résumé 100%.zip']]]);
        $service->retrySave($user, $job->job_id);
        $service->poll($job->id);
        $output = collect($service->payload($job->fresh())['outputs'])->firstWhere('name', 'Résumé 100%.zip');
        $this->actingAs($user)->get($output['download_url'])->assertOk()->assertHeader('Content-Disposition',
            "attachment; filename=\"Resume 100_.zip\"; filename*=utf-8''R%C3%A9sum%C3%A9%20100%25.zip");
    }

    public function test_per_second_price_uses_the_whole_seconds_actually_sent_upstream(): void
    {
        [$user, $model, $revision] = $this->fixture();
        UserToken::topup($user->id, 100);
        $definition = $revision->definition;
        // Fal schemas commonly publish durations as string enums, including a provider-chosen "auto".
        $definition['input_schema']['properties']['duration'] = ['type' => 'string', 'enum' => ['auto', '5', '10'], 'default' => 'auto'];
        $revision->update(['definition' => $definition,
            'curation_overrides' => ['pricing' => [...$revision->curation_overrides['pricing'], 'unit' => 'second']]]);
        $service = app(WorkspaceMediaService::class);
        $billing = $service->capabilities($user, $model->model_id)['capabilities']['text_to_image']['billing'];
        $this->assertSame(['per_second', 'duration'], [$billing['mode'], $billing['duration_field']]);
        try {
            $service->create($user, $this->request($model, ['prompt' => 'A cup']));
            $this->fail('A provider-chosen duration cannot be priced per second.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('inputs.duration', $exception->errors());
        }
        $job = $service->create($user, [...$this->request($model, ['prompt' => 'A cup', 'duration' => '10']), 'expected_price_tokens' => 100])[0];
        $this->assertSame([100, '10'], [$job->price_tokens, $job->normalized_inputs['duration']]);
        $this->assertSame(100, UserToken::getBalance($user->id));
    }

    public function test_http_submission_keeps_exact_schema_strings_and_replays_after_storage_fills(): void
    {
        [$user, $model, $revision] = $this->fixture();
        $definition = $revision->definition;
        $definition['input_schema']['properties']['system_prompt'] = ['type' => 'string', 'default' => ''];
        $revision->update(['definition' => $definition]);
        // A source default of "" and significant whitespace are provider data, not request noise to trim or null.
        $request = $this->request($model, ['prompt' => "  A cup\n", 'system_prompt' => '']);
        $first = $this->actingAs($user)->postJson('/api/media/workspace/jobs', $request)->assertStatus(202);
        $this->assertSame(['prompt' => "  A cup\n", 'system_prompt' => ''], WorkspaceMediaJob::query()->sole()->normalized_inputs);

        // The paid original may itself fill the Library: its key must still resolve instead of inviting a new key.
        config(['storage_quota.base_bytes' => 0]);
        $this->actingAs($user)->postJson('/api/media/workspace/jobs', $request)->assertStatus(202)
            ->assertJsonPath('job.id', $first->json('job.id'));
        $this->actingAs($user)->postJson('/api/media/workspace/jobs', [...$request, 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('storage');
        $this->assertDatabaseCount('token_reservations', 1);
        $this->assertSame(90, UserToken::getBalance($user->id));
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['permissions' => ['image_generator' => true, 'chat' => true]]);
        UserToken::topup($user->id, 100);
        $provider = AiProviderProfile::create(['slug' => 'workspace-fal', 'name' => 'Fal', 'protocol' => 'fal',
            'base_url' => 'https://fal.run', 'api_key' => 'test-only-key', 'is_enabled' => true,
            'status' => 'healthy', 'authenticated_at' => now()]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'public-workspace-model',
            'upstream_model_id' => 'fal-ai/workspace-fixture', 'display_name' => 'Workspace fixture', 'category' => 'image',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 10]);
        $schema = ['type' => 'object', 'properties' => ['prompt' => ['type' => 'string'],
            'settings' => ['type' => 'object', 'properties' => ['seed' => ['type' => 'integer']]]], 'required' => ['prompt']];
        $revision = MediaCapabilityRevision::create(['ai_model_profile_id' => $model->id, 'operation' => 'text_to_image',
            'contract_version' => 2, 'revision' => 1, 'status' => 'published', 'published_at' => now(),
            'source_hash' => str_repeat('a', 64), 'source_schema' => ['fixture' => true],
            'definition' => ['model_public_id' => $model->model_id, 'operation' => 'text_to_image', 'output_kind' => 'image',
                'contract_version' => 2, 'inputs' => [], 'params' => [], 'input_schema' => $schema, 'output_schema' => ['type' => 'object']],
            'provider_bindings' => ['adapter' => 'fal_schema_v2', 'endpoint' => 'fal-ai/workspace-fixture', 'transport' => 'queue',
                'queue_root' => 'fal-ai/workspace-fixture', 'request_schema' => $schema, 'output_schema' => ['type' => 'object']],
            'curation_overrides' => ['pricing' => ['token_cost' => 10, 'unit' => 'request', 'reviewed_by' => $user->id,
                'reviewed_at' => now()->toISOString(), 'variable_configuration' => true]],
        ]);

        return [$user, $model, $revision];
    }

    private function nativeImage(): array
    {
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $provider = AiProviderProfile::create(['slug' => 'kinovi-ai', 'name' => 'Kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'test-only-key', 'is_enabled' => true]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'kinovi-ai/gpt-image-2',
            'upstream_model_id' => 'gpt-image-2', 'display_name' => 'GPT Image 2', 'category' => 'image',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 10]);

        return [$user, $provider, $model];
    }

    private function startNativeImage(User $user, AiModelProfile $model): ImageJob
    {
        return app(MediaGenerationCoordinator::class)->startImage($user, $model, MediaOperation::TextToImage,
            ['prompt' => 'A red apple', 'size' => '1024x1024'], 'studio-image');
    }

    private function request(AiModelProfile $model, array $inputs): array
    {
        return ['model' => $model->model_id, 'operation' => 'text_to_image', 'inputs' => $inputs,
            'expected_capability_hash' => app(CapabilityResolver::class)->resolve($model, MediaOperation::TextToImage, schemaContracts: true)->sourceHash,
            'expected_price_tokens' => 10, 'idempotency_key' => (string) Str::uuid()];
    }
}
