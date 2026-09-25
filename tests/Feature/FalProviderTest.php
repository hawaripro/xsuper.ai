<?php

namespace Tests\Feature;

use App\Exceptions\AiProxyException;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\User;
use App\Models\UserToken;
use App\Services\AiProviderEndpoint;
use App\Services\AiProviderTransport;
use App\Services\FalProtocol;
use App\Services\VideoGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FalProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    public function test_public_catalog_metadata_cannot_make_an_invalid_fal_key_healthy(): void
    {
        $provider = $this->provider();
        $admin = User::factory()->create(['role' => 'admin']);
        Http::fake([
            'https://api.fal.ai/v1/models/pricing*' => Http::response(['error' => ['type' => 'authorization_error']], 401),
            'https://api.fal.ai/v1/models*' => Http::response(['models' => [['endpoint_id' => FalProtocol::IMAGE_SCHNELL]]]),
        ]);

        $this->actingAs($admin)->postJson('/api/admin/ai/providers/'.$provider->id.'/check')->assertStatus(502);
        $this->assertSame('error', $provider->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertDatabaseCount('image_jobs', 0);
        $this->assertDatabaseCount('video_jobs', 0);
    }

    public function test_fal_image_batch_charges_admin_and_keeps_downloads_private_without_forwarding_key(): void
    {
        $provider = $this->provider();
        $this->model($provider, 'image', FalProtocol::IMAGE_SCHNELL, 15);
        $admin = User::factory()->create(['role' => 'admin']);
        UserToken::topup($admin->id, 100);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
        Http::fake([
            'https://fal.run/fal-ai/flux/schnell' => function (Request $request) {
                $this->assertTrue($request->hasHeader('Authorization', 'Key fal-fixture-secret'));
                $this->assertSame(2, $request['num_images']);
                $this->assertTrue($request['enable_safety_checker']);

                return Http::response(['images' => [['url' => 'https://v3.fal.media/a.png'], ['url' => 'https://v3.fal.media/b.png']]]);
            },
            'https://v3.fal.media/*.png' => function (Request $request) use ($png) {
                $this->assertFalse($request->hasHeader('Authorization'));

                return Http::response($png, 200, ['Content-Type' => 'image/png']);
            },
        ]);

        $response = $this->actingAs($admin)->postJson('/api/images', [
            'model' => FalProtocol::IMAGE_SCHNELL, 'prompt' => 'Two quiet ceramic cups', 'size' => '1024x1024', 'n' => 2,
        ])->assertCreated()->assertJsonPath('job.status', 'completed')->assertJsonPath('balance', 70);
        $this->assertSame('settled', ImageJob::firstOrFail()->billing_status);
        $this->assertCount(2, $response->json('job.result_urls'));
        $this->get($response->json('job.result_urls.0'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs(User::factory()->create())->get($response->json('job.result_urls.0'))->assertNotFound();
    }

    public function test_nested_fal_video_polls_app_root_and_settles_once_without_anthropic_review(): void
    {
        $provider = $this->provider();
        $this->model($provider, 'video', FalProtocol::VIDEO, 200, 'second');
        $admin = User::factory()->create(['role' => 'admin']);
        UserToken::topup($admin->id, 450);
        Http::fake([
            'https://queue.fal.run/'.FalProtocol::VIDEO => function (Request $request) {
                $this->assertSame(30, $request['num_frames']);
                $this->assertTrue($request['enable_safety_checker']);

                return Http::response([
                    'request_id' => 'fal-job-1',
                    'status_url' => 'https://untrusted.example/steal',
                    'response_url' => 'http://127.0.0.1/private',
                ]);
            },
            'https://queue.fal.run/fal-ai/longcat-video/requests/fal-job-1/status' => Http::response(['status' => 'COMPLETED']),
            'https://queue.fal.run/fal-ai/longcat-video/requests/fal-job-1' => Http::response(['video' => ['url' => 'https://v3.fal.media/video.mp4']]),
            'https://v3.fal.media/video.mp4' => function (Request $request) {
                $this->assertFalse($request->hasHeader('Authorization'));

                return Http::response(hex2bin('00000018667479706d703432000000006d70343269736f6d000000086d646174'), 200, ['Content-Type' => 'video/mp4']);
            },
        ]);
        $this->actingAs($admin)->getJson('/api/v/models')->assertOk()->assertJsonPath('models.0.id', FalProtocol::VIDEO);
        $service = app(VideoGenerationService::class);
        // 200 tokens/second × 2 seconds × 1 Standard video = 400 tokens.
        $job = $service->create($admin, ['model' => FalProtocol::VIDEO, 'prompt' => 'A calm green garden', 'count' => 1,
            'mode' => 'prompt', 'pro_mode' => false, 'settings' => ['duration' => 2]])[0];
        $this->assertSame(400, (int) $job->tokens_reserved);
        $service->process($job->id);
        $this->assertSame('rendering', $job->fresh()->stage);
        $this->actingAs($admin)->postJson('/api/v/'.$job->job_id.'/cancel')->assertStatus(409)->assertJsonPath('balance', 50);
        $this->travel(9)->seconds();
        $service->poll($job->id);
        $this->assertSame('completed', $job->fresh()->stage);
        $this->assertSame('settled', $job->fresh()->billing_status);
        $this->get($job->fresh()->video_url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $service->poll($job->id);
        $service->process($job->id);
        $this->assertSame(50, UserToken::getBalance($admin->id));
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'untrusted.example') || str_contains($request->url(), 'openrouter'));
    }

    public function test_completed_fal_error_refunds_without_downloading_or_resubmitting(): void
    {
        $provider = $this->provider();
        $this->model($provider, 'video', FalProtocol::VIDEO, 200, 'second');
        $admin = User::factory()->create(['role' => 'admin']);
        UserToken::topup($admin->id, 450);
        Http::fake([
            'https://queue.fal.run/'.FalProtocol::VIDEO => Http::response(['request_id' => 'fal-failed']),
            'https://queue.fal.run/fal-ai/longcat-video/requests/fal-failed/status' => Http::response(['status' => 'COMPLETED', 'error' => 'private provider diagnostics']),
        ]);
        $service = app(VideoGenerationService::class);
        $job = $service->create($admin, ['model' => FalProtocol::VIDEO, 'prompt' => 'A quiet garden', 'count' => 1,
            'mode' => 'prompt', 'pro_mode' => false, 'settings' => ['duration' => 2]])[0];
        $this->assertSame(400, (int) $job->tokens_reserved);
        $this->assertSame(50, UserToken::getBalance($admin->id));
        $service->process($job->id);
        $this->travel(9)->seconds();
        $service->poll($job->id);
        $this->assertSame('failed', $job->fresh()->stage);
        $this->assertSame('released', $job->fresh()->billing_status);
        $this->assertStringNotContainsString('private provider diagnostics', $job->fresh()->error_message);
        $service->poll($job->id);
        $this->assertSame(450, UserToken::getBalance($admin->id));
        Http::assertSentCount(2);
    }

    public function test_fal_rejects_conflicting_token_limits_before_paid_dispatch(): void
    {
        Http::fake(['*' => Http::response([
            'output' => 'This completion must not be requested.',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ])]);
        try {
            app(AiProviderTransport::class)->complete($this->provider(), [
                'model' => FalProtocol::CHAT_MODEL, 'messages' => [['role' => 'user', 'content' => 'Hello']],
                'max_tokens' => 8192, 'max_completion_tokens' => 1, 'stream' => true,
            ]);
            $this->fail('Conflicting token limits must be refused before inference.');
        } catch (AiProxyException $exception) {
            $this->assertSame(422, $exception->responseStatus());
        }
        Http::assertNothingSent();
    }

    public function test_reassigning_a_video_model_to_fal_clears_old_protocol_settings(): void
    {
        $fal = $this->provider();
        $oldProvider = AiProviderProfile::create(['slug' => 'old-provider', 'name' => 'Old Provider', 'protocol' => 'openai', 'is_enabled' => true]);
        $model = AiModelProfile::create([
            'provider_id' => $oldProvider->id, 'model_id' => 'existing-video', 'upstream_model_id' => 'old-video',
            'display_name' => 'Existing video', 'category' => 'video',
            'token_cost' => 200, 'is_enabled' => true, 'is_available' => true,
            'generation_config' => ['durations' => [5, 10]],
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/api/admin/ai/models/'.$model->id, ['provider_slug' => $fal->slug, 'upstream_model_id' => FalProtocol::VIDEO])
            ->assertOk()
            ->assertJsonPath('model.generation_config.durations', [2, 3, 5, 10]);
    }

    private function provider(): AiProviderProfile
    {
        return AiProviderProfile::create(['name' => 'fal', 'slug' => 'fal', 'protocol' => 'fal', 'base_url' => 'https://fal.run', 'api_key' => 'fal-fixture-secret', 'is_enabled' => true]);
    }

    private function model(AiProviderProfile $provider, string $category, string $id, int $cost, ?string $unit = null): void
    {
        AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => $id, 'upstream_model_id' => $id, 'display_name' => $id, 'category' => $category, 'token_cost' => $cost, 'token_cost_unit' => $unit, 'is_enabled' => true, 'is_available' => true]);
    }
}
