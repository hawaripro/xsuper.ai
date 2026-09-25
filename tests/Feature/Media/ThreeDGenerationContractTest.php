<?php

namespace Tests\Feature\Media;

use App\Media\AssetService;
use App\Media\CapabilityResolver;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ThreeDJob;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserToken;
use App\Services\AiProviderEndpoint;
use App\Services\GeneratedModel3dStore;
use App\Services\ThreeDGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ThreeDGenerationContractTest extends TestCase
{
    use RefreshDatabase;

    private bool $failNextDownload = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['media.coordinator_restricted' => false, 'media.kill_switch' => false]);
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    public function test_private_original_settles_admitted_price_once_and_terminal_replay_never_charges_again(): void
    {
        [$user, $model, $asset, $input] = $this->fixture();
        $bytes = $this->glb();
        $this->fakeProvider($bytes);
        $response = $this->actingAs($user)->postJson('/api/3d', $input)->assertAccepted()->assertJsonPath('balance', 450);
        $job = ThreeDJob::where('job_id', $response->json('jobs.0.job_id'))->firstOrFail();
        $service = app(ThreeDGenerationService::class);
        $service->process($job->id);
        $service->process($job->id);
        $this->postJson('/api/3d/'.$job->job_id.'/cancel')->assertConflict();
        $this->travel(9)->seconds();
        $model->update(['token_cost' => 300]);
        $service->poll($job->id);
        $service->poll($job->id);
        $this->postJson('/api/3d', $input)->assertAccepted()->assertJsonPath('jobs.0.job_id', $job->job_id);
        $job->refresh();

        $this->assertSame('completed', $job->status);
        $this->assertSame(450, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('three_d_jobs', 1);
        $this->assertDatabaseHas('token_reservations', ['user_id' => $user->id, 'service' => 'model3d', 'amount_tokens' => 50, 'status' => 'settled']);
        $this->assertSame($bytes, Storage::disk('local')->get($job->model_path));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_starts_with($request['image_url'] ?? '', 'data:image/png;base64,')
            && $request['resolution'] === 512 && $request['texture_size'] === 1024 && $request['decimation_target'] === 50000);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 410)]);
        $user->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/3d/'.$job->job_id)->assertOk()->assertJsonPath('job.previewable', true)
            ->assertJsonMissingPath('job.provider_result_url')->assertJsonMissingPath('job.reference_asset_ids');
        $this->get($job->model_url)->assertOk()->assertHeader('Content-Type', 'model/gltf-binary');
        $this->get($job->model_url.'?download=1')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=model-'.$job->job_id.'.glb');
        $other = User::factory()->create(['is_active' => true]);
        $this->actingAs($other)->getJson('/api/3d/'.$job->job_id)->assertNotFound();
        $this->get($job->model_url)->assertNotFound();
        $this->postJson('/api/3d/'.$job->job_id.'/cancel')->assertNotFound();
        $this->deleteJson('/api/3d/'.$job->job_id)->assertNotFound();
    }

    public function test_native_assets_keep_session_device_identity_but_recheck_approval(): void
    {
        [$user, $model, $asset, $input] = $this->fixture();
        UserDevice::create([
            'user_id' => $user->id, 'device_hash' => hash('sha256', 'another-browser'),
            'device_name' => 'Another browser', 'device_type' => 'browser', 'status' => 'active',
        ]);
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0';
        $this->withHeaders([
            'User-Agent' => $userAgent, 'Accept-Language' => 'en-US,en;q=0.9',
            'Sec-CH-UA' => '"Chromium";v="140"', 'Sec-CH-UA-Platform' => '"Windows"',
        ]);
        $this->fakeProvider($this->glb());
        $job = $this->submitAndPoll($user, $input);
        $browser = UserDevice::where('user_id', $user->id)->where('user_agent', $userAgent)->sole();
        $this->get($job->model_url)->assertOk()->assertHeader('Content-Type', 'model/gltf-binary');

        $session = $this->app['session.store'];
        $session->regenerate();
        $session->save();
        $this->withCookie($session->getName(), $session->getId())->flushHeaders();
        // Symfony supplies a default language unless it is explicitly removed.
        $nativeHeaders = ['User-Agent' => $userAgent, 'Accept-Language' => null, 'Sec-Fetch-Mode' => 'navigate'];
        $this->get($job->model_url, [...$nativeHeaders, 'Sec-Fetch-Mode' => 'no-cors'])
            ->assertOk()->assertHeader('Content-Type', 'model/gltf-binary');
        $this->get($job->model_url.'?download=1', $nativeHeaders)
            ->assertOk()->assertHeader('Content-Type', 'model/gltf-binary')
            ->assertHeader('Content-Disposition', 'attachment; filename=model-'.$job->job_id.'.glb');
        $this->assertDatabaseCount('user_devices', 2);
        $this->assertSame(2, UserDevice::where('user_id', $user->id)->where('status', 'active')->count());

        $browser->update(['status' => 'blocked']);
        $this->get($job->model_url.'?download=1', $nativeHeaders)
            ->assertForbidden()->assertJsonPath('device_blocked', true);
        $browser->update(['status' => 'pending']);
        $this->get($job->model_url, $nativeHeaders)
            ->assertForbidden()->assertJsonPath('device_pending', true);
        $browser->update(['status' => 'active']);
        $this->get($job->model_url.'?download=1', $nativeHeaders)->assertOk();
        $this->assertDatabaseCount('user_devices', 2);
    }

    public function test_hash_conflict_precedes_validation_and_owned_active_assets_are_required_before_charge(): void
    {
        [$user, $model, $asset, $input] = $this->fixture();
        $this->actingAs($user)->postJson('/api/3d', [...$input, 'expected_capability_hash' => str_repeat('0', 64), 'unknown_old_field' => true])
            ->assertConflict();
        $this->postJson('/api/3d', [...$input, 'expected_price_tokens' => 51])->assertConflict();
        $this->postJson('/api/3d', [...$input, 'seed' => 'not-an-integer'])->assertUnprocessable();
        $other = User::factory()->create();
        $asset->update(['user_id' => $other->id]);
        $this->postJson('/api/3d', $input)->assertForbidden();
        $asset->update(['user_id' => $user->id, 'expires_at' => now()->subSecond()]);
        $this->postJson('/api/3d', $input)->assertUnprocessable();
        $asset->update(['expires_at' => null, 'retention_status' => 'deleted']);
        $this->postJson('/api/3d', $input)->assertUnprocessable();
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('three_d_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_ambiguous_paid_submission_is_never_repeated_and_refunds_once(): void
    {
        [$user, $model, $asset, $input] = $this->fixture();
        $submissions = 0;
        Http::fake(['https://queue.fal.run/fal-ai/trellis-2' => function () use (&$submissions) {
            $submissions++;
            throw new ConnectionException('Connection closed after request bytes were sent.');
        }]);
        $id = $this->actingAs($user)->postJson('/api/3d', $input)->assertAccepted()->json('jobs.0.job_id');
        $job = ThreeDJob::where('job_id', $id)->firstOrFail();
        $service = app(ThreeDGenerationService::class);
        $service->process($job->id);
        $service->process($job->id);
        $this->travel(10)->minutes();
        $service->reconcile();
        $this->postJson('/api/3d', $input)->assertAccepted()->assertJsonPath('jobs.0.stage', 'failed');
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertSame(1, $submissions);
        $this->assertSame('released', $job->fresh()->billing_status);
        $this->assertDatabaseCount('token_reservations', 1);
        $this->postJson('/api/3d', [...$input, 'seed' => 42])->assertConflict();
    }

    public function test_invalid_glb_cannot_settle_and_private_staging_is_removed(): void
    {
        [$user, $model, $asset, $input] = $this->fixture();
        $invalid = substr_replace($this->glb(), pack('V', 12), 8, 4);
        $this->fakeProvider($invalid);
        $job = $this->submitAndPoll($user, $input);
        $this->assertSame('failed', $job->status);
        $this->assertSame(500, UserToken::getBalance($user->id));
        Storage::disk('local')->assertMissing(GeneratedModel3dStore::path($job->job_id));
        $this->get('/api/3d/'.$job->job_id.'/asset')->assertNotFound();
    }

    public function test_external_resources_and_unsupported_required_extensions_preserve_download_only_originals(): void
    {
        [$user, $model, $asset, $input] = $this->fixture();
        foreach ([
            ['images' => [['uri' => 'https://untrusted.invalid/texture.png']]],
            ['extensionsRequired' => ['KHR_draco_mesh_compression']],
            ['extensionsUsed' => ['KHR_texture_basisu']],
            ['meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'extensions' => ['EXT_meshopt_compression' => []]]]]]],
        ] as $index => $override) {
            $bytes = $this->glb($override);
            $this->fakeProvider($bytes);
            $job = $this->submitAndPoll($user, [...$input, 'idempotency_key' => 'download-only-'.$index]);
            $this->assertSame('completed', $job->status);
            $this->assertFalse($job->previewable);
            $this->assertNotNull($job->preview_unavailable_reason);
            $this->assertSame($bytes, Storage::disk('local')->get($job->model_path));
            $this->get($job->model_url.'?download=1')->assertOk()->assertHeader('Content-Type', 'model/gltf-binary');
        }
        $this->assertSame(300, UserToken::getBalance($user->id));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'untrusted.invalid'));
    }

    public function test_interrupted_save_recovers_local_original_without_repeating_provider_submission(): void
    {
        [$user, $model, $asset, $input] = $this->fixture();
        $id = $this->actingAs($user)->postJson('/api/3d', $input)->assertAccepted()->json('jobs.0.job_id');
        $job = ThreeDJob::where('job_id', $id)->firstOrFail();
        Storage::disk('local')->put(GeneratedModel3dStore::path($id), $this->glb());
        $job->update(['status' => 'processing', 'stage' => 'saving', 'upstream_job_id' => 'accepted-request',
            'submitted_at' => now()->subMinutes(8), 'processing_started_at' => now()->subMinutes(6),
            'processing_token' => (string) Str::uuid(), 'next_poll_at' => null,
            'provider_result_url' => 'https://v3.fal.media/expired.glb']);
        $service = app(ThreeDGenerationService::class);
        $service->reconcile();
        $service->poll($job->id);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame(450, UserToken::getBalance($user->id));
        Http::assertNothingSent();
    }

    public function test_owner_can_cancel_only_unsubmitted_job_and_recovery_releases_abandoned_claim_once(): void
    {
        [$user, $model, $asset, $input] = $this->fixture();
        $id = $this->actingAs($user)->postJson('/api/3d', $input)->assertAccepted()->json('jobs.0.job_id');
        $this->postJson('/api/3d/'.$id.'/cancel')->assertOk()->assertJsonPath('job.stage', 'cancelled');
        $this->postJson('/api/3d/'.$id.'/cancel')->assertOk();
        $id = $this->postJson('/api/3d', [...$input, 'idempotency_key' => 'interrupted-worker'])->assertAccepted()->json('jobs.0.job_id');
        $job = ThreeDJob::where('job_id', $id)->firstOrFail();
        $job->update(['status' => 'processing', 'stage' => 'submitting', 'processing_started_at' => now()->subMinutes(10),
            'processing_token' => (string) Str::uuid()]);
        $service = app(ThreeDGenerationService::class);
        $service->reconcile();
        $service->reconcile();
        $service->process($job->id);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertSame('released', $job->fresh()->billing_status);
        Http::assertNothingSent();
    }

    public function test_reconciliation_releases_a_timed_out_provider_request_even_when_poll_dispatch_is_unavailable(): void
    {
        [$user, $model, $asset, $input] = $this->fixture();
        $this->fakeProvider($this->glb());
        $id = $this->actingAs($user)->postJson('/api/3d', $input)->assertAccepted()->json('jobs.0.job_id');
        $job = ThreeDJob::where('job_id', $id)->firstOrFail();
        $service = app(ThreeDGenerationService::class);
        $service->process($job->id);
        $this->travel(31)->minutes();
        $service->reconcile();
        $service->reconcile();
        $this->assertSame('released', $job->fresh()->billing_status);
        $this->assertSame(500, UserToken::getBalance($user->id));
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public function test_model_output_quota_failure_preserves_the_paid_result_for_save_only_retry(): void
    {
        [$user, $model, $asset, $input] = $this->fixture();
        $bytes = $this->glb();
        $this->fakeProvider($bytes);
        $quota = app(\App\Services\StorageQuotaService::class);
        $baseline = $quota->usedBytes($user);
        config(['storage_quota.base_bytes' => $baseline + strlen($bytes) - 1]);
        $job = $this->submitAndPoll($user, $input);
        $this->assertSame('save_failed', $job->stage);
        $this->assertSame('reserved', $job->billing_status);
        $this->assertSame($baseline, $quota->usedBytes($user));
        $this->assertSame([], Storage::disk('local')->allFiles(dirname(GeneratedModel3dStore::path($job->job_id))));
        $this->travel(1)->hours();
        app(ThreeDGenerationService::class)->reconcile();
        $this->assertSame(450, UserToken::getBalance($user->id));
        $model->provider()->update(['is_enabled' => false]);
        config(['storage_quota.base_bytes' => $baseline + strlen($bytes)]);
        $this->failNextDownload = true;
        $this->postJson('/api/media/workspace/jobs/model3d:'.$job->job_id.'/retry-save')->assertAccepted();
        app(ThreeDGenerationService::class)->poll($job->id);
        $this->assertSame('save_failed', $job->fresh()->stage);
        $this->assertSame('reserved', $job->fresh()->billing_status);
        $this->postJson('/api/media/workspace/jobs/model3d:'.$job->job_id.'/retry-save')->assertAccepted();
        app(ThreeDGenerationService::class)->poll($job->id);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('settled', $job->fresh()->billing_status);
        $this->assertSame($bytes, Storage::disk('local')->get($job->fresh()->model_path));
        $this->assertSame($baseline + strlen($bytes), $quota->usedBytes($user));
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
    }

    private function fixture(): array
    {
        $provider = AiProviderProfile::create(['name' => '3D fixture', 'slug' => 'fal-3d-fixture', 'protocol' => 'fal',
            'base_url' => 'https://fal.run', 'api_key' => 'fixture-only-key', 'is_enabled' => true]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'fal-ai/trellis-2',
            'upstream_model_id' => 'fal-ai/trellis-2', 'display_name' => 'Trellis 2', 'category' => 'model3d',
            'token_cost' => 50, 'is_enabled' => true, 'is_available' => true]);
        $user = User::factory()->create(['is_active' => true, 'expires_at' => now()->addDay(), 'permissions' => User::DEFAULT_PERMISSIONS]);
        UserToken::topup($user->id, 500);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $asset = app(AssetService::class)->store($user, UploadedFile::fake()->createWithContent('reference.png', $png), InputRole::ImageRef);
        $hash = app(CapabilityResolver::class)->resolve($model->load('provider'), MediaOperation::ImageTo3d)->sourceHash;

        return [$user, $model, $asset, ['model' => $model->model_id, 'operation' => 'image_to_3d', 'image_ref' => $asset->id,
            'expected_price_tokens' => 50, 'expected_capability_hash' => $hash, 'idempotency_key' => 'first-request']];
    }

    private function submitAndPoll(User $user, array $input): ThreeDJob
    {
        $id = $this->actingAs($user)->postJson('/api/3d', $input)->assertAccepted()->json('jobs.0.job_id');
        $job = ThreeDJob::where('job_id', $id)->firstOrFail();
        $service = app(ThreeDGenerationService::class);
        $service->process($job->id);
        $this->travel(9)->seconds();
        $service->poll($job->id);

        return $job->fresh();
    }

    private function fakeProvider(string $bytes): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://queue.fal.run/fal-ai/trellis-2' => fn () => Http::response(['request_id' => (string) Str::uuid(), 'status' => 'IN_QUEUE', 'response_url' => 'http://127.0.0.1/ignored']),
            'https://queue.fal.run/fal-ai/trellis-2/requests/*/status' => Http::response(['status' => 'COMPLETED']),
            'https://queue.fal.run/fal-ai/trellis-2/requests/*' => Http::response(['model_glb' => ['url' => 'https://v3.fal.media/model.glb']]),
            'https://v3.fal.media/model.glb' => function (Request $request) use ($bytes) {
                $this->assertFalse($request->hasHeader('Authorization'));
                if ($this->failNextDownload) {
                    $this->failNextDownload = false;

                    return Http::response('Original temporarily unavailable', 404);
                }

                return Http::response($bytes, 200, ['Content-Type' => 'application/octet-stream']);
            },
        ]);
    }

    private function glb(array $override = []): string
    {
        $bin = pack('g*', -1.0, -1.0, 0.0, 1.0, -1.0, 0.0, 0.0, 1.0, 0.0);
        $json = json_encode(array_replace(['asset' => ['version' => '2.0'], 'scene' => 0, 'scenes' => [['nodes' => [0]]],
            'nodes' => [['mesh' => 0]], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]],
            'buffers' => [['byteLength' => strlen($bin)]], 'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => strlen($bin)]],
            'accessors' => [['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3', 'min' => [-1, -1, 0], 'max' => [1, 1, 0]]]], $override), JSON_THROW_ON_ERROR);
        $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);

        return 'glTF'.pack('VV', 2, 28 + strlen($json) + strlen($bin)).pack('VV', strlen($json), 0x4E4F534A).$json
            .pack('VV', strlen($bin), 0x004E4942).$bin;
    }
}
