<?php

namespace Tests\Feature\Media;

use App\Jobs\ProcessVideoJob;
use App\Media\AssetService;
use App\Media\Enums\InputRole;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Services\AiProviderEndpoint;
use App\Services\VideoGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AvatarStudioContractTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $audioSeconds = 1): array
    {
        Queue::fake();
        Storage::fake('local');
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        config(['media.coordinator_restricted' => false]);
        $provider = AiProviderProfile::create([
            'name' => 'Kinovi', 'slug' => 'kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'test', 'is_enabled' => true,
        ]);
        $model = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'presenter',
            'upstream_model_id' => 'minimax-h3-turbo-avatar-talking', 'display_name' => 'Presenter',
            'category' => 'avatar', 'token_cost' => 10, 'is_enabled' => true, 'is_available' => true,
        ]);
        $user = User::factory()->create(['is_active' => true, 'permissions' => ['video_generator' => true]]);
        UserToken::topup($user->id, 1000);
        $photo = app(AssetService::class)->store($user, UploadedFile::fake()->image('portrait.png', 48, 48), InputRole::AvatarPhoto);
        $audioId = (string) Str::uuid();
        $audioPath = 'media-assets/'.$audioId.'.wav';
        $samples = str_repeat("\0", 16000 * $audioSeconds);
        $wave = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
        Storage::disk('local')->put($audioPath, $wave);
        $audio = MediaAsset::create([
            'id' => $audioId, 'user_id' => $user->id, 'media_type' => 'audio', 'role' => 'speech_audio',
            'storage_disk' => 'local', 'storage_path' => $audioPath, 'size_bytes' => strlen($wave),
            'mime' => 'audio/wav', 'signature_ok' => true, 'retention_status' => 'active',
        ]);
        $input = ['model' => 'presenter', 'operation' => 'talking_avatar', 'avatar_photo' => $photo->id,
            'speech_audio' => $audio->id, 'duration' => 5, 'aspect_ratio' => '16:9', 'rights_confirmed' => true];

        return [$user, $model, $photo, $audio, $input];
    }

    private function falFixture(int $audioSeconds = 2): array
    {
        [$user, $model, $photo, $audio, $input] = $this->fixture($audioSeconds);
        $model->provider->update(['protocol' => 'fal', 'base_url' => 'https://fal.run']);
        $model->update(['upstream_model_id' => 'minimax/h3-max-turbo/image-to-video']);
        $input = array_diff_key($input, ['aspect_ratio' => true]);
        $input['prompt'] = 'A presenter speaks naturally with subtle head motion.';
        config(['media_tools.ffprobe' => PHP_BINARY]);
        $probe = Mockery::mock(Process::class);
        $probe->shouldReceive('setInput', 'setTimeout')->andReturnSelf();
        $probe->shouldReceive('run')->andReturn(0);
        $probe->shouldReceive('isRunning')->andReturnFalse();
        $probe->shouldReceive('getOutput')->andReturn(json_encode([
            'streams' => [['sample_rate' => '8000']], 'frames' => [['nb_samples' => 8000 * $audioSeconds]],
        ]));
        $this->app->bind(Process::class, fn () => $probe);

        return [$user, $model, $photo, $audio, $input];
    }

    private function fakeReferenceUploads(MediaAsset $photo, MediaAsset $audio, bool $confirmed = true): void
    {
        Http::fake(function ($request) use ($photo, $audio, $confirmed) {
            $endpoint = strtok($request->url(), '?');
            if ($request->method() === 'POST' && $endpoint === 'https://kinovi.ai/api/v1/uploads') {
                $asset = $request['fileName'] === basename($photo->storage_path) ? $photo : $audio;

                return Http::response([
                    'uploadUrl' => 'https://uploads.example.com/'.$asset->id.'?signature=test',
                    'url' => 'https://media.example.com/'.$asset->id, 'path' => 'owned/'.$asset->id,
                    'method' => 'PUT', 'contentType' => $asset->mime, 'expiresIn' => 900,
                    'confirmUrl' => '/api/v1/uploads?path=owned/'.$asset->id, 'assetTtlSeconds' => 86400,
                ]);
            }
            if ($request->method() === 'PUT' && str_starts_with($endpoint, 'https://uploads.example.com/')) {
                return Http::response('', 200);
            }
            if ($request->method() === 'GET' && $endpoint === 'https://kinovi.ai/api/v1/uploads') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $asset = ($query['path'] ?? null) === 'owned/'.$photo->id ? $photo : $audio;

                return $confirmed ? Http::response([
                    'path' => 'owned/'.$asset->id, 'url' => 'https://media.example.com/'.$asset->id,
                    'size' => $asset->size_bytes, 'contentType' => $asset->mime,
                    'uploadedAt' => now()->toISOString(), 'expiresAt' => now()->addDay()->toISOString(),
                    'assetTtlSeconds' => 86400,
                ]) : Http::response([], 404);
            }
            if ($endpoint === 'https://kinovi.ai/api/v1/jobs/createTask') {
                $ready = ($request['inputs']['imageUrls'] ?? []) === ['https://media.example.com/'.$photo->id]
                    && ($request['inputs']['audioUrls'] ?? []) === ['https://media.example.com/'.$audio->id];

                return $ready ? Http::response(['taskId' => 'avatar-test']) : Http::response([], 400);
            }

            return Http::response([], 500);
        });
    }

    public function test_pair_and_consent_are_required_before_any_charge(): void
    {
        [$user, , $photo, , $input] = $this->fixture();
        $this->actingAs($user)->postJson('/api/avatar', array_diff_key($input, ['speech_audio' => true]))->assertUnprocessable();
        $this->postJson('/api/avatar', [...$input, 'speech_audio' => $photo->id])->assertUnprocessable();
        $this->postJson('/api/avatar', [...$input, 'rights_confirmed' => false])->assertUnprocessable();
        $this->assertSame(1000, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('video_jobs', 0);
    }

    public function test_uploaded_pair_reaches_provider_while_local_references_remain_private(): void
    {
        [$user, , $photo, $audio, $input] = $this->fixture();
        $result = $this->actingAs($user)->postJson('/api/avatar', $input)->assertAccepted();
        $jobId = $result->json('jobs.0.job_id');
        $this->assertSame(950, UserToken::getBalance($user->id));
        $this->fakeReferenceUploads($photo, $audio);
        $job = VideoJob::where('job_id', $jobId)->firstOrFail();
        app(VideoGenerationService::class)->process($job->id);
        $this->assertSame('rendering', $job->fresh()->stage);
        $this->assertSame('avatar-test', $job->fresh()->upstream_job_id);
        $this->getJson('/api/avatar')->assertJsonPath('jobs.0.job_id', $jobId);
        $this->getJson('/api/v/history')->assertJsonCount(0, 'jobs');
        $this->get('/api/media/assets/'.$audio->id)->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $outsider = User::factory()->create(['is_active' => true, 'permissions' => ['video_generator' => true]]);
        $this->actingAs($outsider)->get('/api/media/assets/'.$audio->id)->assertNotFound();
        $this->get('/api/v/'.$jobId.'/reference')->assertNotFound();
        $this->postJson('/api/avatar', $input)->assertForbidden();
    }

    public function test_unconfirmed_provider_reference_never_submits_a_paid_avatar(): void
    {
        [$user, , $photo, $audio, $input] = $this->fixture();
        $this->fakeReferenceUploads($photo, $audio, confirmed: false);
        $id = $this->actingAs($user)->postJson('/api/avatar', $input)->assertAccepted()->json('jobs.0.job_id');
        $job = VideoJob::where('job_id', $id)->firstOrFail();
        app(VideoGenerationService::class)->process($job->id);

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame(1000, UserToken::getBalance($user->id));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/jobs/createTask'));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://kinovi.ai/api/v1/uploads?'));
    }

    public function test_fal_photo_audio_model_uses_its_own_duration_contract(): void
    {
        [$user, , , , $input] = $this->falFixture();
        $this->actingAs($user)->getJson('/api/avatar/models')->assertOk()->assertJsonPath('models.0.id', 'presenter');
        $this->postJson('/api/avatar', [...$input, 'duration' => 4])->assertUnprocessable();
        $this->assertSame(1000, UserToken::getBalance($user->id));
        $video = hex2bin('00000018667479706d703432000000006d70343269736f6d000000086d646174');
        Http::preventStrayRequests();
        Http::fake([
            'https://queue.fal.run/minimax/h3-max-turbo/image-to-video' => Http::response(['request_id' => 'fal-avatar']),
            'https://queue.fal.run/minimax/h3-max-turbo/requests/fal-avatar/status' => Http::response(['status' => 'COMPLETED']),
            'https://queue.fal.run/minimax/h3-max-turbo/requests/fal-avatar' => Http::response(['video' => ['url' => 'https://v3.fal.media/avatar.mp4']]),
            'https://v3.fal.media/avatar.mp4' => Http::response($video, 200, ['Content-Type' => 'video/mp4']),
        ]);
        $this->postJson('/api/avatar', [...$input, 'duration' => 6, 'expected_price_tokens' => 60])
            ->assertAccepted()->assertJsonPath('jobs.0.tokens_reserved', 60);
        $this->assertSame(940, UserToken::getBalance($user->id));
        $job = VideoJob::query()->sole();
        $service = app(VideoGenerationService::class);
        $service->process($job->id);
        $this->travel(9)->seconds();
        $service->poll($job->id);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('settled', $job->fresh()->billing_status);
        $download = $this->get($job->fresh()->video_url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->assertSame($video, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $service->poll($job->id);
        $service->process($job->id);
        $this->assertSame(940, UserToken::getBalance($user->id));
        Http::assertSentCount(4);
    }

    public function test_fal_rejects_sub_two_second_audio_before_reserving_tokens(): void
    {
        [$user, , , , $input] = $this->falFixture(1);
        Http::preventStrayRequests();
        $this->actingAs($user)->postJson('/api/avatar', $input)->assertUnprocessable();
        $this->assertSame(1000, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('video_jobs', 0);
        $this->assertDatabaseCount('token_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_fal_rejects_oversized_audio_before_reserving_tokens(): void
    {
        [$user, , , $audio, $input] = $this->falFixture();
        $audio->update(['size_bytes' => 15_000_001]);
        Http::preventStrayRequests();
        $this->actingAs($user)->postJson('/api/avatar', $input)->assertUnprocessable();
        $this->assertSame(1000, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('video_jobs', 0);
        $this->assertDatabaseCount('token_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_changed_quote_and_expired_assets_are_rejected_without_charge(): void
    {
        [$user, $model, , $audio, $input] = $this->fixture();
        $catalog = $this->actingAs($user)->getJson('/api/avatar/models')->assertOk();
        $hash = $catalog->json('models.0.capabilities.talking_avatar.source_hash');
        $model->update(['token_cost' => 11]);
        $this->postJson('/api/avatar', [...$input, 'expected_price_tokens' => 50, 'expected_capability_hash' => $hash])->assertConflict();
        $audio->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/avatar', $input)->assertUnprocessable();
        $this->assertSame(1000, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('video_jobs', 0);
    }

    public function test_lost_response_replays_after_price_and_reference_availability_change(): void
    {
        [$user, $model, $photo, , $input] = $this->fixture();
        $hash = $this->actingAs($user)->getJson('/api/avatar/models')->json('models.0.capabilities.talking_avatar.source_hash');
        $input += ['idempotency_key' => 'unconfirmed-avatar', 'expected_price_tokens' => 50, 'expected_capability_hash' => $hash];
        $id = $this->postJson('/api/avatar', $input)->assertAccepted()->json('jobs.0.job_id');
        $model->update(['token_cost' => 11]);
        $photo->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/avatar', $input)->assertAccepted()->assertJsonPath('jobs.0.job_id', $id);
        $this->assertSame(950, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('video_jobs', 1);
        Queue::assertPushed(ProcessVideoJob::class, 1);
    }

    public function test_owned_references_can_be_removed_only_after_active_work_finishes(): void
    {
        [$user, , $photo, $audio, $input] = $this->fixture();
        $grant = app(AssetService::class)->signedUrl($photo);
        $id = $this->actingAs($user)->postJson('/api/avatar', $input)->assertStatus(202)->json('jobs.0.job_id');
        $this->getJson('/api/library?type=reference')->assertOk()->assertJsonPath('counts.reference', 2);
        $this->deleteJson('/api/media/assets/'.$photo->id)->assertUnprocessable();
        Storage::disk('local')->assertExists($photo->storage_path);
        $this->actingAs(User::factory()->create())->deleteJson('/api/media/assets/'.$photo->id)->assertNotFound();
        $this->actingAs($user)->postJson('/api/avatar/'.$id.'/cancel')->assertOk();
        $this->deleteJson('/api/media/assets/'.$photo->id)->assertOk();
        Storage::disk('local')->assertMissing($photo->storage_path);
        $this->get('/api/media/assets/'.$photo->id)->assertNotFound();
        $this->get($grant)->assertNotFound();
        $this->get('/api/media/assets/'.$audio->id)->assertOk();
    }
}
