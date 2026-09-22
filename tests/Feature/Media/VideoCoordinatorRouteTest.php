<?php

namespace Tests\Feature\Media;

use App\Media\AssetService;
use App\Media\Enums\InputRole;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Services\FalProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * VideoController routes a capability submission (carrying `operation`) to the coordinator for the
 * pilot; a non-pilot keeps the existing verified path (text-to-video only). Legacy submissions
 * (no `operation`) are untouched. Dispatch is faked; no provider call is made.
 */
class VideoCoordinatorRouteTest extends TestCase
{
    use RefreshDatabase;

    private function model(): AiModelProfile
    {
        $provider = AiProviderProfile::create([
            'name' => 'fal', 'slug' => 'fal', 'protocol' => 'fal', 'base_url' => 'https://fal.run', 'api_key' => 'k', 'is_enabled' => true,
        ]);

        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => FalProtocol::VIDEO, 'upstream_model_id' => FalProtocol::VIDEO,
            'display_name' => 'LongCat', 'category' => 'video', 'token_cost' => 200, 'is_enabled' => true, 'is_available' => true,
        ]);
    }

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'permissions' => ['video_generator' => true]]);
        UserToken::topup($user->id, 1000);

        return $user;
    }

    public function test_pilot_capability_submission_routes_to_coordinator(): void
    {
        Queue::fake();
        $this->model();
        $user = $this->member();

        $res = $this->actingAs($user)->postJson('/api/v/gen', [
            'operation' => 'text_to_video', 'model' => FalProtocol::VIDEO, 'prompt' => 'A quiet garden', 'aspect_ratio' => '16:9', 'duration' => 5,
        ])->assertStatus(202)->assertJsonPath('balance', 800);

        $job = VideoJob::query()->where('job_id', $res->json('jobs.0.job_id'))->firstOrFail();
        $this->assertNotNull($job->capability_revision_id, 'pilot uses the coordinator path');
        $this->assertSame(FalProtocol::VIDEO, $job->upstream_model_id);
    }

    public function test_pilot_image_to_video_persists_reference_asset_and_routes_to_reference_model(): void
    {
        Queue::fake();
        $this->model();
        $user = $this->member();
        $asset = app(AssetService::class)->store($user, UploadedFile::fake()->image('r.jpg', 32, 32), InputRole::ImageRef);

        $res = $this->actingAs($user)->postJson('/api/v/gen', [
            'operation' => 'image_to_video', 'model' => FalProtocol::VIDEO, 'prompt' => 'Animate', 'reference_image' => $asset->id, 'duration' => 5,
        ])->assertStatus(202);

        $job = VideoJob::query()->where('job_id', $res->json('jobs.0.job_id'))->firstOrFail();
        $this->assertSame([$asset->id], $job->reference_asset_ids);
        $this->assertSame(FalProtocol::VIDEO_REFERENCE, $job->upstream_model_id, 'routes to the fal reference model');
    }

    public function test_non_pilot_text_uses_legacy_path_and_image_to_video_is_unavailable(): void
    {
        Queue::fake();
        $this->model();
        $pilot = $this->member();
        config(['media.coordinator_restricted' => true, 'media.restricted_user_id' => $pilot->id]);
        $other = $this->member();

        // Non-pilot text-to-video falls back to the legacy (non-coordinator) path — never blocked.
        $res = $this->actingAs($other)->postJson('/api/v/gen', [
            'operation' => 'text_to_video', 'model' => FalProtocol::VIDEO, 'prompt' => 'Legacy garden', 'aspect_ratio' => '16:9', 'duration' => 5,
        ])->assertStatus(202);
        $job = VideoJob::query()->where('job_id', $res->json('jobs.0.job_id'))->firstOrFail();
        $this->assertNull($job->capability_revision_id, 'non-pilot stays on the legacy path');

        // Image-to-video is a coordinator-only capability for now.
        $this->actingAs($other)->postJson('/api/v/gen', [
            'operation' => 'image_to_video', 'model' => FalProtocol::VIDEO, 'prompt' => 'Animate', 'reference_image' => 'anything', 'duration' => 5,
        ])->assertStatus(503);
    }

    public function test_coordinator_keeps_quantity_pro_cta_and_ugc_variation(): void
    {
        Queue::fake();
        $this->model();
        $user = $this->member();

        $res = $this->actingAs($user)->postJson('/api/v/gen', [
            'operation' => 'text_to_video', 'model' => FalProtocol::VIDEO,
            'prompt' => 'A quiet garden', 'aspect_ratio' => '16:9', 'duration' => 5,
            'count' => 2, 'pro' => true, 'mode' => 'ab_testing',
            'cta' => 'Order today', 'ugc_variation' => true,
        ])->assertStatus(202)
            ->assertJsonCount(2, 'jobs')
            ->assertJsonPath('tokens_used', 800)
            ->assertJsonPath('balance', 200);

        $jobs = VideoJob::query()->orderBy('id')->get();
        $this->assertCount(2, $jobs, 'quantity creates one job per video, like the existing studio');
        foreach ($jobs as $job) {
            $this->assertNotNull($job->capability_revision_id, 'every video still goes through the coordinator');
            $this->assertTrue((bool) $job->pro_mode);
            $this->assertSame(400, (int) $job->price_tokens, 'Pro doubles the per-video price');
            $this->assertSame('ab_testing', $job->mode);
            $this->assertSame(16, $job->generation_config['video_parameters']['num_inference_steps']);
            $this->assertSame('maximum', $job->generation_config['video_parameters']['video_quality']);
            $this->assertStringContainsString('Call to action: Order today', $job->prompt);
            $this->assertSame('Order today', $job->settings['cta']);
            $this->assertTrue((bool) $job->settings['ugc_variation']);
        }
        $this->assertStringNotContainsString('Create variation', $jobs[0]->prompt);
        $this->assertStringContainsString('Create variation 2', $jobs[1]->prompt, 'variation only differentiates the later videos');
    }

    public function test_coordinator_reference_stays_viewable_and_owner_gated(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->model();
        $user = $this->member();
        $asset = app(AssetService::class)->store($user, UploadedFile::fake()->image('r.jpg', 32, 32), InputRole::ImageRef);

        $res = $this->actingAs($user)->postJson('/api/v/gen', [
            'operation' => 'image_to_video', 'model' => FalProtocol::VIDEO, 'prompt' => 'Animate',
            'reference_image' => $asset->id, 'duration' => 5,
        ])->assertStatus(202);
        $jobId = $res->json('jobs.0.job_id');

        // An asset-backed reference must stay visible in the studio, not silently disappear
        // because it is no longer a per-job file.
        $res->assertJsonPath('jobs.0.reference_url', '/api/v/'.$jobId.'/reference');
        $shown = $this->actingAs($user)->get('/api/v/'.$jobId.'/reference')->assertOk();
        $this->assertSame($asset->size_bytes, strlen($shown->streamedContent()));

        $this->actingAs($this->member())->get('/api/v/'.$jobId.'/reference')->assertNotFound();
    }
}
