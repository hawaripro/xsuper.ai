<?php

namespace Tests\Feature\Media;

use App\Media\AssetService;
use App\Media\CapabilityResolver;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AudioJob;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Models\WorkspaceMediaJob;
use App\Models\WorkspaceMediaSubmission;
use App\Services\FalProtocol;
use App\Services\GeneratedAudioStore;
use App\Services\GeneratedVideoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The native studio pages run through the unified workspace API. Their authoring modes
 * (product/UGC prompt composition, CTA, per-video variation) travel in the execution envelope and
 * belong to the replay identity; the studios' request details, native facts and history clearing
 * stay available without exposing provider data. Dispatch is faked; no provider is called.
 */
class WorkspaceNativeStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_native_video_carries_product_mode_cta_and_variation_through_the_workspace(): void
    {
        $model = $this->model(FalProtocol::VIDEO, 'video', 200, 'second');
        $user = $this->member(['video_generator' => true], 5000);

        $this->actingAs($user)->postJson('/api/media/workspace/jobs', $this->request($model, 'text_to_video', $this->videoInputs(), [
            'count' => 2, 'pro' => true, 'expected_price_tokens' => 2000,
            'mode' => 'ab_testing', 'cta' => '  Order today  ', 'ugc_variation' => true,
        ]))->assertStatus(202)->assertJsonCount(2, 'jobs');

        $jobs = VideoJob::query()->orderBy('id')->get();
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $this->assertSame('ab_testing', $job->mode);
            $this->assertSame('Order today', $job->settings['cta']);
            $this->assertTrue((bool) $job->settings['ugc_variation']);
            $this->assertTrue((bool) $job->pro_mode);
            $this->assertSame(2000, (int) $job->price_tokens, '200 tokens/second × 5 seconds × Pro 2');
            $this->assertStringContainsString('Call to action: Order today', $job->prompt);
        }
        $this->assertStringNotContainsString('Create variation', $jobs[0]->prompt);
        $this->assertStringContainsString('Create variation 2', $jobs[1]->prompt, 'only later videos are varied');
        $this->assertSame(1000, UserToken::getBalance($user->id), '5000 - (200 × 5 × 2 × 2 videos)');
    }

    public function test_video_authoring_options_belong_to_the_replay_identity(): void
    {
        $model = $this->model(FalProtocol::VIDEO, 'video', 200, 'second');
        $user = $this->member(['video_generator' => true]);
        $request = $this->request($model, 'text_to_video', $this->videoInputs(),
            ['expected_price_tokens' => 1000, 'mode' => 'ab_testing', 'cta' => 'Order today']);

        $first = $this->actingAs($user)->postJson('/api/media/workspace/jobs', $request)->assertStatus(202);
        $this->postJson('/api/media/workspace/jobs', $request)->assertStatus(202)->assertJsonPath('job.id', $first->json('job.id'));
        $this->postJson('/api/media/workspace/jobs', [...$request, 'cta' => 'Different message'])->assertStatus(409);
        $this->postJson('/api/media/workspace/jobs', [...$request, 'mode' => 'prompt'])->assertStatus(409);
        $this->postJson('/api/media/workspace/jobs', [...$request, 'ugc_variation' => true])->assertStatus(409);

        $this->assertSame(1, VideoJob::query()->count());
        $this->assertSame(0, UserToken::getBalance($user->id), 'only one 200 × 5-second reservation');
    }

    public function test_plain_requests_keep_their_recorded_execution_identity(): void
    {
        $model = $this->model(FalProtocol::VIDEO, 'video', 200, 'second');
        $user = $this->member(['video_generator' => true]);
        $request = $this->request($model, 'text_to_video', $this->videoInputs(), ['expected_price_tokens' => 1000]);

        $first = $this->actingAs($user)->postJson('/api/media/workspace/jobs', $request)->assertStatus(202);
        // Submissions recorded before the authoring options existed must keep replaying unchanged.
        $this->assertSame(['count' => 1, 'pro' => false, 'rights_confirmed' => false, 'billing_seconds' => null],
            WorkspaceMediaSubmission::query()->sole()->execution);
        $this->postJson('/api/media/workspace/jobs', [...$request, 'mode' => 'prompt', 'cta' => '   ', 'ugc_variation' => false])
            ->assertStatus(202)->assertJsonPath('job.id', $first->json('job.id'));
        $this->assertSame('prompt', VideoJob::query()->sole()->mode);
    }

    public function test_authoring_options_are_refused_where_they_cannot_apply(): void
    {
        $model = $this->model(FalProtocol::AUDIO_SPEECH, 'audio', 100);
        $user = $this->member(['audio_generator' => true]);
        $request = $this->request($model, 'text_to_speech', ['prompt' => 'Hello there', 'voice' => 'af_heart', 'speed' => 1],
            ['expected_price_tokens' => 100]);

        $this->actingAs($user)->postJson('/api/media/workspace/jobs', [...$request, 'mode' => 'ab_testing'])
            ->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->postJson('/api/media/workspace/jobs', [...$request, 'idempotency_key' => 'second', 'cta' => 'Buy now'])
            ->assertUnprocessable()->assertJsonValidationErrors('mode');

        $this->assertSame(0, AudioJob::query()->count());
        $this->assertDatabaseCount('token_reservations', 0);
        $this->assertSame(1000, UserToken::getBalance($user->id));
    }

    public function test_capabilities_carry_native_studio_facts_without_provider_bindings(): void
    {
        $speech = $this->model(FalProtocol::AUDIO_SPEECH, 'audio', 100);
        $video = $this->model(FalProtocol::VIDEO, 'video', 200);
        $user = $this->member(['audio_generator' => true, 'video_generator' => true]);

        $audio = $this->actingAs($user)->getJson('/api/media/workspace/capabilities?model='.urlencode($speech->model_id))->assertOk();
        $audio->assertJsonPath('model.native.audio.voices.0', ['id' => 'af_heart', 'label' => 'Heart', 'language' => 'en-US'])
            ->assertJsonPath('model.native.audio.max_characters', 4000)
            ->assertJsonPath('capabilities.text_to_speech.contract_version', 1);
        $clip = $this->getJson('/api/media/workspace/capabilities?model='.urlencode($video->model_id))->assertOk();
        $clip->assertJsonPath('model.native.pro.supported', true)->assertJsonPath('model.native.reference_image.supported', true);
        $this->assertStringNotContainsString('video_path', $clip->getContent());
        $this->assertStringNotContainsString('fal.run', $clip->getContent());

        $this->getJson('/api/media/workspace/models?kind=audio')->assertOk()
            ->assertJsonPath('models.0.operations.0.operation', 'text_to_speech')
            ->assertJsonPath('models.0.operations.0.contract_version', 1);
    }

    public function test_an_unknown_model_link_is_reported_without_internal_details(): void
    {
        $user = $this->member(['video_generator' => true]);

        $response = $this->actingAs($user)->getJson('/api/media/workspace/capabilities?model=missing/model')
            ->assertNotFound()->assertJsonPath('message', 'This model is unavailable for your account.');
        $this->assertStringNotContainsString('App\\\\Models', $response->getContent());
        $this->postJson('/api/media/workspace/jobs', ['model' => 'missing/model', 'operation' => 'text_to_video', 'inputs' => [],
            'expected_capability_hash' => str_repeat('a', 64), 'expected_price_tokens' => 1, 'idempotency_key' => 'missing'])
            ->assertNotFound()->assertJsonPath('message', 'This model is unavailable for your account.');
        $job = $this->getJson('/api/media/workspace/jobs/video:'.Str::uuid())->assertNotFound()
            ->assertJsonPath('message', 'This media request is unavailable.');
        $this->assertStringNotContainsString('App\\\\Models', $job->getContent());
        $this->deleteJson('/api/media/workspace/jobs/'.Str::uuid())->assertNotFound()
            ->assertJsonPath('message', 'This media request is unavailable.');
        // Malformed links are unknown requests, never a database error on uuid columns (PostgreSQL profile).
        foreach (['not-a-uuid', 'image:not-a-uuid', 'audio:x', 'model3d:1'] as $malformed) {
            $this->getJson('/api/media/workspace/jobs/'.$malformed)->assertNotFound()->assertJsonPath('message', 'This media request is unavailable.');
        }
    }

    public function test_common_jobs_keep_the_request_details_the_studios_showed(): void
    {
        Storage::fake('local');
        $model = $this->model(FalProtocol::VIDEO, 'video', 200, 'second');
        $user = $this->member(['video_generator' => true], 3000);
        $asset = app(AssetService::class)->store($user, UploadedFile::fake()->image('frame.jpg', 32, 32), InputRole::ImageRef);

        $id = $this->actingAs($user)->postJson('/api/media/workspace/jobs', $this->request($model, 'image_to_video',
            ['prompt' => 'Animate the product', 'reference_image' => $asset->id, 'duration' => 5],
            ['pro' => true, 'expected_price_tokens' => 2000, 'mode' => 'ab_testing', 'cta' => 'Order today']))
            ->assertStatus(202)->json('job.id');
        $job = VideoJob::query()->sole();

        $this->getJson('/api/media/workspace/jobs/'.$id)->assertOk()
            ->assertJsonPath('job.details.prompt', $job->prompt)
            ->assertJsonPath('job.details.mode', 'ab_testing')
            ->assertJsonPath('job.details.cta', 'Order today')
            ->assertJsonPath('job.details.pro_mode', true)
            ->assertJsonPath('job.details.duration', 5)
            ->assertJsonPath('job.details.reference_url', '/api/v/'.$job->job_id.'/reference')
            ->assertJsonPath('job.details.tokens_reserved', 2000)
            ->assertJsonPath('job.details.billing_mode', 'tokens');
        $this->get('/api/v/'.$job->job_id.'/reference')->assertOk();

        $speech = $this->model(FalProtocol::AUDIO_SPEECH, 'audio', 100);
        $user->forceFill(['permissions' => ['video_generator' => true, 'audio_generator' => true]])->save();
        $audioId = $this->postJson('/api/media/workspace/jobs', $this->request($speech, 'text_to_speech',
            ['prompt' => 'Hello there', 'voice' => 'af_bella', 'speed' => 1.5], ['expected_price_tokens' => 100, 'idempotency_key' => 'speech']))
            ->assertStatus(202)->json('job.id');
        $this->getJson('/api/media/workspace/jobs/'.$audioId)->assertOk()
            ->assertJsonPath('job.details.prompt', 'Hello there')
            ->assertJsonPath('job.details.mode', 'speech')
            ->assertJsonPath('job.details.voice', 'af_bella');
    }

    public function test_clearing_a_studio_history_removes_only_finished_unreferenced_jobs_of_that_kind(): void
    {
        Storage::fake('local');
        $video = $this->model(FalProtocol::VIDEO, 'video', 10, 'second');
        $speech = $this->model(FalProtocol::AUDIO_SPEECH, 'audio', 10);
        $user = $this->member(['video_generator' => true, 'audio_generator' => true]);
        $other = $this->member(['video_generator' => true]);
        $this->actingAs($user)->postJson('/api/media/workspace/jobs', $this->request($video, 'text_to_video', $this->videoInputs(),
            ['count' => 4, 'expected_price_tokens' => 50]))->assertStatus(202);
        $this->postJson('/api/media/workspace/jobs', $this->request($speech, 'text_to_speech',
            ['prompt' => 'Hello there', 'voice' => 'af_heart', 'speed' => 1], ['expected_price_tokens' => 10, 'idempotency_key' => 'speech']))->assertStatus(202);
        $this->actingAs($other)->postJson('/api/media/workspace/jobs', $this->request($video, 'text_to_video', $this->videoInputs(),
            ['expected_price_tokens' => 50, 'idempotency_key' => 'other']))->assertStatus(202);
        $this->assertSame(790, UserToken::getBalance($user->id), '1000 - (10 × 5 seconds × 4 videos) - 10 audio');
        $this->assertSame(950, UserToken::getBalance($other->id), '1000 - (10 × 5 seconds)');

        [$completed, $failed, $referenced, $pending] = VideoJob::query()->where('user_id', $user->id)->orderBy('id')->get()->all();
        foreach ([$completed, $referenced] as $job) {
            Storage::disk('local')->put(GeneratedVideoStore::path($job->job_id), 'mp4');
            $job->forceFill(['status' => 'completed', 'stage' => 'completed', 'video_url' => '/api/v/'.$job->job_id.'/asset'])->save();
        }
        $failed->forceFill(['status' => 'failed', 'stage' => 'failed'])->save();
        AudioJob::query()->where('user_id', $user->id)->update(['status' => 'failed', 'stage' => 'failed']);
        VideoJob::query()->where('user_id', $other->id)->update(['status' => 'failed', 'stage' => 'failed']);
        $this->artifactReferencing($user, 'video:'.$referenced->job_id);

        $this->actingAs($user)->deleteJson('/api/media/workspace/jobs?kind=video')->assertOk()
            ->assertJsonPath('deleted_count', 2)->assertJsonPath('retained_count', 1);

        $this->assertSame([$referenced->job_id, $pending->job_id],
            VideoJob::query()->where('user_id', $user->id)->orderBy('id')->pluck('job_id')->all());
        Storage::disk('local')->assertMissing(GeneratedVideoStore::path($completed->job_id));
        Storage::disk('local')->assertExists(GeneratedVideoStore::path($referenced->job_id));
        $this->assertSame(1, AudioJob::query()->where('user_id', $user->id)->count(), 'another studio keeps its history');
        $this->assertSame(1, VideoJob::query()->where('user_id', $other->id)->count(), 'another member keeps their history');
        $this->deleteJson('/api/media/workspace/jobs')->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    public function test_native_audio_tracks_stay_playable_and_selectable_in_the_common_history(): void
    {
        Storage::fake('local');
        $speech = $this->model(FalProtocol::AUDIO_MUSIC, 'audio', 100);
        $user = $this->member(['audio_generator' => true]);
        $id = $this->actingAs($user)->postJson('/api/media/workspace/jobs', $this->request($speech, 'music',
            ['prompt' => 'Calm piano', 'duration' => 30], ['expected_price_tokens' => 100]))->assertStatus(202)->json('job.id');
        $job = AudioJob::query()->sole();
        $wav = 'RIFF'.pack('V', 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', 0);
        $outputs = [];
        foreach ([0, 1] as $index) {
            Storage::disk('local')->put($path = GeneratedAudioStore::path($job->job_id, $index), $wav);
            // The shape GeneratedAudioStore::persistTrack records for every native track.
            $outputs[] = ['path' => $path, 'mime_type' => 'audio/wav', 'size_bytes' => strlen($wav)];
        }
        $job->forceFill(['status' => 'completed', 'stage' => 'completed', 'outputs' => $outputs, 'billing_status' => 'settled'])->save();

        $response = $this->getJson('/api/media/workspace/jobs/'.$id)->assertOk()->assertJsonCount(2, 'job.outputs');
        foreach ([0, 1] as $index) {
            $response->assertJsonPath("job.outputs.$index.mime", 'audio/wav')
                ->assertJsonPath("job.outputs.$index.previewable", true)
                ->assertJsonPath("job.outputs.$index.name", 'audio-'.($index + 1).'.wav');
        }
        $this->get($response->json('job.outputs.1.url'))->assertOk()->assertHeader('Content-Type', 'audio/wav');
    }

    public function test_schema_jobs_stay_in_the_studio_their_model_is_listed_in(): void
    {
        $avatar = $this->model('fal-ai/talking-head', 'avatar', 10);
        $video = $this->model(FalProtocol::VIDEO, 'video', 10);
        $user = $this->member(['video_generator' => true]);
        // Schema contracts carry operations such as audio_to_video, never the native talking_avatar.
        $avatarJob = $this->finishedSchemaJob($user, $avatar, 'audio_to_video');
        $videoJob = $this->finishedSchemaJob($user, $video, 'text_to_video');
        $ids = fn (string $kind): array => array_column($this->actingAs($user)->getJson('/api/media/workspace/jobs?kind='.$kind)->assertOk()->json('jobs'), 'id');

        $this->assertSame([$avatarJob->job_id], $ids('avatar'));
        $this->assertSame([$videoJob->job_id], $ids('video'));
        $this->deleteJson('/api/media/workspace/jobs?kind=video')->assertOk()->assertJsonPath('deleted_count', 1);
        $this->assertTrue(WorkspaceMediaJob::query()->whereKey($avatarJob->id)->exists(), 'clearing video keeps the avatar studio history');
    }

    private function finishedSchemaJob(User $user, AiModelProfile $model, string $operation): WorkspaceMediaJob
    {
        return WorkspaceMediaJob::query()->create([
            'job_id' => (string) Str::uuid(), 'user_id' => $user->id, 'provider_id' => $model->provider_id,
            'model' => $model->model_id, 'model_label' => $model->display_name, 'operation' => $operation, 'output_kind' => 'video',
            'upstream_model_id' => $model->model_id, 'connection_fingerprint' => str_repeat('0', 64),
            'capability_hash' => str_repeat('0', 64), 'payload_fingerprint' => str_repeat('0', 64),
            'capability_snapshot' => [], 'provider_bindings' => [], 'normalized_inputs' => [], 'input_assets' => [], 'reference_asset_ids' => [],
            'price_tokens' => 10, 'price_unit' => 'generation', 'billing_mode' => 'tokens', 'billing_status' => 'released',
            'billing_reference_id' => 'workspace:'.Str::uuid(), 'tokens_reserved' => 0, 'status' => 'failed', 'stage' => 'failed',
        ]);
    }

    private function model(string $modelId, string $category, int $price, ?string $unit = null): AiModelProfile
    {
        $provider = AiProviderProfile::query()->firstOrCreate(['slug' => 'fal'], [
            'name' => 'fal', 'protocol' => 'fal', 'base_url' => 'https://fal.run', 'api_key' => 'k', 'is_enabled' => true,
        ]);

        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => $modelId, 'upstream_model_id' => $modelId,
            'display_name' => Str::afterLast($modelId, '/'), 'category' => $category, 'token_cost' => $price,
            'token_cost_unit' => $unit, 'is_enabled' => true, 'is_available' => true,
        ]);
    }

    private function member(array $permissions, int $balance = 1000): User
    {
        $user = User::factory()->create(['is_active' => true, 'permissions' => $permissions]);
        UserToken::topup($user->id, $balance);

        return $user;
    }

    private function videoInputs(): array
    {
        return ['prompt' => 'A quiet garden', 'aspect_ratio' => '16:9', 'duration' => 5];
    }

    private function request(AiModelProfile $model, string $operation, array $inputs, array $extra = []): array
    {
        return [
            'model' => $model->model_id, 'operation' => $operation, 'inputs' => $inputs,
            'expected_capability_hash' => app(CapabilityResolver::class)->resolve($model, MediaOperation::from($operation), schemaContracts: true)->sourceHash,
            'expected_price_tokens' => (int) $model->token_cost, 'idempotency_key' => 'native-studio-key',
            'count' => 1, 'pro' => false,
            ...$extra,
        ];
    }

    private function artifactReferencing(User $user, string $jobId): void
    {
        $artifact = (string) Str::uuid();
        $revision = (string) Str::uuid();
        DB::table('chat_artifacts')->insert(['id' => $artifact, 'user_id' => $user->id, 'conversation_id' => 'c-1', 'title' => 'Clip',
            'kind' => 'video', 'version' => 1, 'current_revision_id' => $revision, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('chat_artifact_revisions')->insert(['id' => $revision, 'artifact_id' => $artifact, 'user_id' => $user->id, 'version' => 1,
            'filename' => 'video.mp4', 'mime' => 'video/mp4', 'generated_job_id' => $jobId, 'generated_output_id' => '0', 'created_at' => now()]);
    }
}
