<?php

namespace Tests\Feature\Media;

use App\Exceptions\ImageGenerationException;
use App\Media\AssetService;
use App\Media\CapabilityResolver;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\MediaGenerationCoordinator;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\UserToken;
use App\Services\AiProviderEndpoint;
use App\Services\FalProtocol;
use App\Services\VideoGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F2 capability-driven video through the coordinator (fal). text_to_video sends aspect + duration;
 * image_to_video routes to the reference model, inlines the OWNED reference asset as a private
 * data-uri (never a public URL), and persists the stable asset id. All provider calls are faked.
 */
class VideoReferenceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // Minimal valid ftyp+mdat mp4 (finfo => video/mp4); stands in for a provider result.
    private const MP4 = 'AAAAIGZ0eXBpc29tAAACAGlzb21pc28ybXA0MW1wNDIAAAAIbWRhdA==';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    private function model(): AiModelProfile
    {
        $provider = AiProviderProfile::create([
            'name' => 'fal', 'slug' => 'fal', 'protocol' => 'fal', 'base_url' => 'https://fal.run',
            'api_key' => 'fixture-only-key', 'is_enabled' => true,
        ]);

        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => FalProtocol::VIDEO, 'upstream_model_id' => FalProtocol::VIDEO,
            'display_name' => 'LongCat', 'category' => 'video', 'token_cost' => 200, 'is_enabled' => true, 'is_available' => true,
        ]);
    }

    private function reference(User $user): MediaAsset
    {
        return app(AssetService::class)->store($user, UploadedFile::fake()->image('ref.jpg', 64, 64), InputRole::ImageRef);
    }

    private function hash(AiModelProfile $model, MediaOperation $op): string
    {
        return app(CapabilityResolver::class)->resolve($model, $op)->sourceHash;
    }

    private function fakeProvider(): void
    {
        Http::fake([
            'https://queue.fal.run/'.FalProtocol::VIDEO => Http::response(['request_id' => 'ttv-job']),
            'https://queue.fal.run/'.FalProtocol::VIDEO_REFERENCE => Http::response(['request_id' => 'i2v-job']),
            'https://queue.fal.run/fal-ai/longcat-video/requests/*/status' => Http::response(['status' => 'COMPLETED']),
            'https://queue.fal.run/fal-ai/longcat-video/requests/*' => Http::response(['video' => ['url' => 'https://fal.media/files/out.mp4']]),
            'https://fal.media/*' => Http::response(base64_decode(self::MP4), 200, ['Content-Type' => 'video/mp4']),
        ]);
    }

    public function test_text_to_video_lifecycle_reserves_sends_params_and_settles_once(): void
    {
        $this->fakeProvider();
        $model = $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 1000);
        $coordinator = app(MediaGenerationCoordinator::class);
        $videos = app(VideoGenerationService::class);

        [$job] = $coordinator->startVideo($user, $model, MediaOperation::TextToVideo,
            ['prompt' => 'A quiet garden', 'aspect_ratio' => '9:16', 'duration' => 5], 'studio-video',
            ['expected_price_tokens' => 200, 'expected_capability_hash' => $this->hash($model, MediaOperation::TextToVideo)]);

        $this->assertSame('pending', $job->status);
        $this->assertNotNull($job->capability_revision_id);
        $this->assertNull($job->reference_asset_ids);
        $this->assertSame(800, UserToken::getBalance($user->id));

        $videos->process($job->id);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), FalProtocol::VIDEO)
            && ($r['aspect_ratio'] ?? null) === '9:16' && ($r['num_frames'] ?? null) === 75 && ! isset($r['image_url']));

        $this->travel(10)->seconds();
        $videos->poll($job->id);
        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertSame('/api/v/'.$job->job_id.'/asset', $job->video_url);
        $this->assertSame('settled', $job->billing_status);
        $this->assertSame(800, UserToken::getBalance($user->id), 'settled once, not double-charged');
    }

    public function test_image_to_video_inlines_private_data_uri_and_persists_stable_asset_id(): void
    {
        $this->fakeProvider();
        $model = $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 1000);
        $asset = $this->reference($user);
        $videos = app(VideoGenerationService::class);

        [$job] = app(MediaGenerationCoordinator::class)->startVideo($user, $model, MediaOperation::ImageToVideo,
            ['prompt' => 'Animate it', 'reference_image' => $asset->id, 'duration' => 5], 'studio-video',
            ['expected_capability_hash' => $this->hash($model, MediaOperation::ImageToVideo)]);

        $this->assertSame([$asset->id], $job->reference_asset_ids, 'stable asset id persisted, not a URL');
        $this->assertSame(800, UserToken::getBalance($user->id));

        $videos->process($job->id);
        // Routed to the fal image-to-video model with an inline data-uri and no aspect ratio.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), FalProtocol::VIDEO_REFERENCE)
            && str_starts_with((string) ($r['image_url'] ?? ''), 'data:image/jpeg;base64,')
            && ! isset($r['aspect_ratio']));

        $this->travel(10)->seconds();
        $videos->poll($job->id);
        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertSame('settled', $job->billing_status);
    }

    public function test_missing_required_reference_is_rejected_before_reserve(): void
    {
        $model = $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 1000);

        try {
            app(MediaGenerationCoordinator::class)->startVideo($user, $model, MediaOperation::ImageToVideo,
                ['prompt' => 'Animate it'], 'studio-video', []);
            $this->fail('expected image_to_video without a reference to be rejected');
        } catch (ImageGenerationException $e) {
            $this->assertSame(422, $e->responseStatus());
        }
        $this->assertDatabaseCount('video_jobs', 0);
        $this->assertSame(1000, UserToken::getBalance($user->id));
        Http::assertNothingSent();
    }

    public function test_other_users_reference_is_rejected(): void
    {
        $model = $this->model();
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        UserToken::topup($attacker->id, 1000);
        $asset = $this->reference($owner);

        try {
            app(MediaGenerationCoordinator::class)->startVideo($attacker, $model, MediaOperation::ImageToVideo,
                ['prompt' => 'steal', 'reference_image' => $asset->id], 'studio-video', []);
            $this->fail('expected another user\'s asset to be rejected');
        } catch (ImageGenerationException $e) {
            $this->assertSame(403, $e->responseStatus());
        }
        $this->assertDatabaseCount('video_jobs', 0);
        $this->assertSame(1000, UserToken::getBalance($attacker->id));
    }

    public function test_retry_with_same_key_reuses_job_without_a_second_reserve(): void
    {
        $model = $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 1000);
        $coordinator = app(MediaGenerationCoordinator::class);
        $inputs = ['prompt' => 'A quiet garden', 'aspect_ratio' => '16:9', 'duration' => 5];
        $opts = ['idempotency_key' => 'vid-act-1'];

        [$first] = $coordinator->startVideo($user, $model, MediaOperation::TextToVideo, $inputs, 'studio-video', $opts);
        [$retry] = $coordinator->startVideo($user, $model, MediaOperation::TextToVideo, $inputs, 'studio-video', $opts);

        $this->assertSame($first->id, $retry->id, 'same key + same input is one job');
        $this->assertSame(800, UserToken::getBalance($user->id), 'a retry never reserves twice');
        $this->assertDatabaseCount('video_jobs', 1);
    }
}
