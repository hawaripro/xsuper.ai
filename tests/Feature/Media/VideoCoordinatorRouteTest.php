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
}
