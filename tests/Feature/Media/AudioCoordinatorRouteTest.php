<?php

namespace Tests\Feature\Media;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AudioJob;
use App\Models\User;
use App\Models\UserToken;
use App\Services\AudioGenerationService;
use App\Services\FalProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AudioController routes capability submissions (carrying `operation`) to the coordinator;
 * legacy submissions (no `operation`) keep the existing verified fal pipeline. Kinovi (Suno)
 * audio is coordinator-only, so a non-coordinator member never sees or reaches it.
 */
class AudioCoordinatorRouteTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'permissions' => User::DEFAULT_PERMISSIONS]);
        UserToken::topup($user->id, 500);

        return $user;
    }

    private function falSpeech(): AiModelProfile
    {
        $provider = AiProviderProfile::create(['name' => 'fal', 'slug' => 'fal', 'protocol' => 'fal', 'base_url' => 'https://fal.run', 'api_key' => 'k', 'is_enabled' => true]);

        return AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => FalProtocol::AUDIO_SPEECH, 'upstream_model_id' => FalProtocol::AUDIO_SPEECH, 'display_name' => 'Kokoro', 'category' => 'audio', 'token_cost' => 50, 'is_enabled' => true, 'is_available' => true]);
    }

    private function suno(): AiModelProfile
    {
        $provider = AiProviderProfile::create(['name' => 'Kinovi', 'slug' => 'kinovi-ai', 'protocol' => 'kinovi', 'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'k', 'is_enabled' => true]);

        return AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'suno-music', 'upstream_model_id' => 'suno-music', 'display_name' => 'Suno Music', 'category' => 'audio', 'token_cost' => 60, 'is_enabled' => true, 'is_available' => true]);
    }

    public function test_capability_speech_submission_routes_to_coordinator(): void
    {
        Queue::fake();
        $model = $this->falSpeech();
        $user = $this->member();

        $res = $this->actingAs($user)->postJson('/api/audio', [
            'operation' => 'text_to_speech', 'model' => $model->model_id, 'prompt' => 'Selamat pagi dari XSuper.',
        ])->assertStatus(202)->assertJsonPath('balance', 450);

        $job = AudioJob::query()->where('job_id', $res->json('job.job_id'))->firstOrFail();
        $this->assertNotNull($job->capability_revision_id, 'capability submissions use the coordinator');
        $this->assertSame('speech', $job->mode);
        $this->assertNotNull($job->voice, 'the declared default voice is applied');
        $this->assertSame(50, (int) $job->price_tokens);
    }

    public function test_suno_music_carries_flags_and_tempo_guidance_to_the_provider(): void
    {
        Queue::fake();
        $this->suno();
        $user = $this->member();

        $res = $this->actingAs($user)->postJson('/api/audio', [
            'operation' => 'music', 'model' => 'suno-music', 'prompt' => 'Lagu pagi ceria',
            'tempo' => 120, 'instrumental' => true, 'custom' => true,
        ])->assertStatus(202);
        $job = AudioJob::query()->where('job_id', $res->json('job.job_id'))->firstOrFail();
        $this->assertNotNull($job->capability_revision_id);
        $this->assertSame(['instrumental' => true, 'custom' => true], $job->settings);
        $this->assertStringContainsString('Tempo guidance: approximately 120 BPM.', $job->provider_prompt);

        Http::fake(['https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['taskId' => 'task_suno_1'])]);
        app(AudioGenerationService::class)->process($job->id);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/jobs/createTask')
                && ($data['model'] ?? null) === 'suno-music'
                && ($data['inputs']['instrumental'] ?? null) === true
                && ($data['inputs']['custom'] ?? null) === true
                && ! array_key_exists('duration', $data['inputs'])
                && str_contains((string) ($data['inputs']['prompt'] ?? ''), 'Tempo guidance');
        });
        $this->assertSame('rendering', $job->fresh()->stage);
    }

    public function test_duration_is_rejected_for_a_model_that_does_not_declare_it(): void
    {
        Queue::fake();
        $this->suno();
        $user = $this->member();

        $this->actingAs($user)->postJson('/api/audio', [
            'operation' => 'music', 'model' => 'suno-music', 'prompt' => 'Lagu', 'duration' => 30,
        ])->assertStatus(422);
        $this->assertDatabaseCount('audio_jobs', 0);
        $this->assertSame(500, UserToken::getBalance($user->id));
    }

    public function test_kill_switch_stops_new_audio_submissions_on_both_paths(): void
    {
        Queue::fake();
        $model = $this->falSpeech();
        $user = $this->member();
        config(['media.kill_switch' => true]);

        $this->actingAs($user)->postJson('/api/audio', ['model' => $model->model_id, 'mode' => 'speech', 'prompt' => 'Halo'])->assertStatus(503);
        $this->actingAs($user)->postJson('/api/audio', ['operation' => 'text_to_speech', 'model' => $model->model_id, 'prompt' => 'Halo'])->assertStatus(503);
        $this->assertDatabaseCount('audio_jobs', 0);
        $this->assertSame(500, UserToken::getBalance($user->id));
    }

    public function test_non_coordinator_member_keeps_fal_and_never_sees_kinovi_audio(): void
    {
        Queue::fake();
        $fal = $this->falSpeech();
        $this->suno();
        $pilot = $this->member();
        config(['media.coordinator_restricted' => true, 'media.restricted_user_id' => $pilot->id]);
        $other = $this->member();

        $ids = collect($this->actingAs($other)->getJson('/api/audio/models')->json('models'))->pluck('id');
        $this->assertTrue($ids->contains($fal->model_id));
        $this->assertFalse($ids->contains('suno-music'), 'a model that cannot complete is never offered');

        $res = $this->actingAs($other)->postJson('/api/audio', [
            'operation' => 'text_to_speech', 'model' => $fal->model_id, 'prompt' => 'Jalur lama tetap jalan.',
        ])->assertStatus(202);
        $this->assertNull(AudioJob::query()->where('job_id', $res->json('job.job_id'))->firstOrFail()->capability_revision_id, 'non-coordinator members stay on the verified fal path');

        $this->actingAs($other)->postJson('/api/audio', [
            'operation' => 'music', 'model' => 'suno-music', 'prompt' => 'Lagu',
        ])->assertStatus(503);
    }
}
