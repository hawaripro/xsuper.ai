<?php

namespace Tests\Feature;

use App\Exceptions\AiProxyException;
use App\Jobs\ProcessVideoJob;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\TokenTransaction;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AiProviderEndpoint;
use App\Services\AiProxyService;
use App\Services\GeneratedVideoStore;
use App\Services\VideoGenerationService;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MediaGenerationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);
        $this->mock(AiProviderEndpoint::class, function ($mock): void {
            $mock->shouldReceive('normalize')->andReturnUsing(fn (string $url): string => rtrim($url, '/'));
            $mock->shouldReceive('requestOptions')->andReturn(['allow_redirects' => false, 'proxy' => '', 'verify' => true]);
        });
    }

    public function test_catalog_classifies_media_from_sparse_openai_metadata(): void
    {
        $provider = $this->provider();
        Http::fake(['https://media.example.test/v1/models' => Http::response(['data' => [
            ['id' => 'gemini-3.1-flash-image', 'owned_by' => 'google'],
            ['id' => 'gemini-2.5-flash-image', 'owned_by' => 'google'],
            ['id' => 'gpt-image-2', 'owned_by' => 'openai'],
            ['id' => 'seedance-2.5', 'owned_by' => 'bytedance'],
            ['id' => 'claude-opus-4.6', 'owned_by' => 'anthropic'],
        ]])]);
        $catalog = collect(app(AiProxyService::class)->fetchCatalog($provider))->keyBy('id');
        $this->assertSame('image', $catalog['gemini-3.1-flash-image']['category']);
        $this->assertSame('image', $catalog['gemini-2.5-flash-image']['category']);
        $this->assertSame(['image'], $catalog['gpt-image-2']['output_modalities']);
        $this->assertSame('video', $catalog['seedance-2.5']['category']);
        $this->assertSame('chat', $catalog['claude-opus-4.6']['category']);
    }

    public function test_image_batch_omits_unsupported_fields_and_charges_tokens_linearly(): void
    {
        $provider = $this->provider();
        $this->model($provider, 'gpt-image-2', 'image', 15);
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        Http::fake(['https://media.example.test/v1/images/generations' => Http::response(['data' => [['b64_json' => $this->png()]]])]);

        $response = $this->actingAs($user)->postJson('/api/images', ['model' => 'gpt-image-2', 'prompt' => 'A ceramic cup', 'size' => 'auto', 'n' => 2]);
        $response->assertCreated()->assertJsonPath('job.status', 'completed')->assertJsonPath('job.tokens_reserved', 30)->assertJsonPath('balance', 70);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => ! isset($request['size']) && ! isset($request['n']) && $request['model'] === 'gpt-image-2');
        $job = ImageJob::firstOrFail();
        $this->assertCount(2, $job->asset_paths);
        $this->get($response->json('job.result_urls.0'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $other = User::factory()->create();
        $this->actingAs($other)->get($response->json('job.result_urls.0'))->assertNotFound();
        $this->assertSame(70, UserToken::getBalance($user->id));
    }

    public function test_provider_image_url_is_copied_to_private_owner_scoped_storage(): void
    {
        $provider = $this->provider();
        $this->model($provider, 'gemini-2.5-flash-image', 'image', 15);
        $user = User::factory()->create();
        UserToken::topup($user->id, 30);
        $bytes = base64_decode($this->png());
        Http::fake([
            'https://media.example.test/v1/images/generations' => Http::response(['data' => [['url' => 'https://cdn.example.test/asset.png?signature=private-result']]]),
            'https://cdn.example.test/asset.png*' => Http::response($bytes, 200, ['Content-Type' => 'image/png']),
        ]);
        $response = $this->actingAs($user)->postJson('/api/images', ['model' => 'gemini-2.5-flash-image', 'prompt' => 'A quiet garden', 'size' => '1024x1024', 'n' => 1])->assertCreated();
        $assetUrl = $response->json('job.result_urls.0');
        $this->assertStringStartsWith('/api/images/', $assetUrl);
        $this->assertStringNotContainsString('private-result', $response->getContent());
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'cdn.example.test') && ! $request->hasHeader('Authorization'));
        Http::fake(fn () => Http::response('', 410));
        $this->get($assetUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(15, UserToken::getBalance($user->id));
        $this->actingAs(User::factory()->create())->get($assetUrl)->assertNotFound();
    }

    public function test_admin_image_generation_requires_pricing_and_token_balance_before_dispatch(): void
    {
        $provider = $this->provider();
        $model = $this->model($provider, 'gemini-2.5-flash-image', 'image', null);
        $admin = User::factory()->create(['role' => 'admin']);
        $input = ['model' => $model->model_id, 'prompt' => 'A quiet garden', 'size' => '1024x1024', 'n' => 2];

        $this->actingAs($admin)->postJson('/api/images', $input)->assertStatus(503);
        $this->assertDatabaseCount('image_jobs', 0);
        Http::assertNothingSent();

        $model->update(['token_cost' => 15]);
        $this->postJson('/api/images', $input)->assertUnprocessable()->assertJsonValidationErrors('tokens');
        $this->assertDatabaseCount('image_jobs', 0);
        Http::assertNothingSent();

        UserToken::topup($admin->id, 45);
        Http::fake(['https://media.example.test/v1/images/generations' => Http::response(['data' => [['b64_json' => $this->png()]]])]);
        $response = $this->postJson('/api/images', $input)->assertCreated()
            ->assertJsonPath('job.billing_mode', 'tokens')->assertJsonPath('job.tokens_reserved', 30)
            ->assertJsonPath('job.billing_status', 'settled')->assertJsonPath('balance', 15);
        $this->get($response->json('job.result_urls.0'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(15, UserToken::getBalance($admin->id));
        Http::assertSentCount(2);
    }

    public function test_admin_video_requests_require_positive_cost_and_reserve_each_video(): void
    {
        $provider = $this->provider();
        $model = $this->model($provider, 'seedance-2.5', 'video', 0);
        $admin = User::factory()->create(['role' => 'admin']);
        $input = array_replace($this->videoInput(), ['count' => 2]);

        $this->actingAs($admin)->getJson('/api/v/models')->assertOk()->assertJsonCount(0, 'models');
        $this->postJson('/api/v/gen', $input)->assertUnprocessable()->assertJsonValidationErrors('model');
        $this->assertDatabaseCount('video_jobs', 0);
        Http::assertNothingSent();

        $model->update(['token_cost' => 200]);
        $this->getJson('/api/v/models')->assertOk()->assertJsonPath('models.0.token_cost', 200)
            ->assertJsonPath('models.0.billing_mode', 'tokens');
        $this->postJson('/api/v/gen', $input)->assertUnprocessable()->assertJsonValidationErrors('tokens');
        $this->assertDatabaseCount('video_jobs', 0);

        UserToken::topup($admin->id, 500);
        $this->postJson('/api/v/gen', $input)->assertStatus(202)->assertJsonCount(2, 'jobs')
            ->assertJsonPath('billing_mode', 'tokens')->assertJsonPath('tokens_used', 400)
            ->assertJsonPath('jobs.0.tokens_reserved', 200)->assertJsonPath('jobs.1.tokens_reserved', 200)
            ->assertJsonPath('jobs.0.can_cancel', true)->assertJsonPath('balance', 100);
        $this->assertSame(100, UserToken::getBalance($admin->id));
        Http::assertNothingSent();
    }

    public function test_failed_image_refunds_reserved_tokens_without_replaying_generation(): void
    {
        $provider = $this->provider();
        $this->model($provider, 'gemini-3.1-flash-image', 'image', 15);
        $user = User::factory()->create();
        UserToken::topup($user->id, 30);
        Http::fake(['https://media.example.test/v1/images/generations' => Http::response(['error' => 'private transport error'], 503)]);
        $this->actingAs($user)->postJson('/api/images', ['model' => 'gemini-3.1-flash-image', 'prompt' => 'A still life', 'size' => '1024x1024', 'n' => 1])
            ->assertStatus(503)->assertJsonPath('job.billing_status', 'released');
        $this->assertSame(30, UserToken::getBalance($user->id));
        Http::assertSentCount(1);
    }

    public function test_video_batch_submits_original_prompts_and_reserved_snapshots_without_a_chat_model(): void
    {
        [$user, $provider] = $this->videoFixture();
        Http::fake(['https://media.example.test/v1/videos/generations' => Http::sequence()
            ->push(['id' => 'upstream-first', 'status' => 'queued'])
            ->push(['id' => 'upstream-second', 'status' => 'queued'])]);
        $service = app(VideoGenerationService::class);
        $jobs = $service->create($user, array_replace($this->videoInput(), [
            'prompt' => '  A safe studio product shot  ', 'count' => 2,
            'cta' => '  Explore the collection  ', 'ugc_variation' => true,
        ]));
        AiModelProfile::query()->where('provider_id', $provider->id)->update([
            'token_cost' => 350, 'upstream_model_id' => 'replacement-video',
        ]);

        foreach ($jobs as $job) {
            $service->process($job->id);
            $service->process($job->id);
            $this->assertSame('rendering', $job->fresh()->stage);
            $this->assertNull($job->fresh()->improved_prompt);
            $this->assertDatabaseHas('token_reservations', [
                'reference_id' => $job->billing_reference_id, 'amount_tokens' => 200, 'status' => 'reserved',
            ]);
        }

        $this->assertSame(100, UserToken::getBalance($user->id));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request['model'] === 'seedance-2.5'
            && $request['prompt'] === "A safe studio product shot\nCall to action: Explore the collection");
        Http::assertSent(fn ($request): bool => $request['model'] === 'seedance-2.5'
            && $request['prompt'] === "A safe studio product shot\nCall to action: Explore the collection\nCreate variation 2 with a distinct camera composition while preserving the same subject and message.");
    }

    public function test_changed_video_connection_refunds_without_sending_to_another_provider(): void
    {
        [$user, $provider] = $this->videoFixture();
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $provider->update(['base_url' => 'https://replacement.example.test/v1']);

        $service->process($job->id);
        $service->process($job->id);
        (new ProcessVideoJob($job->id))->failed(new RuntimeException('Repeated failure callback'));

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('released', $job->fresh()->billing_status);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        Http::assertNothingSent();
    }

    public function test_server_poll_persists_video_result_and_settles_without_browser_requests(): void
    {
        [$user] = $this->videoFixture();
        Http::fake([
            'https://media.example.test/v1/videos/generations' => Http::response(['id' => 'upstream-task', 'status' => 'queued']),
            'https://media.example.test/v1/videos/generations/upstream-task' => Http::response(['status' => 'completed', 'video_url' => 'https://cdn.example.test/finished.mp4?private=upstream']),
            'https://cdn.example.test/finished.mp4*' => Http::response($this->mp4(), 200, ['Content-Type' => 'video/mp4']),
        ]);
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $this->assertSame(300, UserToken::getBalance($user->id));
        $service->process($job->id);
        $job->refresh();
        $this->assertSame('rendering', $job->stage);
        $this->assertNull($job->improved_prompt);
        $beforeCancellation = $job->getAttributes();
        $this->actingAs($user)->postJson('/api/v/'.$job->job_id.'/cancel')->assertStatus(409)
            ->assertJsonPath('reason_code', 'submission_started')->assertJsonPath('job.can_cancel', false)
            ->assertJsonPath('job.stage', 'rendering')->assertJsonPath('job.billing_status', 'reserved')
            ->assertJsonPath('balance', 300);
        $this->assertSame($beforeCancellation, $job->fresh()->getAttributes());
        $job->update(['next_poll_at' => now()->subSecond()]);
        $service->poll($job->id);
        $service->poll($job->id);
        $service->process($job->id);
        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertSame('/api/v/'.$job->job_id.'/asset', $job->video_url);
        $this->assertTrue(Storage::disk('local')->exists(GeneratedVideoStore::path($job->job_id)));
        $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);
        $user->forceFill(['permissions' => ['video_generator' => true]])->save();
        $this->actingAs($user)->get($job->video_url)->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')->assertHeader('X-Content-Type-Options', 'nosniff');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get($job->video_url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $other = User::factory()->create(['permissions' => ['video_generator' => true]]);
        $this->actingAs($other)->get($job->video_url)->assertNotFound();
        $this->assertSame('settled', $job->billing_status);
        $this->assertSame(300, UserToken::getBalance($user->id));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/videos/generations') && $request['prompt'] === 'A safe studio product shot' && ! isset($request['duration']) && ! isset($request['aspect_ratio']));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'cdn.example.test') && ! $request->hasHeader('Authorization'));
    }

    public function test_delayed_submission_failure_preserves_rendering_until_the_poller_settles(): void
    {
        [$user] = $this->videoFixture();
        Http::fake([
            'https://media.example.test/v1/videos/generations' => Http::response(['id' => 'upstream-task', 'status' => 'queued']),
            'https://media.example.test/v1/videos/generations/upstream-task' => Http::response(['status' => 'completed', 'video_url' => 'https://cdn.example.test/finished.mp4']),
            'https://cdn.example.test/finished.mp4' => Http::response($this->mp4(), 200, ['Content-Type' => 'video/mp4']),
        ]);
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $worker = new ProcessVideoJob($job->id);
        $worker->handle($service);
        $rendering = $job->fresh()->getAttributes();

        $this->travel(601)->seconds();
        $worker->failed(new RuntimeException('Delayed submission queue acknowledgement'));
        $worker->failed(new RuntimeException('Repeated failure callback'));
        $service->process($job->id);

        $this->assertSame($rendering, $job->fresh()->getAttributes());
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->assertDatabaseHas('token_reservations', ['reference_id' => $job->billing_reference_id, 'status' => 'reserved']);
        $service->reconcile();
        $service->poll($job->id);

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('settled', $job->fresh()->billing_status);
        $this->assertDatabaseHas('token_reservations', ['reference_id' => $job->billing_reference_id, 'status' => 'settled']);
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->assertSame(0, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        Storage::disk('local')->assertExists(GeneratedVideoStore::path($job->job_id));
        Http::assertSentCount(3);
    }

    #[DataProvider('interruptedSubmissionPhases')]
    public function test_interrupted_submission_worker_refunds_its_reservation_once(string $status, string $stage): void
    {
        [$user] = $this->videoFixture();
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $job->update([
            'status' => $status, 'stage' => $stage,
            'processing_started_at' => $status === 'processing' ? now() : null,
        ]);
        Queue::fake();

        $worker = new ProcessVideoJob($job->id);
        $worker->failed(new RuntimeException('Submission worker interrupted'));
        $worker->failed(new RuntimeException('Repeated failure callback'));
        $service->process($job->id);
        $service->poll($job->id);
        $service->reconcile();

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('released', $job->fresh()->billing_status);
        $this->assertDatabaseHas('token_reservations', ['reference_id' => $job->billing_reference_id, 'status' => 'released']);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function interruptedSubmissionPhases(): array
    {
        return [
            'before claiming' => ['pending', 'queued'],
            'during submission' => ['processing', 'submitting'],
            'during immediate result saving' => ['processing', 'saving'],
        ];
    }

    public function test_abandoned_saving_claim_refunds_once_after_the_lease_expires(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user] = $this->videoFixture();
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $job->forceFill([
            'status' => 'processing', 'stage' => 'saving', 'upstream_job_id' => 'upstream-task',
            'processing_started_at' => now(), 'next_poll_at' => null,
        ])->save();

        Queue::fake();
        $this->travel(359)->seconds();
        $this->assertSame(0, $service->reconcile());
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->travel(2)->seconds();
        $this->assertSame(1, $service->reconcile());
        $this->assertSame(0, $service->reconcile());
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('released', $job->fresh()->billing_status);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('staleSubmissionPhases')]
    public function test_stale_unsubmitted_work_is_refunded_once_without_resubmission(string $stage): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user] = $this->videoFixture();
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $job->update(['status' => 'processing', 'stage' => $stage, 'processing_started_at' => now(), 'next_poll_at' => null]);
        Queue::fake();

        $this->travel(359)->seconds();
        $this->assertSame(0, $service->reconcile());
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->travel(2)->seconds();
        $this->assertSame(1, $service->reconcile());
        $this->assertSame(0, $service->reconcile());
        $service->process($job->id);

        $this->assertSame('failed', $job->fresh()->stage);
        $this->assertSame('released', $job->fresh()->billing_status);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function staleSubmissionPhases(): array
    {
        return [
            'direct submission' => ['submitting'],
            'historical interrupted review' => ['reviewing'],
        ];
    }

    public function test_saving_claim_excludes_competing_polls_during_a_slow_download(): void
    {
        [$user] = $this->videoFixture();
        $service = app(VideoGenerationService::class);
        $job = null;
        $downloads = 0;
        $stageDuringDownload = null;
        Http::fake([
            'https://media.example.test/v1/videos/generations' => Http::response(['id' => 'upstream-task', 'status' => 'queued']),
            'https://media.example.test/v1/videos/generations/upstream-task' => Http::response(['status' => 'completed', 'video_url' => 'https://cdn.example.test/finished.mp4']),
            'https://cdn.example.test/finished.mp4' => function () use ($service, &$job, &$downloads, &$stageDuringDownload) {
                $downloads++;
                $stageDuringDownload = $service->payload($job->fresh())['stage'];
                $this->travel(70)->seconds();
                (new ProcessVideoJob($job->id))->failed(new RuntimeException('Delayed submission failure during poll-owned saving'));
                $service->poll($job->id);
                $service->reconcile();

                return Http::response($this->mp4(), 200, ['Content-Type' => 'video/mp4']);
            },
        ]);
        $job = $service->create($user, $this->videoInput())[0];
        $service->process($job->id);
        $this->travel(9)->seconds();
        $service->poll($job->id);

        $this->assertSame('saving', $stageDuringDownload);
        $this->assertSame(1, $downloads);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('settled', $job->fresh()->billing_status);
        $this->assertSame(300, UserToken::getBalance($user->id));
        Http::assertSentCount(3);
    }

    public function test_video_result_with_trailing_dot_host_is_rejected_before_download(): void
    {
        [$user] = $this->videoFixture();
        Http::fake([
            'https://media.example.test/v1/videos/generations' => Http::response(['status' => 'completed', 'video_url' => 'https://cdn.example.test./finished.mp4']),
        ]);

        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $service->process($job->id);

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('released', $job->billing_status);
        $this->assertSame(500, UserToken::getBalance($user->id));
        Http::assertSentCount(1);
    }

    public function test_immediate_video_result_survives_a_submission_crossing_seconds(): void
    {
        [$user] = $this->videoFixture();
        Http::fake([
            'https://media.example.test/v1/videos/generations' => function () {
                $this->travel(2)->seconds();

                return Http::response(['status' => 'completed', 'video_url' => 'https://cdn.example.test/finished.mp4']);
            },
            'https://cdn.example.test/finished.mp4' => Http::response($this->mp4(), 200, ['Content-Type' => 'video/mp4']),
        ]);
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $service->process($job->id);

        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertSame('settled', $job->billing_status);
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->actingAs($user)->get($job->video_url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        Http::assertSentCount(2);
    }

    public function test_asset_connection_failure_refunds_a_saving_job_without_replay(): void
    {
        [$user] = $this->videoFixture();
        $this->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            public function requestOptions(string $baseUrl): array
            {
                if ($baseUrl === 'https://cdn.example.test') {
                    throw new AiProxyException('The provider endpoint is unavailable.', 503);
                }

                return ['allow_redirects' => false, 'proxy' => '', 'verify' => true];
            }
        });
        Http::fake([
            'https://media.example.test/v1/videos/generations' => Http::response(['id' => 'upstream-task', 'status' => 'queued']),
            'https://media.example.test/v1/videos/generations/upstream-task' => Http::response(['status' => 'completed', 'video_url' => 'https://cdn.example.test/finished.mp4']),
        ]);
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $service->process($job->id);
        $this->travel(9)->seconds();
        $service->poll($job->id);
        $service->reconcile();
        $service->poll($job->id);

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('released', $job->billing_status);
        $this->assertNull($job->next_poll_at);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        Http::assertSentCount(2);
        $this->assertSame([], Storage::disk('local')->allFiles('generated/videos'));
    }

    public function test_video_submission_is_not_redelivered_during_submission_and_download(): void
    {
        [$user] = $this->videoFixture();
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $queue = Queue::getFacadeRoot()->queue->connection('media');
        $queue->push((new ProcessVideoJob($job->id))->beforeCommit(), '', 'media');
        $queued = $queue->pop('media');
        $redelivered = false;
        Http::fake([
            'https://media.example.test/v1/videos/generations' => function () {
                $this->travel(89)->seconds();

                return Http::response(['status' => 'completed', 'video_url' => 'https://cdn.example.test/finished.mp4']);
            },
            'https://cdn.example.test/finished.mp4' => function () use ($queue, $service, &$redelivered) {
                $this->travel(179)->seconds();
                $redelivered = $queue->pop('media') !== null;
                $service->reconcile();

                return Http::response($this->mp4(), 200, ['Content-Type' => 'video/mp4']);
            },
        ]);
        $queued->fire();

        $this->assertFalse($redelivered);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('settled', $job->fresh()->billing_status);
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->actingAs($user)->get($job->fresh()->video_url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        Http::assertSentCount(2);
    }

    public function test_video_provider_rejection_refunds_once_without_replaying_generation(): void
    {
        [$user] = $this->videoFixture();
        Http::fake(['https://media.example.test/v1/videos/generations' => Http::response(['status' => 'rejected'])]);
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $service->process($job->id);
        $service->process($job->id);
        (new ProcessVideoJob($job->id))->failed(new RuntimeException('Delayed failure callback'));
        $service->reconcile();
        $job->refresh();
        $this->assertSame('failed', $job->stage);
        $this->assertSame('released', $job->billing_status);
        $this->assertNull($job->moderation_reason_code);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        Http::assertSentCount(1);
    }

    public function test_legacy_unsubmitted_video_releases_wallet_once_without_provider_dispatch(): void
    {
        $user = User::factory()->create();
        Wallet::credit($user->id, 1_000_000, 'Opening wallet');
        $reservation = Wallet::reserve($user->id, 200_000, 'legacy-video-job', ['service' => 'video', 'model' => 'legacy-video']);
        $job = VideoJob::create([
            'user_id' => $user->id, 'job_id' => 'legacy-local-id', 'mode' => 'prompt',
            'model' => 'legacy-video', 'prompt' => 'Original historical prompt',
            'status' => 'processing', 'stage' => 'legacy', 'billing_mode' => 'wallet',
            'billing_reference_id' => $reservation['reference_id'], 'billing_reserved_microusd' => 200_000,
            'billing_status' => 'reserved',
        ]);
        $videos = app(VideoGenerationService::class);
        $videos->reconcile();
        $videos->reconcile();
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('released', $job->fresh()->billing_status);
        $this->assertSame(1_000_000, Wallet::balance($user->id));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('terminalWalletStates')]
    public function test_terminal_legacy_wallet_reservations_reconcile_once_without_changing_history(string $status, string $billingStatus, string $transactionType, int $balance): void
    {
        $user = User::factory()->create(['is_active' => true, 'permissions' => ['video_generator' => true]]);
        Wallet::credit($user->id, 1_000_000, 'Opening wallet');
        $jobs = [];
        $history = [];
        foreach ([200_000, 300_000] as $index => $cost) {
            $reference = 'terminal-wallet-'.$index;
            $reservation = Wallet::reserve($user->id, $cost, $reference, ['service' => 'video', 'model' => 'legacy-video']);
            $job = VideoJob::create([
                'user_id' => $user->id, 'job_id' => 'legacy-terminal-'.$index, 'mode' => 'prompt',
                'model' => 'legacy-video', 'prompt' => 'Original historical prompt',
                'improved_prompt' => 'Historical reviewed prompt',
                'status' => $status, 'stage' => $status, 'billing_mode' => 'wallet',
                'billing_reference_id' => $reservation['reference_id'], 'billing_reserved_microusd' => $cost,
                'billing_status' => 'reserved', 'completed_at' => now()->subDay(),
                'video_url' => $status === 'completed' ? 'https://cdn.example.test/historical.mp4' : null,
                'thumbnail_url' => $status === 'completed' ? 'https://cdn.example.test/historical.png' : null,
                'error_message' => $status === 'failed' ? 'Original historical failure' : null,
            ])->fresh();
            $jobs[] = $job;
            $history[] = array_replace($job->getAttributes(), ['billing_status' => $billingStatus]);
        }
        $videos = app(VideoGenerationService::class);
        $this->travel(60)->seconds();
        $this->actingAs($user)->getJson('/api/v/status/'.$jobs[0]->job_id)->assertOk()
            ->assertJsonPath('job.status', $status)->assertJsonPath('job.billing_status', $billingStatus);
        $this->getJson('/api/v/status/'.$jobs[0]->job_id)->assertOk();
        $this->assertSame(1, $videos->reconcile());
        $this->assertSame(0, $videos->reconcile());

        foreach ($jobs as $index => $job) {
            $this->getJson('/api/v/status/'.$job->job_id)->assertOk()->assertJsonPath('job.billing_status', $billingStatus);
            $this->assertSame($history[$index], $job->fresh()->getAttributes());
            $this->assertSame(1, WalletTransaction::query()->where('reference_id', $job->billing_reference_id)->where('type', 'reserve')->count());
            $this->assertSame(1, WalletTransaction::query()->where('reference_id', $job->billing_reference_id)->where('type', $transactionType)->count());
        }
        $this->assertSame($balance, Wallet::balance($user->id));
        $this->assertSame(5, WalletTransaction::query()->where('user_id', $user->id)->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function terminalWalletStates(): array
    {
        return [
            'failed' => ['failed', 'released', 'release', 1_000_000],
            'completed' => ['completed', 'settled', 'settlement', 500_000],
        ];
    }

    public function test_terminal_reconciliation_does_not_change_historical_admin_free_jobs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $job = VideoJob::create([
            'user_id' => $admin->id, 'job_id' => 'legacy-admin-free', 'mode' => 'prompt',
            'model' => 'legacy-video', 'prompt' => 'Original free historical request',
            'status' => 'completed', 'stage' => 'completed', 'billing_mode' => 'admin',
            'billing_status' => 'reserved', 'video_url' => 'https://cdn.example.test/historical-free.mp4',
        ])->fresh();
        $history = $job->getAttributes();

        $this->actingAs($admin)->getJson('/api/v/status/'.$job->job_id)->assertOk();
        $this->assertSame(0, app(VideoGenerationService::class)->reconcile());
        $this->assertSame($history, $job->fresh()->getAttributes());
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('token_reservations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_provider_failed_status_is_persisted_and_refunded_without_resubmission(): void
    {
        [$user] = $this->videoFixture();
        Http::fake([
            'https://media.example.test/v1/videos/generations' => Http::response(['id' => 'upstream-task', 'status' => 'queued']),
            'https://media.example.test/v1/videos/generations/upstream-task' => Http::response(['status' => 'failed', 'error' => ['message' => 'private provider detail']]),
        ]);
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $service->process($job->id);
        $this->travel(9)->seconds();
        $service->poll($job->id);
        $service->poll($job->id);
        $service->process($job->id);

        $response = $this->actingAs($user)->getJson('/api/v/status/'.$job->job_id)->assertOk()
            ->assertJsonPath('job.status', 'failed')->assertJsonPath('job.stage', 'failed')
            ->assertJsonPath('job.billing_status', 'released')->assertJsonPath('balance', 500);
        $this->assertStringNotContainsString('private provider detail', $response->getContent());
        $this->assertSame(1, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        $beforeCancellation = $job->fresh()->getAttributes();
        $this->postJson('/api/v/'.$job->job_id.'/cancel')->assertStatus(409)
            ->assertJsonPath('reason_code', 'job_failed')->assertJsonPath('job.stage', 'failed')
            ->assertJsonPath('job.billing_status', 'released')->assertJsonPath('balance', 500);
        $this->assertSame($beforeCancellation, $job->fresh()->getAttributes());
        Http::assertSentCount(2);
    }

    public function test_video_cancellation_is_owner_scoped_and_refunds_an_expired_owner_once(): void
    {
        [$user] = $this->videoFixture();
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $path = '/api/v/'.$job->job_id.'/cancel';

        $this->postJson($path)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($path)->assertNotFound();
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->actingAs($user)->getJson('/api/v/status/'.$job->job_id)->assertOk()
            ->assertJsonPath('job.can_cancel', true)->assertJsonPath('job.cancel_reason_code', null);
        $user->update(['expires_at' => now()->subDay()]);
        $this->actingAs($user)->postJson($path)->assertOk()
            ->assertJsonPath('job.stage', 'cancelled')->assertJsonPath('job.billing_status', 'released')
            ->assertJsonPath('job.can_cancel', false)->assertJsonPath('balance', 500);
        $cancelled = $job->fresh()->getAttributes();
        $this->postJson($path)->assertOk()->assertJsonPath('job.stage', 'cancelled');
        $this->assertSame($cancelled, $job->fresh()->getAttributes());
        $service->process($job->id);
        $service->poll($job->id);
        $service->reconcile();

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertNull($job->fresh()->next_poll_at);
        $this->assertNull($job->fresh()->processing_started_at);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        Http::assertNothingSent();
    }

    #[DataProvider('submissionEvidence')]
    public function test_queued_state_with_submission_evidence_is_not_cancelled_or_resubmitted(string $evidence): void
    {
        [$user] = $this->videoFixture();
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $job->update([$evidence => $evidence === 'upstream_job_id' ? 'existing-upstream-task' : now()]);
        $before = $job->fresh()->getAttributes();
        Queue::fake();

        $this->actingAs($user)->postJson('/api/v/'.$job->job_id.'/cancel')->assertStatus(409)
            ->assertJsonPath('reason_code', 'submission_started')->assertJsonPath('job.can_cancel', false)
            ->assertJsonPath('job.billing_status', 'reserved')->assertJsonPath('balance', 300);
        $service->process($job->id);
        (new ProcessVideoJob($job->id))->failed(new RuntimeException('Delayed failure callback'));
        $service->reconcile();

        $this->assertSame($before, $job->fresh()->getAttributes());
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->assertSame(0, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function submissionEvidence(): array
    {
        return [
            'upstream job reference' => ['upstream_job_id'],
            'submission timestamp' => ['submitted_at'],
        ];
    }

    public function test_submitting_claim_prevents_concurrent_cancellation_before_provider_returns(): void
    {
        [$user] = $this->videoFixture();
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $refusal = null;
        $beforeCancellation = null;
        $afterCancellation = null;
        Http::fake([
            'https://media.example.test/v1/videos/generations' => function () use ($service, $user, $job, &$refusal, &$beforeCancellation, &$afterCancellation) {
                $beforeCancellation = $job->fresh()->getAttributes();
                $refusal = $this->actingAs($user)->postJson('/api/v/'.$job->job_id.'/cancel');
                $afterCancellation = $job->fresh()->getAttributes();
                $service->process($job->id);

                return Http::response(['id' => 'upstream-task', 'status' => 'queued']);
            },
        ]);

        $service->process($job->id);
        $refusal->assertStatus(409)->assertJsonPath('reason_code', 'submission_started')
            ->assertJsonPath('job.stage', 'submitting')->assertJsonPath('job.can_cancel', false)
            ->assertJsonPath('job.billing_status', 'reserved')->assertJsonPath('balance', 300);
        $this->assertSame($beforeCancellation, $afterCancellation);
        $this->assertSame('rendering', $job->fresh()->stage);
        $this->assertSame('reserved', $job->fresh()->billing_status);
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->assertSame(0, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        $this->assertStringNotContainsString('fixture-secret', $refusal->getContent());
        $this->assertStringNotContainsString('connection_fingerprint', $refusal->getContent());
        $service->process($job->id);
        Http::assertSentCount(1);
    }

    public function test_cancelling_a_saving_video_is_refused_and_the_paid_asset_completes(): void
    {
        [$user] = $this->videoFixture();
        $service = app(VideoGenerationService::class);
        $job = $service->create($user, $this->videoInput())[0];
        $refusal = null;
        $beforeCancellation = null;
        $afterCancellation = null;
        Http::fake([
            'https://media.example.test/v1/videos/generations' => Http::response(['status' => 'completed', 'video_url' => 'https://cdn.example.test/finished.mp4']),
            'https://cdn.example.test/finished.mp4' => function () use ($user, $job, &$refusal, &$beforeCancellation, &$afterCancellation) {
                $beforeCancellation = $job->fresh()->getAttributes();
                $refusal = $this->actingAs($user)->postJson('/api/v/'.$job->job_id.'/cancel');
                $afterCancellation = $job->fresh()->getAttributes();

                return Http::response($this->mp4(), 200, ['Content-Type' => 'video/mp4']);
            },
        ]);

        $service->process($job->id);
        $refusal->assertStatus(409)->assertJsonPath('reason_code', 'submission_started')
            ->assertJsonPath('job.stage', 'saving')->assertJsonPath('job.can_cancel', false)
            ->assertJsonPath('job.billing_status', 'reserved')->assertJsonPath('balance', 300);
        $this->assertSame($beforeCancellation, $afterCancellation);
        $this->assertSame('completed', $job->fresh()->stage);
        $this->assertSame('settled', $job->fresh()->billing_status);
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->assertSame(0, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        Storage::disk('local')->assertExists(GeneratedVideoStore::path($job->job_id));
        $this->get('/api/v/'.$job->job_id.'/asset')->assertOk()->assertHeader('Content-Type', 'video/mp4');
        Http::assertSentCount(2);
    }

    public function test_admin_can_cancel_another_owners_job_but_not_refund_a_completed_result(): void
    {
        [$user] = $this->videoFixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(VideoGenerationService::class);
        $pending = $service->create($user, $this->videoInput())[0];
        $this->actingAs($admin)->postJson('/api/v/'.$pending->job_id.'/cancel')->assertOk()
            ->assertJsonPath('job.stage', 'cancelled');
        $this->assertSame(500, UserToken::getBalance($user->id));

        Http::fake([
            'https://media.example.test/v1/videos/generations' => Http::response(['status' => 'completed', 'video_url' => 'https://cdn.example.test/finished.mp4']),
            'https://cdn.example.test/finished.mp4' => Http::response($this->mp4(), 200, ['Content-Type' => 'video/mp4']),
        ]);
        $completed = $service->create($user, $this->videoInput())[0];
        $service->process($completed->id);
        $beforeCancellation = $completed->fresh()->getAttributes();
        $this->postJson('/api/v/'.$completed->job_id.'/cancel')->assertStatus(409)
            ->assertJsonPath('reason_code', 'job_completed')->assertJsonPath('job.can_cancel', false)
            ->assertJsonPath('job.stage', 'completed')->assertJsonPath('job.billing_status', 'settled');
        $this->assertSame($beforeCancellation, $completed->fresh()->getAttributes());
        $this->assertSame(300, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenTransaction::query()->where('user_id', $user->id)->where('type', 'refund')->count());
        $this->get($completed->fresh()->video_url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
    }

    private function provider(): AiProviderProfile
    {
        return AiProviderProfile::create(['slug' => 'media', 'name' => 'Fixture media', 'protocol' => 'openai', 'base_url' => 'https://media.example.test/v1', 'api_key' => 'fixture-secret', 'is_enabled' => true]);
    }

    private function model(AiProviderProfile $provider, string $id, string $category, ?int $cost): AiModelProfile
    {
        return AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => $id, 'upstream_model_id' => $id, 'display_name' => $id, 'category' => $category, 'token_cost' => $cost, 'is_enabled' => true, 'is_available' => true]);
    }

    private function videoFixture(): array
    {
        $provider = $this->provider();
        $this->model($provider, 'seedance-2.5', 'video', 200);
        $user = User::factory()->create(['is_active' => true, 'permissions' => ['video_generator' => true]]);
        UserToken::topup($user->id, 500);

        return [$user, $provider];
    }

    private function videoInput(): array
    {
        return ['model' => 'seedance-2.5', 'prompt' => 'A safe studio product shot', 'count' => 1, 'mode' => 'prompt'];
    }

    private function png(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=';
    }

    private function mp4(): string
    {
        return hex2bin('00000018667479706d703432000000006d70343269736f6d000000086d646174');
    }
}
