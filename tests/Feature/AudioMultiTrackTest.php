<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AudioJob;
use App\Models\User;
use App\Models\UserToken;
use App\Services\AiProviderEndpoint;
use App\Services\AudioGenerationService;
use App\Services\GeneratedAudioStore;
use App\Services\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AudioMultiTrackTest extends TestCase
{
    use RefreshDatabase;

    private bool $failNextDownload = false;

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

    public function test_every_suno_track_is_private_and_persists_in_order_with_one_charge(): void
    {
        [$user, $job] = $this->submitMusic();
        $this->providerResults();
        $audio = app(AudioGenerationService::class);
        $audio->process($job->id);
        $audio->process($job->id);
        $this->travel(9)->seconds();
        $audio->poll($job->id);
        $audio->poll($job->id);
        $audio->reconcile();

        $response = $this->getJson('/api/audio/'.$job->job_id)->assertOk()
            ->assertJsonPath('job.status', 'completed')->assertJsonPath('job.billing_status', 'settled')
            ->assertJsonCount(2, 'job.outputs')->assertJsonMissingPath('job.audio_url');
        $outputs = $response->json('job.outputs');
        $this->assertSame('/api/audio/'.$job->job_id.'/assets/0', $outputs[0]['url']);
        $this->assertSame('/api/audio/'.$job->job_id.'/assets/1', $outputs[1]['url']);
        $this->assertArrayNotHasKey('path', $outputs[0]);
        $this->assertSame(440, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('token_reservations', 1);
        $this->assertDatabaseHas('token_reservations', ['user_id' => $user->id, 'amount_tokens' => 60, 'status' => 'settled']);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        $this->assertSame(strlen($this->wave(1)) + strlen($this->wave(2)), app(StorageQuotaService::class)->usedBytes($user));
        $this->getJson('/api/library?type=audio')->assertOk()->assertJsonPath('counts.audio', 2);

        $user->update(['expires_at' => now()->subMinute()]);
        foreach ($outputs as $index => $output) {
            $download = $this->get($output['url'])->assertOk()->assertHeader('Content-Type', 'audio/wav');
            $this->assertSame($this->wave($index + 1), file_get_contents($download->baseResponse->getFile()->getPathname()));
        }
        $this->get('/api/audio/'.$job->job_id.'/assets/2')->assertNotFound();
        $this->get('/api/audio/'.$job->job_id.'/asset')->assertNotFound();
        $other = User::factory()->create(['is_active' => true]);
        foreach ($outputs as $output) {
            $this->actingAs($other)->get($output['url'])->assertNotFound();
        }

        // Membership expiry never blocks usage: the expired owner composes again, paid from the token balance.
        $this->actingAs($user)->postJson('/api/audio', [
            'operation' => 'music', 'model' => 'suno-music', 'prompt' => 'A second composition.',
        ])->assertAccepted()->assertJsonPath('balance', 380);
        $this->deleteJson('/api/audio/'.$job->job_id)->assertOk();
        $this->assertSame([], Storage::disk('local')->allFiles('generated/audio'));
        $this->assertSame(0, app(StorageQuotaService::class)->usedBytes($user));
    }

    public function test_second_track_failure_removes_first_track_and_releases_reservation_once(): void
    {
        [$user, $job] = $this->submitMusic();
        $this->providerResults(failSecond: true);
        Storage::disk('local')->put('generated/audio/unrelated/keep.audio', 'keep');
        $audio = app(AudioGenerationService::class);
        $audio->process($job->id);
        $this->travel(9)->seconds();
        $audio->poll($job->id);
        $audio->poll($job->id);
        $audio->reconcile();
        $audio->reconcile();

        $this->getJson('/api/audio/'.$job->job_id)->assertOk()
            ->assertJsonPath('job.status', 'failed')->assertJsonPath('job.billing_status', 'released')
            ->assertJsonPath('job.outputs', []);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('token_reservations', 1);
        $this->assertDatabaseHas('token_reservations', ['user_id' => $user->id, 'amount_tokens' => 60, 'status' => 'released']);
        $this->assertSame(['generated/audio/unrelated/keep.audio'], Storage::disk('local')->allFiles('generated/audio'));
        $this->assertSame(0, app(StorageQuotaService::class)->usedBytes($user));
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://cdn.example.com/')));
        $this->get('/api/audio/'.$job->job_id.'/assets/0')->assertNotFound();
    }

    public function test_unexpected_third_track_rejects_the_collection_without_downloading_or_truncating_it(): void
    {
        [$user, $job] = $this->submitMusic();
        $this->providerResults(extraTrack: true);
        $audio = app(AudioGenerationService::class);
        $audio->process($job->id);
        $this->travel(9)->seconds();
        $audio->poll($job->id);
        $audio->poll($job->id);
        $audio->reconcile();

        $this->getJson('/api/audio/'.$job->job_id)->assertOk()
            ->assertJsonPath('job.status', 'failed')->assertJsonPath('job.billing_status', 'released')
            ->assertJsonPath('job.outputs', []);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('token_reservations', 1);
        $this->assertDatabaseHas('token_reservations', ['user_id' => $user->id, 'status' => 'released']);
        $this->assertSame([], Storage::disk('local')->allFiles('generated/audio'));
        $this->assertCount(0, Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://cdn.example.com/')));
    }

    public function test_reconciliation_preserves_a_live_second_track_save_until_the_worker_lease_expires(): void
    {
        [$user, $job] = $this->submitMusic();
        $job->update([
            'status' => 'processing', 'stage' => 'saving', 'processing_token' => '5c4a2f10-0000-4000-8000-000000000136',
            'processing_started_at' => now()->subMinutes(6),
        ]);
        $path = GeneratedAudioStore::path($job->job_id, 0);
        Storage::disk('local')->put($path, $this->wave(1));
        $audio = app(AudioGenerationService::class);

        $audio->reconcile();

        $this->assertSame('saving', $job->fresh()->stage);
        $this->assertSame('reserved', $job->fresh()->billing_status);
        $this->assertSame(440, UserToken::getBalance($user->id));
        Storage::disk('local')->assertExists($path);
        $this->travel(4)->minutes();
        $audio->reconcile();
        $audio->reconcile();
        $this->assertSame('released', $job->fresh()->billing_status);
        $this->assertSame(500, UserToken::getBalance($user->id));
        Storage::disk('local')->assertMissing($path);
    }

    public function test_retention_removes_all_tracks_without_deleting_another_jobs_files(): void
    {
        [$user, $job] = $this->submitMusic();
        $this->providerResults();
        $audio = app(AudioGenerationService::class);
        $audio->process($job->id);
        $this->travel(9)->seconds();
        $audio->poll($job->id);
        $job->forceFill(['created_at' => now()->subDays(10)])->save();
        Storage::disk('local')->put('generated/audio/unrelated/keep.audio', 'keep');

        $counts = app(StorageQuotaService::class)->purge(now()->subDays(7));

        $this->assertSame(1, $counts['audio']);
        $this->assertDatabaseMissing('audio_jobs', ['id' => $job->id]);
        $this->assertSame(['generated/audio/unrelated/keep.audio'], Storage::disk('local')->allFiles('generated/audio'));
        $this->assertSame(0, app(StorageQuotaService::class)->usedBytes($user));
        $this->assertSame(440, UserToken::getBalance($user->id));
    }

    public function test_historical_scalar_audio_migrates_without_moving_or_exposing_private_paths(): void
    {
        $migration = require database_path('migrations/2026_09_22_100007_collect_audio_outputs.php');
        $migration->down();
        [$user, $job] = $this->submitMusic();
        $path = 'generated/audio/'.hash('sha256', $job->job_id).'/output.audio';
        $wave = $this->wave(1);
        Storage::disk('local')->put($path, $wave);
        DB::table('audio_jobs')->where('id', $job->id)->update([
            'status' => 'completed', 'stage' => 'completed', 'billing_status' => 'settled',
            'audio_url' => '/api/audio/'.$job->job_id.'/asset', 'audio_path' => $path,
            'mime_type' => 'audio/wav', 'size_bytes' => strlen($wave),
        ]);

        $migration->up();

        $response = $this->getJson('/api/audio/'.$job->job_id)->assertOk()->assertJsonCount(1, 'job.outputs')
            ->assertJsonPath('job.outputs.0.url', '/api/audio/'.$job->job_id.'/assets/0')
            ->assertJsonPath('job.outputs.0.size_bytes', strlen($wave))->assertJsonMissingPath('job.audio_url');
        $this->assertArrayNotHasKey('path', $response->json('job.outputs.0'));
        $this->assertArrayNotHasKey('outputs', $job->fresh()->toArray());
        $download = $this->get($response->json('job.outputs.0.url'))->assertOk();
        $this->assertSame($wave, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->assertSame(strlen($wave), app(StorageQuotaService::class)->usedBytes($user));
        $this->deleteJson('/api/audio/'.$job->job_id)->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_all_audio_tracks_share_the_remaining_quota_and_retry_the_original_result_only(): void
    {
        [$user, $job] = $this->submitMusic();
        $this->providerResults();
        $size = strlen($this->wave(1)) + strlen($this->wave(2));
        $service = app(AudioGenerationService::class);
        $service->process($job->id);
        config(['storage_quota.base_bytes' => $size - 1]);
        $this->travel(9)->seconds();
        $service->poll($job->id);
        $this->assertSame('save_failed', $job->fresh()->stage);
        $this->assertSame('reserved', $job->fresh()->billing_status);
        $this->assertSame([], Storage::disk('local')->allFiles(GeneratedAudioStore::directory($job->job_id)));
        $this->travel(1)->hours();
        $service->reconcile();
        $this->assertSame(440, UserToken::getBalance($user->id));
        AiProviderProfile::whereKey($job->provider_id)->update(['is_enabled' => false]);
        config(['storage_quota.base_bytes' => $size]);
        $this->failNextDownload = true;
        $this->postJson('/api/media/workspace/jobs/audio:'.$job->job_id.'/retry-save')->assertAccepted();
        $service->poll($job->id);
        $this->assertSame('save_failed', $job->fresh()->stage);
        $this->assertSame('reserved', $job->fresh()->billing_status);
        $this->postJson('/api/media/workspace/jobs/audio:'.$job->job_id.'/retry-save')->assertAccepted();
        $service->poll($job->id);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('settled', $job->fresh()->billing_status);
        $this->assertSame($size, app(StorageQuotaService::class)->usedBytes($user));
        foreach ($job->fresh()->outputs as $index => $output) {
            $this->assertSame($this->wave($index + 1), Storage::disk('local')->get($output['path']));
        }
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
    }

    private function submitMusic(): array
    {
        $provider = AiProviderProfile::create(['name' => 'Kinovi', 'slug' => 'kinovi-ai', 'protocol' => 'kinovi', 'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'fixture-only', 'is_enabled' => true]);
        AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'suno-music', 'upstream_model_id' => 'suno-music', 'display_name' => 'Suno', 'category' => 'audio', 'token_cost' => 60, 'is_enabled' => true, 'is_available' => true]);
        $user = User::factory()->create(['is_active' => true, 'expires_at' => now()->addDay(), 'permissions' => User::DEFAULT_PERMISSIONS]);
        UserToken::topup($user->id, 500);
        $response = $this->actingAs($user)->postJson('/api/audio', [
            'operation' => 'music', 'model' => 'suno-music', 'prompt' => 'A two-track composition.',
        ])->assertAccepted();

        return [$user, AudioJob::where('job_id', $response->json('job.job_id'))->firstOrFail()];
    }

    private function providerResults(bool $failSecond = false, bool $extraTrack = false): void
    {
        $outputs = ['https://cdn.example.com/first.wav', 'https://cdn.example.com/second.wav'];
        if ($extraTrack) {
            $outputs[] = 'https://cdn.example.com/third.wav';
        }
        Http::fake([
            'https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['taskId' => 'two-tracks']),
            'https://kinovi.ai/api/v1/jobs/recordInfo*' => Http::response(['status' => 'success', 'output' => $outputs]),
            'https://cdn.example.com/first.wav' => function () {
                if ($this->failNextDownload) {
                    $this->failNextDownload = false;

                    return Http::response('Temporary asset outage', 503);
                }

                return Http::response($this->wave(1), 200, ['Content-Type' => 'audio/wav']);
            },
            'https://cdn.example.com/second.wav' => fn () => $failSecond
                ? Http::response('interrupted download', 502)
                : Http::response($this->wave(2), 200, ['Content-Type' => 'audio/wav']),
            'https://cdn.example.com/third.wav' => fn () => Http::response($this->wave(3), 200, ['Content-Type' => 'audio/wav']),
        ]);
    }

    private function wave(int $track): string
    {
        $samples = str_repeat(pack('v', $track * 100), 8000 * $track);

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
    }
}
