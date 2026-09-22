<?php

namespace Tests\Feature\Media;

use App\Jobs\ProcessVideoJob;
use App\Media\AssetService;
use App\Media\CapabilityPresenter;
use App\Media\Enums\InputRole;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Services\FalProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        $this->actingAs($other)->postJson('/api/v/gen', [
            'operation' => 'text_to_video', 'model' => FalProtocol::VIDEO, 'prompt' => 'Legacy garden', 'aspect_ratio' => '16:9', 'duration' => 5,
        ])->assertStatus(202);

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

    public function test_retry_returns_the_whole_original_batch_even_after_terminal_transitions(): void
    {
        Queue::fake();
        $this->model();
        $user = $this->member();
        $input = [
            'operation' => 'text_to_video', 'model' => FalProtocol::VIDEO, 'prompt' => 'A quiet garden',
            'aspect_ratio' => '16:9', 'duration' => 5, 'count' => 2, 'pro' => true,
            'cta' => 'Order today', 'ugc_variation' => true, 'idempotency_key' => 'batch-network-retry',
        ];
        $first = $this->actingAs($user)->postJson('/api/v/gen', $input)->assertStatus(202);
        $ids = array_column($first->json('jobs'), 'job_id');
        VideoJob::query()->where('job_id', $ids[0])->update(['status' => 'completed']);
        AiModelProfile::query()->where('model_id', FalProtocol::VIDEO)->update([
            'token_cost' => 250, 'generation_config' => ['durations' => [6]],
        ]);

        $retry = $this->postJson('/api/v/gen', $input)->assertStatus(202)->assertJsonCount(2, 'jobs')->assertJsonPath('balance', 200);
        $this->assertSame($ids, array_column($retry->json('jobs'), 'job_id'));
        VideoJob::query()->where('job_id', $ids[1])->update(['status' => 'failed']);
        $this->postJson('/api/v/gen', $input)->assertStatus(202)->assertJsonCount(2, 'jobs')->assertJsonPath('balance', 200);
        $this->postJson('/api/v/gen', [...$input, 'cta' => 'Different message'])->assertStatus(409);
        $this->assertSame(2, VideoJob::query()->count());
        $this->assertSame(200, UserToken::getBalance($user->id));
    }

    public function test_stale_video_quote_or_contract_never_reserves_tokens(): void
    {
        Queue::fake();
        $model = $this->model();
        $user = $this->member();
        $hash = app(CapabilityPresenter::class)->forModel($model)['text_to_video']['source_hash'];
        $input = [
            'operation' => 'text_to_video', 'model' => FalProtocol::VIDEO, 'prompt' => 'Garden',
            'duration' => 5, 'pro' => true, 'expected_price_tokens' => 400, 'expected_capability_hash' => $hash,
        ];
        $model->update(['token_cost' => 210]);
        $this->actingAs($user)->postJson('/api/v/gen', $input)->assertStatus(409);
        $this->postJson('/api/v/gen', [...$input, 'duration' => 6, 'expected_capability_hash' => str_repeat('0', 64)])->assertStatus(409);
        $this->assertSame(1000, UserToken::getBalance($user->id));
        $this->assertSame(0, VideoJob::query()->count());
    }

    public function test_restricted_cohort_fallback_preserves_quote_and_retry_guards(): void
    {
        Queue::fake();
        $model = $this->model();
        $user = $this->member();
        config(['media.coordinator_restricted' => true, 'media.restricted_user_id' => $user->id + 1]);
        $input = [
            'operation' => 'text_to_video', 'model' => FalProtocol::VIDEO, 'prompt' => 'Garden',
            'duration' => 5, 'expected_price_tokens' => 200, 'idempotency_key' => 'legacy-network-retry',
            'expected_capability_hash' => app(CapabilityPresenter::class)->forModel($model)['text_to_video']['source_hash'],
        ];
        $this->actingAs($user)->postJson('/api/v/gen', [...$input, 'expected_price_tokens' => 1])->assertStatus(409);
        $first = $this->postJson('/api/v/gen', $input)->assertStatus(202);
        $id = $first->json('jobs.0.job_id');
        VideoJob::query()->where('job_id', $id)->update(['status' => 'completed']);
        $model->update(['token_cost' => 250, 'generation_config' => ['durations' => [6]]]);
        $this->postJson('/api/v/gen', $input)->assertStatus(202)->assertJsonPath('jobs.0.job_id', $id)->assertJsonPath('balance', 800);
        $this->postJson('/api/v/gen', [...$input, 'prompt' => 'Changed'])->assertStatus(409);
        $this->assertSame(1, VideoJob::query()->count());
    }

    public function test_pre_cutover_request_keeps_its_original_identity_after_catalog_changes(): void
    {
        Queue::fake();
        $model = $this->model();
        $user = $this->member();
        $input = ['operation' => 'text_to_video', 'model' => $model->model_id, 'prompt' => 'Garden',
            'aspect_ratio' => '16:9', 'duration' => 5, 'idempotency_key' => 'before-upgrade'];
        $id = $this->actingAs($user)->postJson('/api/v/gen', $input)->assertAccepted()->json('jobs.0.job_id');
        $job = VideoJob::where('job_id', $id)->firstOrFail();
        // The deployed F3 format, including defaults omitted from the original request.
        $job->update(['payload_fingerprint' => hash('sha256', json_encode([
            'o' => 'text_to_video', 'm' => $model->model_id,
            'p' => ['inputs' => ['prompt' => 'Garden'], 'params' => ['aspect_ratio' => '16:9', 'duration' => 5, 'count' => 1, 'pro' => false]],
        ], JSON_THROW_ON_ERROR))]);
        $revision = MediaCapabilityRevision::findOrFail($job->capability_revision_id);
        $definition = $revision->definition;
        foreach ($definition['params'] as &$param) {
            $param['type'] = ['integer' => 'int', 'boolean' => 'bool'][$param['type']] ?? $param['type'];
        }
        unset($param);
        $revision->update(['definition' => $definition]);
        (require database_path('migrations/2026_09_22_100011_canonicalize_capability_parameter_types.php'))->up();
        $model->update(['token_cost' => 300, 'generation_config' => ['durations' => [6]]]);
        $this->postJson('/api/v/gen', $input)->assertAccepted()->assertJsonPath('jobs.0.job_id', $id)->assertJsonPath('balance', 800);
        $this->postJson('/api/v/gen', [...$input, 'cta' => 'New creative input'])->assertConflict();
        Queue::assertPushed(ProcessVideoJob::class, 1);
        $this->assertDatabaseCount('video_jobs', 1);
    }

    public function test_fallback_rechecks_price_when_it_changes_after_the_initial_model_read(): void
    {
        Queue::fake();
        $model = $this->model();
        $user = $this->member();
        config(['media.coordinator_restricted' => true, 'media.restricted_user_id' => $user->id + 1]);
        $hash = app(CapabilityPresenter::class)->forModel($model)['text_to_video']['source_hash'];
        $dispatcher = AiModelProfile::getEventDispatcher();
        AiModelProfile::setEventDispatcher(clone $dispatcher);
        $changed = false;
        AiModelProfile::retrieved(function (AiModelProfile $snapshot) use ($model, &$changed): void {
            if (! $changed && $snapshot->id === $model->id) {
                $changed = true;
                DB::table('ai_model_profiles')->where('id', $model->id)->update(['token_cost' => 400]);
            }
        });
        try {
            $this->actingAs($user)->postJson('/api/v/gen', [
                'operation' => 'text_to_video', 'model' => FalProtocol::VIDEO, 'prompt' => 'Garden',
                'duration' => 5, 'expected_price_tokens' => 200, 'expected_capability_hash' => $hash,
            ])->assertStatus(409);
            $this->assertSame(1000, UserToken::getBalance($user->id));
            $this->assertSame(0, VideoJob::query()->count());
        } finally {
            AiModelProfile::setEventDispatcher($dispatcher);
        }
    }
}
