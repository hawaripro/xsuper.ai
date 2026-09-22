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
use App\Services\VideoGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AvatarStudioContractTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        Storage::fake('local');
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
        $samples = str_repeat("\0", 16000);
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

    public function test_pair_and_consent_are_required_before_any_charge(): void
    {
        [$user, , $photo, , $input] = $this->fixture();
        $this->actingAs($user)->postJson('/api/avatar', array_diff_key($input, ['speech_audio' => true]))->assertUnprocessable();
        $this->postJson('/api/avatar', [...$input, 'speech_audio' => $photo->id])->assertUnprocessable();
        $this->postJson('/api/avatar', [...$input, 'rights_confirmed' => false])->assertUnprocessable();
        $this->assertSame(1000, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('video_jobs', 0);
    }

    public function test_owned_pair_uses_signed_provider_grants_and_keeps_references_private(): void
    {
        [$user, , $photo, $audio, $input] = $this->fixture();
        $result = $this->actingAs($user)->postJson('/api/avatar', $input)->assertAccepted();
        $jobId = $result->json('jobs.0.job_id');
        $this->assertSame(950, UserToken::getBalance($user->id));
        Http::fake(['https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['taskId' => 'avatar-test'])]);
        $job = VideoJob::where('job_id', $jobId)->firstOrFail();
        app(VideoGenerationService::class)->process($job->id);
        Http::assertSent(function ($request) use ($photo, $audio): bool {
            $images = $request['inputs']['imageUrls'] ?? [];
            $sounds = $request['inputs']['audioUrls'] ?? [];

            return count($images) === 1 && count($sounds) === 1
                && str_contains($images[0], $photo->id) && str_contains($images[0], 'signature=')
                && str_contains($sounds[0], $audio->id) && str_contains($sounds[0], 'signature=')
                && $request['inputs']['outputResolution'] === '0.2'
                && $request['inputs']['duration'] === 5;
        });
        $this->getJson('/api/avatar')->assertJsonPath('jobs.0.job_id', $jobId);
        $this->getJson('/api/v/history')->assertJsonCount(0, 'jobs');
        $this->get('/api/media/assets/'.$audio->id)->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $outsider = User::factory()->create(['is_active' => true, 'permissions' => ['video_generator' => true]]);
        $this->actingAs($outsider)->get('/api/media/assets/'.$audio->id)->assertNotFound();
        $this->get('/api/v/'.$jobId.'/reference')->assertNotFound();
        $this->postJson('/api/avatar', $input)->assertForbidden();
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
