<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Services\AiProviderEndpoint;
use App\Services\FalProtocol;
use App\Services\VideoGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoStudioContractTest extends TestCase
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

    public function test_pro_reserves_twice_the_price_and_refunds_the_original_amount_once(): void
    {
        [$user, $model] = $this->fixture();
        $response = $this->actingAs($user)->postJson('/api/v/gen', $this->input(['pro_mode' => true]));
        // 200 tokens/second × 2 seconds × Pro 2 × 1 video = 800 tokens.
        $response->assertAccepted()->assertJsonPath('balance', 200)->assertJsonPath('jobs.0.tokens_reserved', 800);
        $jobId = $response->json('jobs.0.job_id');
        $model->update(['token_cost' => 900]);

        $this->postJson('/api/v/'.$jobId.'/cancel')->assertOk()->assertJsonPath('balance', 1000);
        $this->postJson('/api/v/'.$jobId.'/cancel')->assertOk()->assertJsonPath('balance', 1000);
        $this->assertDatabaseHas('token_reservations', ['user_id' => $user->id, 'amount_tokens' => 800, 'status' => 'released']);
        Http::assertNothingSent();
    }

    public function test_pro_changes_real_generation_parameters_without_changing_the_prompt(): void
    {
        [$user] = $this->fixture();
        Http::fake(['https://queue.fal.run/'.FalProtocol::VIDEO => Http::response(['request_id' => 'pro-quality-job'])]);
        $response = $this->actingAs($user)->postJson('/api/v/gen', $this->input(['pro_mode' => true]));
        $response->assertAccepted();
        $job = VideoJob::query()->where('job_id', $response->json('jobs.0.job_id'))->firstOrFail();
        app(VideoGenerationService::class)->process($job->id);

        Http::assertSent(fn (Request $request): bool => ($request->data()['num_inference_steps'] ?? null) === 16
            && ($request->data()['video_quality'] ?? null) === 'maximum'
            && $request['prompt'] === 'A quiet garden in morning light');
        $this->assertSame(200, UserToken::getBalance($user->id));
    }

    public function test_reference_upload_uses_image_to_video_without_exposing_a_public_upload(): void
    {
        [$user] = $this->fixture();
        Http::fake(['https://queue.fal.run/fal-ai/longcat-video/distilled/image-to-video/480p' => Http::response(['request_id' => 'reference-video-job'])]);
        $image = UploadedFile::fake()->createWithContent('reference.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='));
        $response = $this->actingAs($user)->post('/api/v/gen', $this->input(['reference_image' => $image]), ['Accept' => 'application/json']);
        $response->assertAccepted()->assertJsonPath('jobs.0.has_reference', true)->assertJsonPath('jobs.0.tokens_reserved', 400);
        $job = VideoJob::query()->where('job_id', $response->json('jobs.0.job_id'))->firstOrFail();
        app(VideoGenerationService::class)->process($job->id);

        Http::assertSent(fn (Request $request): bool => str_starts_with((string) $request['image_url'], 'data:image/png;base64,')
            && ! isset($request['aspect_ratio']));
        $this->assertSame(600, UserToken::getBalance($user->id));
    }

    public function test_reference_upload_keeps_a_retained_per_generation_tariff(): void
    {
        [$user, $model] = $this->fixture();
        // A tariff kept per generation must not become per second on the reference endpoint.
        $model->update(['token_cost_unit' => 'generation']);
        $image = UploadedFile::fake()->createWithContent('reference.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='));
        $this->actingAs($user)->post('/api/v/gen', $this->input(['reference_image' => $image, 'settings' => ['duration' => 5]]), ['Accept' => 'application/json'])
            ->assertAccepted()->assertJsonPath('jobs.0.has_reference', true)->assertJsonPath('jobs.0.tokens_reserved', 200)->assertJsonPath('balance', 800);
        $this->postJson('/api/v/gen', $this->input(['settings' => ['duration' => 5]]))->assertAccepted()->assertJsonPath('jobs.0.tokens_reserved', 200);
        $this->assertSame(600, UserToken::getBalance($user->id));
        Http::assertNothingSent();
    }

    public function test_unsupported_pro_is_rejected_before_any_charge_or_dispatch(): void
    {
        [$user, $model] = $this->fixture();
        $model->provider->update(['protocol' => 'openai', 'base_url' => 'https://media.example.test/v1']);
        $model->update(['model_id' => 'seedance-2.5', 'upstream_model_id' => 'seedance-2.5']);
        $this->actingAs($user)->postJson('/api/v/gen', $this->input(['model' => 'seedance-2.5', 'pro_mode' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('pro_mode');
        $this->assertSame(1000, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('video_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_kill_switch_stops_new_video_submissions_on_the_path_the_studio_uses(): void
    {
        // The studio posts the legacy payload (no `operation`). That branch never consulted the
        // emergency pause, so MEDIA_KILL_SWITCH accepted and charged video work while "paused".
        [$user] = $this->fixture();
        config(['media.kill_switch' => true]);

        $this->actingAs($user)->postJson('/api/v/gen', $this->input())->assertStatus(503);

        $this->assertDatabaseCount('video_jobs', 0);
        $this->assertSame(1000, UserToken::getBalance($user->id));
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        $provider = AiProviderProfile::create(['name' => 'fal', 'slug' => 'fal', 'protocol' => 'fal', 'base_url' => 'https://fal.run', 'api_key' => 'fixture-only-key', 'is_enabled' => true]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => FalProtocol::VIDEO, 'upstream_model_id' => FalProtocol::VIDEO, 'display_name' => 'LongCat', 'category' => 'video', 'token_cost' => 200, 'token_cost_unit' => 'second', 'is_enabled' => true, 'is_available' => true]);
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        UserToken::topup($user->id, 1000);

        return [$user, $model];
    }

    private function input(array $changes = []): array
    {
        return array_replace(['model' => FalProtocol::VIDEO, 'prompt' => 'A quiet garden in morning light', 'count' => 1,
            'mode' => 'prompt', 'pro_mode' => false, 'settings' => ['duration' => 2]], $changes);
    }
}
