<?php

namespace Tests\Feature\Media;

use App\Media\CapabilityResolver;
use App\Media\Enums\MediaOperation;
use App\Media\FalRealtimeCapabilityImporter;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaAsset;
use App\Models\MediaCapabilityRevision;
use App\Models\RealtimeMediaSession;
use App\Models\User;
use App\Models\UserToken;
use App\Services\AiProviderEndpoint;
use App\Services\MediaTokenBillingService;
use App\Services\RealtimeMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealtimeMediaSessionTest extends TestCase
{
    use RefreshDatabase;

    // A complete non-trickle offer: receive-only media, the control data channel and a gathered candidate.
    private const OFFER = "v=0\r\no=- 4611731400430051336 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=group:BUNDLE 0 1 2\r\n"
        ."m=video 9 UDP/TLS/RTP/SAVPF 96\r\nc=IN IP4 0.0.0.0\r\na=mid:0\r\na=recvonly\r\n"
        ."a=candidate:842163049 1 udp 1677729535 198.51.100.7 49203 typ srflx raddr 0.0.0.0 rport 0\r\n"
        ."m=audio 9 UDP/TLS/RTP/SAVPF 111\r\nc=IN IP4 0.0.0.0\r\na=mid:1\r\na=recvonly\r\n"
        ."m=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\nc=IN IP4 0.0.0.0\r\na=mid:2\r\na=sctp-port:5000\r\n";

    private const ANSWER = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=group:BUNDLE 0 1 2\r\n";

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['media.coordinator_restricted' => false, 'media.kill_switch' => false, 'realtime_media.max_session_seconds' => 60,
            'realtime_media.max_recordings_per_session' => 3]);
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    public function test_director_contract_keeps_protocol_constants_server_side_and_the_full_update_schema(): void
    {
        $normalized = $this->normalized();
        $this->assertTrue($normalized['publishable'], implode(' ', $normalized['report']['blockers']));
        $input = $normalized['capability']->inputSchema;
        foreach (['type', 'protocol_version', 'prompt_version'] as $controlled) {
            $this->assertArrayNotHasKey($controlled, $input['properties']);
        }
        $this->assertSame(['prompt'], $input['required']);
        $image = collect($input['properties']['image_url']['anyOf'])->firstWhere('type', 'string');
        // The same file leaf as queued contracts: an owned upload or a public HTTPS link the provider fetches.
        $this->assertSame(['kind' => 'image', 'role' => 'init_frame', 'accepts_url' => true], $image['x-workspace-asset']);
        $this->assertArrayNotHasKey('format', $image);
        $bindings = $normalized['provider_bindings'];
        $this->assertSame(['fal_wma_v1', 'minimax/h3-max/director', 'realtime'], [$bindings['adapter'], $bindings['endpoint'], $bindings['transport']]);
        $update = $bindings['execution']['update_schema'];
        $this->assertArrayNotHasKey('prompt_version', $update['properties']);
        foreach (['prompt', 'script', 'audio_url', 'end_image_url', 'audio_behavior', 'replan', 'script_mode'] as $field) {
            $this->assertArrayHasKey($field, $update['properties']);
        }
        $this->assertSame(60, RealtimeMediaService::maxSessionSeconds($bindings));
        config(['realtime_media.max_session_seconds' => 600]);
        $this->assertSame(60, RealtimeMediaService::maxSessionSeconds($bindings), 'Configuration may shorten, never extend, the reviewed bound.');
    }

    public function test_accepted_session_is_charged_once_and_a_lost_response_replays_without_another_offer(): void
    {
        [$user, $model] = $this->fixture();
        Http::fake(['https://wma.fal.run/session' => Http::response(['session_id' => 'sess-1', 'sdp' => self::ANSWER, 'type' => 'answer'])]);
        $request = $this->request($model);

        $first = $this->actingAs($user)->postJson('/api/media/realtime/sessions', $request)->assertCreated()
            ->assertJsonPath('session.status', 'connected')->assertJsonPath('session.billing_status', 'charged')
            ->assertJsonPath('session.max_session_seconds', 60)->assertJsonPath('answer.sdp', self::ANSWER)
            ->assertJsonPath('configure.type', 'configure')->assertJsonPath('configure.protocol_version', 1)
            ->assertJsonPath('configure.prompt_version', 1)->assertJsonPath('configure.prompt', 'A quiet harbor at dawn.')
            ->assertJsonMissingPath('configure.image_url')->assertJsonMissingPath('session.upstream_session_id');
        $this->assertSame(60, UserToken::getBalance($user->id));
        // Request middleware trims strings; the bridge still receives the byte-exact CRLF offer.
        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'https://wma.fal.run/session'
            && $sent->hasHeader('Authorization', 'Key test-only-key')
            && $sent->data() === ['app_id' => 'minimax/h3-max/director', 'sdp' => self::OFFER, 'type' => 'offer']);

        $replay = $this->actingAs($user)->postJson('/api/media/realtime/sessions', $request)->assertCreated();
        $this->assertSame($first->json('session.id'), $replay->json('session.id'));
        $this->assertSame(self::ANSWER, $replay->json('answer.sdp'));
        $this->assertNotSame($first->json('session_token'), $replay->json('session_token'));
        Http::assertSentCount(1);
        $this->assertSame(60, UserToken::getBalance($user->id));
        $this->assertDatabaseHas('token_reservations', ['reference_id' => 'realtime:'.$first->json('session.id'), 'status' => 'settled']);

        $this->actingAs($user)->postJson('/api/media/realtime/sessions', [...$request, 'inputs' => ['prompt' => 'A different scene.']])->assertStatus(409);
        $this->actingAs($user)->postJson('/api/media/realtime/sessions', [...$request, 'idempotency_key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('message', 'Another realtime session is still running. Stop it before starting a new one.');
        $this->actingAs($user)->postJson("/api/media/realtime/sessions/{$first->json('session.id')}/heartbeat",
            ['session_token' => $first->json('session_token')])->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_uncertain_acceptance_keeps_the_reservation_and_is_never_offered_again(): void
    {
        [$user, $model] = $this->fixture();
        Http::fake(['https://wma.fal.run/session' => Http::response('upstream timeout', 504)]);
        $request = $this->request($model);

        $this->actingAs($user)->postJson('/api/media/realtime/sessions', $request)->assertStatus(504)
            ->assertJsonPath('uncertain', true)->assertJsonPath('session.status', 'uncertain')->assertJsonPath('session.billing_status', 'reserved');
        $this->actingAs($user)->postJson('/api/media/realtime/sessions', $request)->assertStatus(504)->assertJsonPath('uncertain', true);
        $this->travel(2)->days();
        app(RealtimeMediaService::class)->reconcile();

        Http::assertSentCount(1);
        $this->assertSame(60, UserToken::getBalance($user->id));
        $this->assertSame('reserved', RealtimeMediaSession::query()->sole()->billing_status);
        $this->assertDatabaseHas('token_reservations', ['user_id' => $user->id, 'status' => 'reserved']);
    }

    public function test_definite_bridge_rejection_returns_the_reserved_tokens(): void
    {
        [$user, $model] = $this->fixture();
        Http::fake(['https://wma.fal.run/session' => Http::response(['detail' => 'Unknown app'], 422)]);

        $this->actingAs($user)->postJson('/api/media/realtime/sessions', $this->request($model))->assertStatus(502)
            ->assertJsonPath('session.status', 'failed')->assertJsonPath('session.billing_status', 'released');

        $this->assertSame(100, UserToken::getBalance($user->id));
        $this->assertDatabaseHas('token_reservations', ['user_id' => $user->id, 'status' => 'released']);
    }

    public function test_documented_script_rules_are_refused_before_any_reservation_or_offer(): void
    {
        [$user, $model] = $this->fixture();
        $image = (string) Str::uuid();

        $this->actingAs($user)->postJson('/api/media/realtime/sessions', $this->request($model, [
            'prompt' => 'A heist in three acts.', 'end_image_url' => $image, 'script' => [['offset' => 0, 'prompt' => 'Open on the vault.']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('inputs.end_image_url');
        $this->actingAs($user)->postJson('/api/media/realtime/sessions', $this->request($model, [
            'prompt' => 'A heist in three acts.', 'script' => [['offset' => 5, 'end_image_url' => $image], ['offset' => 7, 'end_image_url' => $image]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('inputs.script.1.offset');

        Http::assertNothingSent();
        $this->assertDatabaseCount('token_reservations', 0);
        $this->assertDatabaseCount('realtime_media_sessions', 0);
        $this->assertSame(100, UserToken::getBalance($user->id));
    }

    public function test_updates_wait_for_the_configured_acknowledgement_and_receive_new_versions_only_for_their_owner(): void
    {
        [$user, $model] = $this->fixture();
        Http::fake([
            'https://wma.fal.run/session' => Http::response(['session_id' => 'sess-2', 'sdp' => self::ANSWER, 'type' => 'answer']),
            'https://wma.fal.run/session/heartbeat' => Http::response(['alive' => true]),
        ]);
        $started = $this->actingAs($user)->postJson('/api/media/realtime/sessions', $this->request($model))->assertCreated();
        $id = $started->json('session.id');
        $token = $started->json('session_token');
        $input = fn (array $inputs) => $this->actingAs($user)->postJson("/api/media/realtime/sessions/{$id}/input", ['session_token' => $token, 'inputs' => $inputs]);

        $input(['prompt' => 'Walk to the pier.'])->assertStatus(409);
        $this->actingAs($user)->postJson("/api/media/realtime/sessions/{$id}/heartbeat", ['session_token' => $token, 'configured' => true])
            ->assertOk()->assertJsonPath('alive', true)->assertJsonPath('session.configured', true);
        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'https://wma.fal.run/session/heartbeat' && $sent->data() === ['session_id' => 'sess-2']);

        $input(['prompt' => 'Walk to the pier.'])->assertOk()->assertJsonPath('prompt_version', 2)
            ->assertJsonPath('message.type', 'prompt')->assertJsonPath('message.prompt_version', 2)->assertJsonPath('message.prompt', 'Walk to the pier.')
            ->assertJsonMissingPath('message.script');
        $input(['prompt' => 'Board the ferry.', 'script' => [['offset' => 0, 'prompt' => 'Horn sounds.']]])
            ->assertUnprocessable()->assertJsonValidationErrors('inputs.prompt');
        $input([])->assertUnprocessable();
        $input(['script' => [['offset' => 0, 'prompt' => 'Horn sounds.'], ['offset' => 4, 'prompt' => 'Gulls scatter.']], 'script_mode' => 'append'])
            ->assertOk()->assertJsonPath('prompt_version', 3)->assertJsonPath('message.script.1.offset', 4)->assertJsonMissingPath('message.script.0.audio_url');

        $other = User::factory()->create(['permissions' => ['video_generator' => true]]);
        $this->actingAs($other)->getJson("/api/media/realtime/sessions/{$id}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/media/realtime/sessions/{$id}/input", ['session_token' => $token, 'inputs' => ['prompt' => 'Hijack.']])->assertNotFound();
        $this->actingAs($other)->postJson("/api/media/realtime/sessions/{$id}/close")->assertNotFound();
        $this->actingAs($other)->getJson('/api/media/realtime/sessions')->assertOk()->assertJsonCount(0, 'sessions');
        $this->assertSame(4, RealtimeMediaSession::query()->findOrFail($id)->next_prompt_version);
    }

    public function test_server_lease_ends_at_the_reviewed_bound_even_when_the_browser_keeps_beating(): void
    {
        [$user, $model] = $this->fixture();
        config(['realtime_media.max_session_seconds' => 20]);
        Http::fake(['https://wma.fal.run/session' => Http::response(['session_id' => 'sess-3', 'sdp' => self::ANSWER, 'type' => 'answer'])]);
        $started = $this->actingAs($user)->postJson('/api/media/realtime/sessions', $this->request($model))
            ->assertCreated()->assertJsonPath('session.max_session_seconds', 20);
        $id = $started->json('session.id');

        $this->travel(21)->seconds();
        // Any forwarded heartbeat would be a stray HTTP request and fail this test.
        $this->actingAs($user)->postJson("/api/media/realtime/sessions/{$id}/heartbeat", ['session_token' => $started->json('session_token')])
            ->assertOk()->assertJsonPath('alive', false)->assertJsonPath('session.status', 'expired');
        $this->actingAs($user)->postJson("/api/media/realtime/sessions/{$id}/input", ['session_token' => $started->json('session_token'), 'inputs' => ['prompt' => 'More.']])
            ->assertStatus(409);

        Http::assertSentCount(1);
        $this->assertSame('charged', RealtimeMediaSession::query()->findOrFail($id)->billing_status);
        $this->assertSame(60, UserToken::getBalance($user->id));
    }

    public function test_recordings_are_owned_video_assets_bounded_per_session_and_show_when_unavailable(): void
    {
        [$user, $model] = $this->fixture();
        config(['realtime_media.max_recordings_per_session' => 1]);
        Http::fake(['https://wma.fal.run/session' => Http::response(['session_id' => 'sess-4', 'sdp' => self::ANSWER, 'type' => 'answer'])]);
        $id = $this->actingAs($user)->postJson('/api/media/realtime/sessions', $this->request($model))->assertCreated()->json('session.id');
        $this->actingAs($user)->postJson("/api/media/realtime/sessions/{$id}/close", ['reason' => 'stopped'])->assertOk()->assertJsonPath('session.status', 'closed');
        $webm = hex2bin('1A45DFA39F4286810142F7810142F2810442F381084282847765626D4287810442858102').hex2bin('1853806701FFFFFFFFFFFFFF').str_repeat("\0", 64);
        $upload = fn () => $this->actingAs($user)->post("/api/media/realtime/sessions/{$id}/recording",
            ['file' => UploadedFile::fake()->createWithContent('session.webm', $webm)], ['Accept' => 'application/json']);

        $saved = $upload()->assertCreated()->assertJsonPath('recording.available', true)->assertJsonPath('session.recordings.0.available', true);
        $asset = MediaAsset::query()->findOrFail($saved->json('recording.id'));
        $this->assertSame([$user->id, 'video', 'reference_video'], [$asset->user_id, $asset->media_type, $asset->role]);
        $upload()->assertStatus(409);
        $this->assertSame(1, MediaAsset::query()->count());
        $this->assertSame(60, UserToken::getBalance($user->id), 'Stopping an accepted session never refunds it.');

        $asset->forceFill(['retention_status' => 'expired'])->save();
        $this->actingAs($user)->getJson("/api/media/realtime/sessions/{$id}")->assertOk()
            ->assertJsonPath('session.recordings.0.available', false)->assertJsonPath('session.recordings.0.id', $asset->id);
    }

    public function test_recovery_refunds_only_unsent_offers_and_never_an_interrupted_negotiation(): void
    {
        [$user, $model] = $this->fixture();
        UserToken::topup($user->id, 100);
        $stale = $this->row($user, $model, 'preparing');
        $stale->forceFill(['created_at' => now()->subMinutes(11)])->save();
        $interrupted = $this->row($user, $model, 'negotiating', ['offered_at' => now()->subMinutes(3)]);
        $stopped = $this->row($user, $model, 'preparing');
        $this->assertSame(80, UserToken::getBalance($user->id));

        $this->actingAs($user)->postJson("/api/media/realtime/sessions/{$stopped->id}/close", ['reason' => 'stopped'])->assertOk()
            ->assertJsonPath('session.status', 'closed')->assertJsonPath('session.billing_status', 'released');
        $this->assertSame(['expired' => 0, 'abandoned' => 1, 'uncertain' => 1], app(RealtimeMediaService::class)->reconcile());
        $this->assertSame(['expired' => 0, 'abandoned' => 0, 'uncertain' => 0], app(RealtimeMediaService::class)->reconcile());

        $this->assertSame(['failed', 'released'], [$stale->fresh()->status, $stale->fresh()->billing_status]);
        $this->assertSame(['uncertain', 'reserved'], [$interrupted->fresh()->status, $interrupted->fresh()->billing_status]);
        $this->assertSame(160, UserToken::getBalance($user->id), 'Only the two offers that never reached the bridge are refunded.');
        Http::assertNothingSent();
    }

    public function test_ice_discovery_uses_the_bridge_then_reports_a_stun_only_fallback_truthfully(): void
    {
        [$user, $model] = $this->fixture();
        Http::fake([
            'https://wma.fal.run/ice' => Http::sequence()
                ->push(['ice_servers' => [['urls' => ['turn:turn.example.test:3478?transport=udp'], 'username' => 'ephemeral', 'credential' => 'secret']]])
                ->push('bridge unavailable', 503),
            'https://fal.run/minimax/h3-max/director/ice' => Http::response(['detail' => 'Not found'], 404),
        ]);
        $body = ['model' => $model->model_id, 'operation' => 'realtime_video'];

        $this->actingAs($user)->postJson('/api/media/realtime/ice', $body)->assertOk()->assertJsonPath('source', 'bridge')
            ->assertJsonPath('ice_servers.0.urls.0', 'turn:turn.example.test:3478?transport=udp')->assertJsonPath('ice_servers.0.username', 'ephemeral');
        $this->actingAs($user)->postJson('/api/media/realtime/ice', $body)->assertOk()->assertJsonPath('source', 'stun')
            ->assertJsonPath('ice_servers.0.urls.0', 'stun:stun.l.google.com:19302');

        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'https://wma.fal.run/ice' && $sent->data() === ['app_id' => 'minimax/h3-max/director']);
        $this->assertDatabaseCount('token_reservations', 0);
    }

    public function test_file_fields_accept_a_safe_public_link_and_refuse_private_ones_before_any_offer(): void
    {
        [$user, $model] = $this->fixture();
        Http::fake(['https://wma.fal.run/session' => Http::response(['session_id' => 'sess-5', 'sdp' => self::ANSWER, 'type' => 'answer'])]);
        $this->actingAs($user)->postJson('/api/media/realtime/sessions', $this->request($model, ['prompt' => 'A harbor.', 'image_url' => 'http://127.0.0.1/frame.png']))
            ->assertUnprocessable()->assertJsonValidationErrors('inputs.image_url');
        Http::assertNothingSent();
        $this->assertSame(100, UserToken::getBalance($user->id));

        $this->actingAs($user)->postJson('/api/media/realtime/sessions', $this->request($model, ['prompt' => 'A harbor.', 'image_url' => 'https://images.example.com/frame.png']))
            ->assertCreated()->assertJsonPath('configure.image_url', 'https://images.example.com/frame.png');
        Http::assertSentCount(1);
    }

    private function normalized(): array
    {
        $document = json_decode((string) file_get_contents(resource_path('media/fal/minimax-h3-max-director.asyncapi.json')), true, 512, JSON_THROW_ON_ERROR);

        return app(FalRealtimeCapabilityImporter::class)->normalize('director-live', 'minimax/h3-max/director', ['category' => 'text-to-video'], $document, str_repeat('d', 64));
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['permissions' => ['video_generator' => true]]);
        UserToken::topup($user->id, 100);
        $provider = AiProviderProfile::create(['slug' => 'realtime-fal', 'name' => 'Fal', 'protocol' => 'fal',
            'base_url' => 'https://fal.run', 'api_key' => 'test-only-key', 'is_enabled' => true,
            'status' => 'healthy', 'authenticated_at' => now()]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'director-live',
            'upstream_model_id' => 'minimax/h3-max/director', 'display_name' => 'H3 Max Director', 'category' => 'video',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 40]);
        $normalized = $this->normalized();
        MediaCapabilityRevision::create(['ai_model_profile_id' => $model->id, 'operation' => 'realtime_video',
            'contract_version' => 2, 'revision' => 1, 'status' => 'published', 'published_at' => now(),
            'source_hash' => str_repeat('d', 64), 'source_schema' => ['asyncapi' => '3.1.0'],
            'definition' => $normalized['capability']->toArray(), 'provider_bindings' => $normalized['provider_bindings'],
            'curation_overrides' => ['pricing' => ['token_cost' => 40, 'unit' => 'request', 'variable_configuration' => true,
                'max_session_seconds' => 60, 'reviewed_by' => $user->id, 'reviewed_at' => now()->toISOString()]],
        ]);

        return [$user, $model->fresh('provider')];
    }

    private function request(AiModelProfile $model, array $inputs = ['prompt' => 'A quiet harbor at dawn.']): array
    {
        return ['model' => $model->model_id, 'operation' => 'realtime_video', 'inputs' => $inputs,
            'expected_capability_hash' => app(CapabilityResolver::class)->resolve($model, MediaOperation::RealtimeVideo, schemaContracts: true)->sourceHash,
            'expected_price_tokens' => 40, 'idempotency_key' => (string) Str::uuid(), 'sdp' => self::OFFER];
    }

    /** A durable session row exactly as admission leaves it, with a real token reservation. */
    private function row(User $user, AiModelProfile $model, string $status, array $attributes = []): RealtimeMediaSession
    {
        $id = (string) Str::uuid();
        $revision = MediaCapabilityRevision::query()->where('ai_model_profile_id', $model->id)->firstOrFail();
        $reservation = app(MediaTokenBillingService::class)->reserve($user, 'media', $model->model_id, 1, 'realtime:'.$id, 40);
        $session = new RealtimeMediaSession([
            'user_id' => $user->id, 'provider_id' => $model->provider_id, 'capability_revision_id' => $revision->id,
            'model' => $model->model_id, 'model_label' => 'H3 Max Director', 'request_key' => hash('sha256', $id),
            'payload_fingerprint' => str_repeat('2', 64), 'connection_fingerprint' => str_repeat('3', 64),
            'offer_fingerprint' => str_repeat('4', 64), 'session_token_hash' => str_repeat('5', 64), 'capability_hash' => str_repeat('d', 64),
            'capability_snapshot' => $revision->definition, 'provider_bindings' => $revision->provider_bindings,
            'normalized_inputs' => ['prompt' => 'A quiet harbor at dawn.'], 'asset_ids' => [], 'recording_asset_ids' => [],
            'control_updates' => [], 'billing_reservation' => $reservation, 'price_tokens' => 40, 'billing_status' => 'reserved',
            'status' => $status, 'max_session_seconds' => 60, ...$attributes,
        ]);
        $session->id = $id;
        $session->save();

        return $session;
    }
}
