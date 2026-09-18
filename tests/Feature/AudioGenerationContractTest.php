<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AudioJob;
use App\Models\User;
use App\Models\UserToken;
use App\Services\AiProviderEndpoint;
use App\Services\AudioGenerationService;
use App\Services\FalProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AudioGenerationContractTest extends TestCase
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

    public function test_speech_settles_admitted_price_once_and_keeps_completed_audio_private_after_expiry(): void
    {
        [$user, $model] = $this->fixture(FalProtocol::AUDIO_SPEECH);
        $this->fakeProvider(FalProtocol::AUDIO_SPEECH, 'fal-ai/kokoro', ['audio' => ['url' => 'https://v3.fal.media/audio.wav']]);
        $response = $this->actingAs($user)->postJson('/api/audio', [
            'model' => $model->model_id, 'mode' => 'speech', 'prompt' => 'Welcome to the studio.', 'voice' => 'af_heart', 'speed' => 1,
        ])->assertAccepted()->assertJsonPath('balance', 450);
        $job = AudioJob::query()->where('job_id', $response->json('job.job_id'))->firstOrFail();
        $model->update(['token_cost' => 300]);
        $audio = app(AudioGenerationService::class);
        $audio->process($job->id);
        $audio->process($job->id);
        $this->postJson('/api/audio/'.$job->job_id.'/cancel')->assertConflict();
        $this->travel(9)->seconds();
        $audio->poll($job->id);
        $audio->poll($job->id);

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame(450, UserToken::getBalance($user->id));
        $this->assertDatabaseHas('token_reservations', ['user_id' => $user->id, 'service' => 'audio', 'amount_tokens' => 50, 'status' => 'settled']);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        $user->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/audio/'.$job->job_id)->assertOk()->assertJsonPath('job.billing_status', 'settled');
        $this->get($job->fresh()->audio_url)->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $other = User::factory()->create(['is_active' => true]);
        $this->actingAs($other)->getJson('/api/audio/'.$job->job_id)->assertNotFound();
        $this->get($job->fresh()->audio_url)->assertNotFound();
    }

    public function test_music_accepts_documented_string_audio_result_and_requested_duration(): void
    {
        [$user, $model] = $this->fixture(FalProtocol::AUDIO_MUSIC);
        $this->fakeProvider(FalProtocol::AUDIO_MUSIC, 'fal-ai/stable-audio-25', ['audio' => 'https://v3.fal.media/audio.wav']);
        $response = $this->actingAs($user)->postJson('/api/audio', [
            'model' => $model->model_id, 'mode' => 'music', 'prompt' => 'A calm acoustic instrumental.', 'duration' => 20, 'tempo' => 96,
        ])->assertAccepted();
        $job = AudioJob::query()->where('job_id', $response->json('job.job_id'))->firstOrFail();
        $audio = app(AudioGenerationService::class);
        $audio->process($job->id);
        $this->travel(9)->seconds();
        $audio->poll($job->id);

        $this->assertSame('completed', $job->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && ($request->data()['seconds_total'] ?? null) === 20);
        $this->getJson('/api/audio/'.$job->job_id)->assertOk()->assertJsonPath('job.tempo', 96)->assertJsonPath('job.duration', 20);
        $this->assertSame(450, UserToken::getBalance($user->id));
    }

    public function test_invalid_voice_cannot_reserve_tokens_and_disabled_connection_refunds_once_without_dispatch(): void
    {
        [$user, $model] = $this->fixture(FalProtocol::AUDIO_SPEECH);
        $input = ['model' => $model->model_id, 'mode' => 'speech', 'prompt' => 'A short narration.', 'voice' => 'not-a-provider-voice'];
        $this->actingAs($user)->postJson('/api/audio', $input)->assertUnprocessable()->assertJsonValidationErrors('voice');
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('audio_jobs', 0);

        $input['voice'] = 'af_heart';
        $response = $this->postJson('/api/audio', $input)->assertAccepted();
        $job = AudioJob::query()->where('job_id', $response->json('job.job_id'))->firstOrFail();
        $model->provider->update(['is_enabled' => false]);
        $audio = app(AudioGenerationService::class);
        $audio->process($job->id);
        $audio->process($job->id);
        $audio->reconcile();
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertSame('released', $job->fresh()->billing_status);
        Http::assertNothingSent();
    }

    public function test_obsolete_queue_failure_cannot_refund_another_workers_live_submission(): void
    {
        [$user, $model] = $this->fixture(FalProtocol::AUDIO_SPEECH);
        $id = $this->actingAs($user)->postJson('/api/audio', ['model' => $model->model_id, 'mode' => 'speech', 'prompt' => 'Keep this live request.'])->assertAccepted()->json('job.job_id');
        $job = AudioJob::where('job_id', $id)->firstOrFail();
        $job->update(['status' => 'processing', 'stage' => 'submitting', 'processing_token' => 'replacement-worker', 'processing_started_at' => now()]);

        (new \App\Jobs\ProcessAudioJob($job->id))->failed(new \RuntimeException('Obsolete queue delivery exceeded attempts.'));

        $this->assertSame('processing', $job->fresh()->status);
        $this->assertSame(450, UserToken::getBalance($user->id));
        Http::assertNothingSent();
    }

    public function test_abandoned_save_cleans_only_its_private_staging_files_and_refunds_once(): void
    {
        [$user, $model] = $this->fixture(FalProtocol::AUDIO_SPEECH);
        $id = $this->actingAs($user)->postJson('/api/audio', ['model' => $model->model_id, 'mode' => 'speech', 'prompt' => 'Recover an interrupted save.'])->assertAccepted()->json('job.job_id');
        $job = AudioJob::where('job_id', $id)->firstOrFail();
        $job->update(['status' => 'processing', 'stage' => 'saving', 'processing_token' => 'abandoned-save', 'processing_started_at' => now()->subMinutes(15)]);
        $partial = \App\Services\GeneratedAudioStore::path($id).'.0123456789abcdef.part';
        $unrelated = \App\Services\GeneratedAudioStore::path('unrelated-job');
        Storage::disk('local')->put($partial, 'partial audio bytes');
        Storage::disk('local')->put($unrelated, 'unrelated private audio');

        app(AudioGenerationService::class)->reconcile();
        app(AudioGenerationService::class)->reconcile();

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame(500, UserToken::getBalance($user->id));
        Storage::disk('local')->assertMissing($partial);
        $this->assertSame('unrelated private audio', Storage::disk('local')->get($unrelated));
        Http::assertNothingSent();
    }

    private function fixture(string $id): array
    {
        $provider = AiProviderProfile::create(['name' => 'fal', 'slug' => 'fal', 'protocol' => 'fal', 'base_url' => 'https://fal.run', 'api_key' => 'fixture-only-key', 'is_enabled' => true]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => $id, 'upstream_model_id' => $id, 'display_name' => 'Audio fixture', 'category' => 'audio', 'tier' => 'Original', 'token_cost' => 50, 'is_enabled' => true, 'is_available' => true]);
        $user = User::factory()->create(['is_active' => true, 'expires_at' => now()->addDay(), 'permissions' => User::DEFAULT_PERMISSIONS]);
        UserToken::topup($user->id, 500);

        return [$user, $model];
    }

    private function fakeProvider(string $model, string $root, array $result): void
    {
        Http::fake([
            'https://queue.fal.run/'.$model => Http::response(['request_id' => 'audio-request', 'response_url' => 'http://127.0.0.1/private']),
            'https://queue.fal.run/'.$root.'/requests/audio-request/status' => Http::response(['status' => 'COMPLETED']),
            'https://queue.fal.run/'.$root.'/requests/audio-request' => Http::response($result),
            'https://v3.fal.media/audio.wav' => function (Request $request) {
                $this->assertFalse($request->hasHeader('Authorization'));
                $samples = str_repeat("\x00", 16000);
                $wave = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;

                return Http::response($wave, 200, ['Content-Type' => 'audio/wav']);
            },
        ]);
    }
}
