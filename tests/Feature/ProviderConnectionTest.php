<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\UsageRate;
use App\Models\User;
use App\Services\AiProviderEndpoint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    public function test_admin_can_save_and_rotate_a_provider_without_disclosing_the_key(): void
    {
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => 'admin']);
        $secret = 'provider-fixture-secret-never-return';

        $response = $this->actingAs($admin)->postJson('/api/admin/ai/providers', [
            'name' => 'Official connection',
            'slug' => 'official-connection',
            'protocol' => 'openai',
            'base_url' => 'https://api.openai.com/v1/',
            'api_key' => $secret,
        ])->assertCreated()->assertJsonPath('provider.has_api_key', true);

        $providerId = $response->json('provider.id');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $stored = DB::table('ai_provider_profiles')->where('id', $providerId)->value('api_key');
        $this->assertNotSame($secret, $stored);
        $this->assertSame($secret, AiProviderProfile::findOrFail($providerId)->api_key);

        $this->patchJson('/api/admin/ai/providers/'.$providerId, [
            'name' => 'Renamed connection',
            'api_key' => '',
        ])->assertOk()->assertJsonPath('provider.has_api_key', true);
        $this->assertSame($secret, AiProviderProfile::findOrFail($providerId)->api_key);
        $this->patchJson('/api/admin/ai/providers/'.$providerId, ['api_key' => ''])->assertOk();
        $this->assertSame($secret, AiProviderProfile::findOrFail($providerId)->api_key);

        $replacement = 'replacement-fixture-secret-never-return';
        $this->patchJson('/api/admin/ai/providers/'.$providerId, [
            'api_key' => $replacement,
        ])->assertOk();
        $this->assertSame($replacement, AiProviderProfile::findOrFail($providerId)->api_key);
        $catalog = $this->getJson('/api/admin/ai/catalog')->assertOk()->getContent();
        $audit = DB::table('audit_events')->pluck('metadata')->implode(' ');
        foreach ([$secret, $replacement] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $catalog);
            $this->assertStringNotContainsString($privateValue, $audit);
        }
    }

    public function test_members_cannot_configure_delete_check_or_sync_provider_connections(): void
    {
        $provider = $this->provider('restricted');
        $this->actingAs(User::factory()->create(['role' => 'member']));

        $this->postJson('/api/admin/ai/providers', [
            'name' => 'Denied', 'protocol' => 'openai',
            'base_url' => 'https://denied.example.test/v1', 'api_key' => 'denied-key',
        ])->assertForbidden();
        $this->patchJson('/api/admin/ai/providers/'.$provider->id, ['is_enabled' => false])->assertForbidden();
        $this->deleteJson('/api/admin/ai/providers/'.$provider->id, [
            'delete_models' => true, 'expected_model_count' => 0,
        ])->assertForbidden();
        $this->postJson('/api/admin/ai/providers/'.$provider->id.'/check')->assertForbidden();
        $this->postJson('/api/admin/ai/providers/'.$provider->id.'/sync')->assertForbidden();

        $this->assertDatabaseCount('ai_provider_profiles', 1);
        $this->assertTrue($provider->fresh()->is_enabled);
        $this->assertDatabaseCount('audit_events', 0);
        Http::assertNothingSent();
    }

    public function test_connection_changes_require_an_explicit_key_and_invalidate_only_linked_availability(): void
    {
        $provider = $this->provider('rotate');
        $model = $this->model($provider, 'rotate-public', 'native-model');
        $other = $this->model($this->provider('other'), 'other-public', 'native-model');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->patchJson('/api/admin/ai/providers/'.$provider->id, [
            'base_url' => 'https://replacement.example.test/custom', 'api_key' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('api_key');
        $this->patchJson('/api/admin/ai/providers/'.$provider->id, [
            'protocol' => 'anthropic',
        ])->assertUnprocessable()->assertJsonValidationErrors('api_key');
        $this->assertSame('https://rotate.example.test/v1', $provider->fresh()->base_url);
        $this->assertTrue($model->fresh()->is_available);

        $this->patchJson('/api/admin/ai/providers/'.$provider->id, [
            'base_url' => 'https://replacement.example.test/custom/',
            'protocol' => 'anthropic', 'api_key' => 'replacement-private-key',
        ])->assertOk()->assertJsonPath('provider.base_url', 'https://replacement.example.test/custom')
            ->assertJsonPath('provider.status', 'unknown')->assertJsonPath('provider.last_checked_at', null);

        $this->assertFalse($model->fresh()->is_available);
        $this->assertTrue($model->fresh()->is_enabled);
        $this->assertTrue($other->fresh()->is_available);
        $serialized = $model->fresh('provider')->toJson();
        $audit = DB::table('audit_events')->pluck('metadata')->implode(' ');
        foreach (['replacement-private-key', 'replacement.example.test', 'rotate-private-key'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $serialized);
            $this->assertStringNotContainsString($privateValue, $audit);
        }
    }

    public function test_fal_connections_keep_the_official_root_and_reject_openai_paths_on_partial_updates(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->postJson('/api/admin/ai/providers', [
            'name' => 'Fal connection', 'protocol' => 'fal', 'base_url' => 'https://fal.run/',
            'api_key' => 'fal-fixture-private-key',
        ])->assertCreated()->assertJsonPath('provider.protocol', 'fal')
            ->assertJsonPath('provider.base_url', 'https://fal.run')->assertJsonPath('provider.models_count', 0);
        $provider = AiProviderProfile::findOrFail($response->json('provider.id'));

        $this->patchJson('/api/admin/ai/providers/'.$provider->id, [
            'base_url' => 'https://fal.run/v1', 'api_key' => 'replacement-fal-private-key',
        ])->assertUnprocessable()->assertJsonValidationErrors('base_url');
        $this->assertSame('https://fal.run', $provider->fresh()->base_url);
        $this->assertSame('fal-fixture-private-key', $provider->fresh()->api_key);
        Http::assertNothingSent();
    }

    public function test_protocol_only_fal_updates_cannot_reuse_a_non_fal_stored_endpoint(): void
    {
        $provider = $this->provider('wrong-fal-endpoint');
        $model = $this->model($provider, 'wrong-fal-public', 'native-model');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->patchJson('/api/admin/ai/providers/'.$provider->id, [
            'protocol' => 'fal', 'api_key' => 'replacement-fal-private-key',
        ])->assertUnprocessable()->assertJsonValidationErrors('base_url');

        $this->assertSame('openai', $provider->fresh()->protocol);
        $this->assertSame('wrong-fal-endpoint-private-key', $provider->fresh()->api_key);
        $this->assertTrue($model->fresh()->is_available);
        $this->assertDatabaseCount('audit_events', 0);
        foreach (['wrong-fal-endpoint.example.test', 'replacement-fal-private-key'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_protocol_only_fal_update_accepts_a_saved_official_root_and_requires_key_rotation(): void
    {
        $provider = $this->provider('fal-migration');
        $provider->update(['base_url' => 'https://fal.run']);
        $model = $this->model($provider, 'migrated-fal-public', 'native-model');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson('/api/admin/ai/providers/'.$provider->id, [
            'protocol' => 'fal',
        ])->assertUnprocessable()->assertJsonValidationErrors('api_key');

        $this->patchJson('/api/admin/ai/providers/'.$provider->id, [
            'protocol' => 'fal', 'api_key' => 'new-fal-private-key',
        ])->assertOk()->assertJsonPath('provider.protocol', 'fal')
            ->assertJsonPath('provider.base_url', 'https://fal.run')->assertJsonPath('provider.models_count', 1);

        $this->assertFalse($model->fresh()->is_available);
        $this->assertSame('new-fal-private-key', $provider->fresh()->api_key);
        Http::assertNothingSent();
    }

    public function test_environment_profiles_do_not_expose_or_replace_server_managed_connection_settings(): void
    {
        config()->set([
            'services.ai_proxy.url' => 'https://environment-private.example.test',
            'services.ai_proxy.key' => 'environment-private-key',
        ]);
        $provider = AiProviderProfile::create(['slug' => 'ai-proxy', 'name' => 'Environment']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->patchJson('/api/admin/ai/providers/'.$provider->id, [
            'name' => 'Renamed environment', 'is_enabled' => false,
        ])->assertOk()->assertJsonPath('provider.configuration_source', 'environment')
            ->assertJsonPath('provider.base_url', null)->assertJsonPath('provider.has_api_key', true);
        $this->assertStringNotContainsString('environment-private', $response->getContent());
        $this->assertNull($provider->fresh()->base_url);
        $this->assertNull($provider->fresh()->api_key);

        $this->patchJson('/api/admin/ai/providers/'.$provider->id, [
            'base_url' => 'https://override.example.test/v1', 'api_key' => 'override-private-key',
        ])->assertUnprocessable();
        $this->assertNull($provider->fresh()->base_url);
    }

    #[DataProvider('unsafeEndpoints')]
    public function test_unsafe_endpoints_are_rejected_without_storing_or_echoing_credentials(string $url): void
    {
        $secret = 'validation-private-key';
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/ai/providers', [
                'name' => 'Unsafe', 'protocol' => 'openai', 'base_url' => $url, 'api_key' => $secret,
            ])->assertUnprocessable()->assertJsonValidationErrors('base_url');

        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertDatabaseCount('ai_provider_profiles', 0);
        Http::assertNothingSent();
    }

    public static function unsafeEndpoints(): array
    {
        return [
            'plaintext' => ['http://api.example.test/v1'],
            'credentials' => ['https://user:pass@api.example.test/v1'],
            'query' => ['https://api.example.test/v1?token=private'],
            'fragment' => ['https://api.example.test/v1#private'],
            'loopback' => ['https://127.0.0.1/v1'],
            'private' => ['https://10.0.0.1/v1'],
            'link-local' => ['https://169.254.169.254/v1'],
            'shared-address-space' => ['https://100.64.0.1/v1'],
            'reserved' => ['https://192.0.2.1/v1'],
            'ipv6-loopback' => ['https://[::1]/v1'],
            'ipv6-private' => ['https://[fd00::1]/v1'],
            'mapped-loopback' => ['https://[::ffff:127.0.0.1]/v1'],
            'abbreviated-loopback' => ['https://127.1/v1'],
            'numeric-loopback' => ['https://2130706433/v1'],
            'localhost' => ['https://localhost/v1'],
            'local-domain' => ['https://metadata.internal/v1'],
            'control-character' => ["https://api.example.test/v1\r\nHeader: injected"],
            'encoded-control' => ['https://api.example.test/v1%0d%0aHeader'],
        ];
    }

    public function test_validation_errors_never_flash_the_api_key_even_for_non_json_requests(): void
    {
        $secret = 'invalid-request-private-key';
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->from('/admin/ai')->post('/api/admin/ai/providers', [
                'name' => '', 'protocol' => 'invalid',
                'base_url' => 'https://safe.example.test', 'api_key' => $secret,
            ])->assertUnprocessable();

        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertNull(session()->get('_old_input.api_key'));
        $this->assertDatabaseCount('ai_provider_profiles', 0);
    }

    public function test_check_uses_read_only_catalog_and_sanitizes_upstream_failure(): void
    {
        $provider = $this->provider('check', 'anthropic');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Http::fake([
            'https://check.example.test/v1/models*' => Http::sequence()
                ->push(['data' => [['id' => 'claude-fixture', 'display_name' => 'Claude fixture']], 'has_more' => false])
                ->push(['error' => ['message' => 'check-private-key at https://check.example.test/secret exploded']], 503),
        ]);

        $this->postJson('/api/admin/ai/providers/'.$provider->id.'/check')->assertOk()
            ->assertJsonPath('model_count', 1)->assertJsonPath('provider.status', 'healthy');
        $this->assertDatabaseCount('ai_model_profiles', 0);
        $response = $this->postJson('/api/admin/ai/providers/'.$provider->id.'/check')
            ->assertStatus(503)->assertJsonPath('provider.status', 'unavailable');
        $this->assertNotNull($provider->fresh()->last_checked_at);
        foreach (['check-private-key', '/secret', 'exploded'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $response->getContent());
            $this->assertStringNotContainsString($privateValue, $provider->fresh()->last_error);
            $this->assertStringNotContainsString($privateValue, DB::table('audit_events')->pluck('metadata')->implode(' '));
        }
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_dispatch_rejects_mixed_public_and_private_dns_answers_before_sending_credentials(): void
    {
        $provider = $this->provider('rebound');
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34', '127.0.0.1'];
            }
        });
        Http::fake(['*' => Http::response(['data' => []])]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/ai/providers/'.$provider->id.'/check')
            ->assertStatus(503)->assertJsonPath('provider.status', 'unavailable');
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_scoped_sync_keeps_shared_upstream_ids_and_curated_metadata_owned_by_their_provider(): void
    {
        config()->set(['services.ai_proxy.url' => 'https://legacy.example.test', 'services.ai_proxy.key' => 'legacy-private-key']);
        $legacy = AiProviderProfile::create(['slug' => 'ai-proxy', 'name' => 'Legacy']);
        $legacyModel = $this->model($legacy, 'shared-native', null);
        $alpha = $this->provider('alpha');
        $beta = $this->provider('beta', 'anthropic');
        $curated = $this->model($alpha, 'curated-public', 'shared-native');
        $curated->update([
            'display_name' => 'Curated name', 'provider_name' => 'Curated maker', 'category' => 'custom',
            'tier' => 'Premium', 'description_en' => 'Keep this description', 'capabilities' => ['curated'],
            'context_window' => 128000, 'badges' => ['Popular'], 'sort_order' => 9,
        ]);
        $missing = $this->model($alpha, 'missing-alpha', 'missing-native');
        $other = $this->model($beta, 'unseen-beta', 'beta-native');
        foreach (['input_tokens' => 1.25, 'output_tokens' => 3.75] as $meter => $price) {
            UsageRate::create([
                'service' => 'api', 'meter' => $meter, 'model' => $curated->model_id, 'label' => $meter,
                'unit' => '1M tokens', 'price_usd' => $price, 'price_idr' => $price * 16000, 'is_active' => true,
            ]);
        }
        Http::fake([
            'https://alpha.example.test/v1/models' => Http::response(['data' => [[
                'id' => 'shared-native', 'name' => 'Upstream name', 'category' => 'chat', 'tier' => 'Standard',
                'capabilities' => ['streaming'], 'context_length' => 999999,
            ]]]),
            'https://beta.example.test/v1/models*' => Http::response([
                'data' => [['id' => 'shared-native', 'display_name' => 'Native beta']], 'has_more' => false,
            ]),
            'https://legacy.example.test/v1/models' => Http::response(['data' => [['id' => 'shared-native', 'name' => 'Native legacy']]]),
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/admin/ai/providers/'.$alpha->id.'/sync')->assertOk();
        $this->assertFalse($missing->fresh()->is_available);
        $this->assertTrue($other->fresh()->is_available);
        $this->assertTrue($legacyModel->fresh()->is_available);
        $this->postJson('/api/admin/ai/providers/'.$beta->id.'/sync')->assertOk();
        $this->postJson('/api/admin/ai/providers/'.$legacy->id.'/sync')->assertOk();

        $curated->refresh();
        $this->assertSame($alpha->id, $curated->provider_id);
        $this->assertSame('Curated name', $curated->display_name);
        $this->assertSame('Curated maker', $curated->provider_name);
        $this->assertSame('custom', $curated->category);
        $this->assertSame('Premium', $curated->tier);
        $this->assertSame('Keep this description', $curated->description_en);
        $this->assertSame(['curated'], $curated->capabilities);
        $this->assertSame(128000, $curated->context_window);
        $this->assertSame(['Popular'], $curated->badges);
        $this->assertSame(9, $curated->sort_order);
        $this->assertTrue($curated->is_available);
        $this->assertTrue($curated->is_enabled);
        $this->assertSame($legacy->id, $legacyModel->fresh()->provider_id);
        $this->assertDatabaseHas('usage_rates', ['model' => 'curated-public', 'meter' => 'input_tokens', 'price_usd' => 1.25]);
        $betaModel = AiModelProfile::where('provider_id', $beta->id)->where('upstream_model_id', 'shared-native')->firstOrFail();
        $this->assertNotSame('shared-native', $betaModel->model_id);
        $this->assertStringStartsWith('beta/', $betaModel->model_id);
        $this->assertFalse($betaModel->is_enabled);
        $this->assertTrue($betaModel->is_available);
        $this->assertFalse($other->fresh()->is_available);
        $publicId = $betaModel->model_id;
        $this->postJson('/api/admin/ai/providers/'.$beta->id.'/sync')->assertOk();
        $this->assertSame($publicId, $betaModel->fresh()->model_id);
        $this->assertSame(1, AiModelProfile::where('provider_id', $beta->id)->where('upstream_model_id', 'shared-native')->count());
    }

    public function test_new_saved_models_are_unpublished_and_long_native_ids_have_stable_public_aliases(): void
    {
        $provider = $this->provider('long-models');
        $nativeId = str_repeat('m', 160);
        Http::fake(['https://long-models.example.test/v1/models' => Http::response(['data' => [
            ['id' => $nativeId, 'name' => 'Long native'], ['id' => 'short-native', 'name' => 'Short native'],
        ]])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/admin/ai/providers/'.$provider->id.'/sync')->assertOk()->assertJsonPath('synced_models', 2);
        $model = AiModelProfile::where('provider_id', $provider->id)->where('upstream_model_id', $nativeId)->firstOrFail();
        $this->assertLessThanOrEqual(120, strlen($model->model_id));
        $this->assertStringStartsWith('long-models/', $model->model_id);
        $this->assertFalse($model->is_enabled);
        $this->assertSame('Original', $model->tier);
        $this->assertSame(0, $provider->models()->where('is_enabled', true)->count());
        $publicId = $model->model_id;
        $this->postJson('/api/admin/ai/providers/'.$provider->id.'/sync')->assertOk();
        $this->assertSame($publicId, $model->fresh()->model_id);
        $this->assertDatabaseCount('ai_model_profiles', 2);
    }

    public function test_model_reassignment_requires_explicit_mapping_and_duplicate_identity_rolls_back(): void
    {
        $alpha = $this->provider('assign-alpha');
        $beta = $this->provider('assign-beta');
        $existing = $this->model($alpha, 'native-existing', null);
        $model = $this->model($beta, 'public-to-move', 'native-beta');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->patchJson('/api/admin/ai/models/'.$model->id, [
            'provider_slug' => $alpha->slug,
        ])->assertUnprocessable()->assertJsonValidationErrors('upstream_model_id');
        $this->patchJson('/api/admin/ai/models/'.$model->id, [
            'provider_slug' => $alpha->slug, 'upstream_model_id' => 'native-existing', 'display_name' => 'Must roll back',
        ])->assertUnprocessable()->assertJsonValidationErrors('upstream_model_id');
        $this->postJson('/api/admin/ai/models', [
            'model_id' => 'second-public-name', 'display_name' => 'Duplicate', 'category' => 'chat',
            'provider_slug' => $alpha->slug, 'upstream_model_id' => 'native-existing',
        ])->assertUnprocessable()->assertJsonValidationErrors('upstream_model_id');
        $this->assertSame($beta->id, $model->fresh()->provider_id);
        $this->assertSame('public-to-move', $model->fresh()->display_name);
        $this->assertTrue($model->fresh()->is_available);
        $this->assertDatabaseCount('ai_model_profiles', 2);
        $this->assertDatabaseCount('audit_events', 0);

        $this->patchJson('/api/admin/ai/models/'.$model->id, [
            'provider_slug' => $alpha->slug, 'upstream_model_id' => 'native-new',
        ])->assertOk()->assertJsonPath('model.provider_slug', $alpha->slug)
            ->assertJsonPath('model.upstream_model_id', 'native-new')->assertJsonPath('model.is_available', false);
        $this->assertSame('public-to-move', $model->fresh()->model_id);
        $this->assertSame($alpha->id, $model->fresh()->provider_id);
        $this->assertTrue($existing->fresh()->is_available);
        $audit = DB::table('audit_events')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('assign-alpha.example.test', $audit);
        $this->assertStringNotContainsString('assign-alpha-private-key', $audit);
    }

    public function test_catalog_results_from_a_rotated_connection_cannot_restore_stale_health_or_models(): void
    {
        $provider = $this->provider('stale-connection');
        $model = $this->model($provider, 'existing-public', 'existing-native');
        Http::fake(function () use ($provider) {
            AiProviderProfile::findOrFail($provider->id)->update([
                'api_key' => 'rotated-during-request', 'status' => 'unknown', 'last_checked_at' => null,
            ]);
            $provider->models()->update(['is_available' => false]);

            return Http::response(['data' => [['id' => 'stale-native', 'name' => 'Stale model']]]);
        });
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/admin/ai/providers/'.$provider->id.'/sync')->assertConflict();
        $this->assertSame('unknown', $provider->fresh()->status);
        $this->assertNull($provider->fresh()->last_checked_at);
        $this->assertFalse($model->fresh()->is_available);
        $this->assertDatabaseMissing('ai_model_profiles', ['upstream_model_id' => 'stale-native']);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_database_atomically_rejects_a_duplicate_of_a_legacy_effective_upstream_id(): void
    {
        $provider = $this->provider('unique-native');
        $this->model($provider, 'legacy-native', null);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('ai_model_profiles')->insert([
            'provider_id' => $provider->id, 'model_id' => 'different-public',
            'upstream_model_id' => 'legacy-native', 'display_name' => 'Duplicate native',
        ]);
    }

    #[DataProvider('collisionDiagnostics')]
    public function test_sync_recovers_from_public_id_collisions_without_refetching_or_losing_models(bool $translatedDiagnostics): void
    {
        $first = $this->provider('first-owner');
        $second = $this->provider('second-owner');
        $this->model($first, 'shared-new-native', null);
        $this->model($first, 'second-owner/shared-new-native', 'another-native');
        Http::fake(['https://second-owner.example.test/v1/models' => Http::response(['data' => [
            ['id' => 'shared-new-native', 'name' => 'Shared model'],
            ['id' => 'other-new-native', 'name' => 'Other model'],
        ]])]);

        if ($translatedDiagnostics) {
            AiModelProfile::creating(function (AiModelProfile $model): void {
                if (in_array($model->model_id, ['shared-new-native', 'second-owner/shared-new-native'], true)) {
                    throw $this->postgresUniqueViolation('ERROR: nilai kunci ganda melanggar batasan unik « ai_model_profiles_model_id_unique »');
                }
            });
        }
        try {
            $this->actingAs(User::factory()->create(['role' => 'admin']))
                ->postJson('/api/admin/ai/providers/'.$second->id.'/sync')
                ->assertOk()->assertJsonPath('synced_models', 2);
        } finally {
            if ($translatedDiagnostics) {
                AiModelProfile::flushEventListeners();
            }
        }

        $this->assertSame(2, $second->models()->count());
        $this->assertSame(2, $first->models()->count());
        $this->assertDatabaseHas('ai_model_profiles', ['provider_id' => $second->id, 'upstream_model_id' => 'shared-new-native', 'is_available' => true]);
        $this->assertDatabaseHas('ai_model_profiles', ['provider_id' => $first->id, 'model_id' => 'shared-new-native']);
        Http::assertSentCount(1);
    }

    public static function collisionDiagnostics(): array
    {
        return ['database diagnostics' => [false], 'Indonesian PostgreSQL diagnostics' => [true]];
    }

    #[DataProvider('modelConstraintFailures')]
    public function test_model_constraint_failures_preserve_validation_and_rethrow_unknown_errors(bool $sync, string $diagnostic, ?string $field): void
    {
        $provider = $this->provider('constraint-errors');
        Http::fake(['https://constraint-errors.example.test/v1/models' => Http::response(['data' => [
            ['id' => 'new-native', 'name' => 'New model'],
        ]])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $exception = $this->postgresUniqueViolation($diagnostic);
        AiModelProfile::creating(function () use ($exception): void {
            throw $exception;
        });
        try {
            if ($field === null) {
                $this->withoutExceptionHandling();
                $this->expectExceptionObject($exception);
            }
            $response = $sync
                ? $this->postJson('/api/admin/ai/providers/'.$provider->id.'/sync')
                : $this->postJson('/api/admin/ai/models', [
                    'model_id' => 'new-public', 'upstream_model_id' => 'new-native',
                    'provider_slug' => $provider->slug, 'display_name' => 'New model', 'category' => 'chat',
                ]);
            $response->assertUnprocessable()->assertJsonValidationErrors($field);
        } finally {
            AiModelProfile::flushEventListeners();
        }
        $this->assertDatabaseCount('ai_model_profiles', 0);
        $this->assertDatabaseCount('audit_events', 0);
        Http::assertSentCount($sync ? 1 : 0);
    }

    public static function modelConstraintFailures(): array
    {
        $upstream = 'ERROR: nilai kunci ganda melanggar batasan unik « ai_models_provider_upstream_unique »';
        $publicId = 'ERROR: nilai kunci ganda melanggar batasan unik « ai_model_profiles_model_id_unique »';
        $unknown = 'ERROR: nilai kunci ganda melanggar batasan unik « ai_model_profiles_display_name_unique »'
            ."\nDETAIL: Kunci (display_name)=(« ai_model_profiles_model_id_unique ») sudah ada.";

        return [
            'sync upstream identity' => [true, $upstream, 'upstream_model_id'],
            'save upstream identity' => [false, $upstream, 'upstream_model_id'],
            'save public ID' => [false, $publicId, 'model_id'],
            'sync unknown constraint with known name in detail' => [true, $unknown, null],
            'save unknown constraint with known name in detail' => [false, $unknown, null],
            'sync unknown constraint with known name as prefix' => [true, 'ERROR: nilai kunci ganda melanggar batasan unik « ai_model_profiles_model_id_unique-legacy »', null],
        ];
    }

    private function postgresUniqueViolation(string $diagnostic): UniqueConstraintViolationException
    {
        $previous = new PDOException('SQLSTATE[23505]: '.$diagnostic);
        $previous->errorInfo = ['23505', 7, $diagnostic];

        return new UniqueConstraintViolationException('pgsql', 'insert into ai_model_profiles (display_name) values (?)', ['"ai_models_provider_upstream_unique"'], $previous);
    }

    private function provider(string $slug, string $protocol = 'openai'): AiProviderProfile
    {
        return AiProviderProfile::create([
            'slug' => $slug, 'name' => ucfirst($slug), 'protocol' => $protocol,
            'base_url' => 'https://'.$slug.'.example.test/v1', 'api_key' => $slug.'-private-key',
            'is_enabled' => true, 'status' => 'healthy', 'last_checked_at' => now(),
        ]);
    }

    private function model(AiProviderProfile $provider, string $publicId, ?string $upstreamId): AiModelProfile
    {
        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => $publicId, 'upstream_model_id' => $upstreamId,
            'display_name' => $publicId, 'category' => 'chat', 'tier' => 'Original',
            'is_enabled' => true, 'is_available' => true,
        ]);
    }
}
