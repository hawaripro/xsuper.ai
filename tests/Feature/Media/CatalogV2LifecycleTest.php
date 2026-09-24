<?php

namespace Tests\Feature\Media;

use App\Media\CapabilityResolver;
use App\Media\FalCapabilityImporter;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use App\Services\MediaCatalogService;
use App\Services\MediaModelConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FalCatalogFixture;
use Tests\TestCase;

class CatalogV2LifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_normalization_creates_a_versioned_candidate_without_changing_published_history(): void
    {
        [$admin, $provider] = $this->connection();
        [$model, $old] = $this->stored($provider, FalCatalogFixture::model(), 1);
        $old->update(['status' => 'published', 'published_at' => now(), 'reviewed_at' => now()]);
        $model->update(['display_name' => 'Curated', 'token_cost' => 47, 'is_enabled' => true]);
        $definition = $old->definition;
        Http::preventStrayRequests();

        $catalog = app(MediaCatalogService::class);
        $first = $catalog->renormalize($model, $admin);
        $repeat = $catalog->renormalize($model, $admin);

        $this->assertTrue($first['created']);
        $this->assertFalse($repeat['created']);
        $this->assertSame($first['revision_id'], $repeat['revision_id']);
        $this->assertSame(2, MediaCapabilityRevision::findOrFail($first['revision_id'])->contract_version);
        $this->assertSame($definition, $old->fresh()->definition);
        $this->assertSame('published', $old->fresh()->status);
        $this->assertSame(47, $model->fresh()->token_cost);
        $this->assertSame('Curated', $model->fresh()->display_name);
        Http::assertNothingSent();
    }

    public function test_selected_non_image_contract_can_be_explicitly_priced_reviewed_and_published(): void
    {
        [$admin, $provider] = $this->connection();
        $entry = FalCatalogFixture::model('fal-ai/audio-fixture');
        $entry['metadata']['category'] = 'text-to-audio';
        $entry['openapi']['components']['schemas']['Output'] = ['type' => 'object', 'properties' => [
            'audio' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'format' => 'uri']]],
        ]];
        [$model, $revision] = $this->stored($provider, $entry);

        $this->actingAs($admin)->postJson('/api/admin/ai/providers/'.$provider->id.'/catalog-bulk', [
            'items' => [['model_id' => $model->id, 'revision_id' => $revision->id, 'token_cost' => 19, 'price_unit' => 'request']],
            'expected_count' => 1, 'action' => 'publish', 'reviewed' => true, 'confirm' => true,
        ])->assertOk()->assertJsonPath('items.0.status', 'published');

        $model = $model->fresh('provider');
        $this->assertSame([$revision->operation], MediaModelConfig::workspaceOperations($admin, $model));
        $this->assertSame('request', MediaModelConfig::forOperation($model, $revision->operation)['price_unit']);
        // Fixed-form studios never receive a schema contract, even after publication.
        $this->assertFalse(MediaModelConfig::allowedFor($admin, $model));
        $this->assertNotContains($model->model_id, array_column($this->getJson('/api/audio/models')->assertOk()->json('models'), 'id'));
        $this->assertDatabaseHas('audit_events', ['action' => 'ai_catalog.bulk_published', 'actor_id' => $admin->id]);
    }

    public function test_bulk_price_rejects_existing_positive_tariff_atomically(): void
    {
        [$admin, $provider] = $this->connection();
        [$fresh, $candidate] = $this->stored($provider, FalCatalogFixture::model('fal-ai/new-fixture'));
        [$priced, $existing] = $this->stored($provider, FalCatalogFixture::model('fal-ai/priced-fixture'));
        $priced->update(['token_cost' => 47]);
        $this->actingAs($admin)->postJson('/api/admin/ai/providers/'.$provider->id.'/catalog-bulk', [
            'items' => [
                ['model_id' => $fresh->id, 'revision_id' => $candidate->id, 'token_cost' => 15, 'price_unit' => 'request'],
                ['model_id' => $priced->id, 'revision_id' => $existing->id, 'token_cost' => 15, 'price_unit' => 'request'],
            ],
            'expected_count' => 2, 'action' => 'publish', 'reviewed' => true, 'confirm' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.token_cost');
        $this->assertNull($fresh->fresh()->token_cost);
        $this->assertFalse($fresh->fresh()->is_enabled);
        $this->assertSame(47, $priced->fresh()->token_cost);
        $this->assertNull($candidate->fresh()->reviewed_at);
    }

    public function test_tariff_drift_hides_a_published_schema_contract_until_its_price_is_re_reviewed(): void
    {
        [$admin, $provider] = $this->connection();
        [$model, $revision] = $this->stored($provider, FalCatalogFixture::model('fal-ai/drift-fixture'));
        $this->actingAs($admin)->postJson('/api/admin/ai/providers/'.$provider->id.'/catalog-bulk', [
            'items' => [['model_id' => $model->id, 'revision_id' => $revision->id, 'token_cost' => 20, 'price_unit' => 'request']],
            'expected_count' => 1, 'action' => 'publish', 'reviewed' => true, 'confirm' => true,
        ])->assertOk();
        $model->update(['token_cost' => 25]);
        $this->assertSame([], MediaModelConfig::workspaceOperations($admin, $model->fresh('provider')));

        $this->postJson('/api/admin/ai/capabilities/'.$revision->id.'/review', ['reviewed' => true, 'price_review' => [
            'token_cost' => 25, 'unit' => 'request', 'variable_configuration' => true,
        ]])->assertOk()->assertJsonPath('revision.status', 'published');

        $this->assertSame([$revision->operation], MediaModelConfig::workspaceOperations($admin, $model->fresh('provider')));
        $this->assertSame(25, $model->fresh()->token_cost);
        $this->assertSame($revision->definition, $revision->fresh()->definition);
    }

    public function test_existing_positive_tariff_and_unit_survive_v2_upgrade_and_v1_rollback(): void
    {
        [$admin, $provider] = $this->connection();
        [$model, $legacy] = $this->stored($provider, FalCatalogFixture::model(), 1);
        $legacy->update(['status' => 'published', 'published_at' => now(), 'reviewed_at' => now()]);
        $model->update(['token_cost' => 47, 'is_enabled' => true]);
        $definition = $legacy->definition;
        $candidate = app(MediaCatalogService::class)->renormalize($model, $admin);
        $url = '/api/admin/ai/capabilities/'.$candidate['revision_id'].'/publish';
        $this->actingAs($admin)->postJson($url, ['reviewed' => true, 'price_review' => [
            'token_cost' => 48, 'unit' => 'generation', 'variable_configuration' => true,
        ]])->assertUnprocessable();
        $this->postJson($url, ['reviewed' => true, 'price_review' => [
            'token_cost' => 47, 'unit' => 'generation', 'variable_configuration' => true,
        ]])->assertOk();
        $this->assertSame(47, $model->fresh()->token_cost);
        $this->assertSame('generation', MediaModelConfig::forOperation($model->fresh('provider'), $candidate['operation'])['price_unit']);
        $this->assertFalse(MediaModelConfig::hasCatalogImage($model->fresh('provider')));
        $this->assertFalse(MediaModelConfig::allowedFor($admin, $model->fresh('provider')));
        $this->postJson('/api/admin/ai/capabilities/'.$legacy->id.'/rollback')->assertOk();
        $resolved = app(CapabilityResolver::class)->resolve($model->fresh('provider'), \App\Media\Enums\MediaOperation::from($legacy->operation));
        $this->assertSame($legacy->id, $resolved->revisionId);
        $this->assertSame($definition, $legacy->fresh()->definition);
        $this->assertSame(47, $model->fresh()->token_cost);
        $this->assertTrue(MediaModelConfig::allowedFor($admin, $model->fresh('provider')));
    }

    public function test_realtime_candidate_requires_its_session_bound_and_stays_out_of_fixed_forms(): void
    {
        [$admin, $provider] = $this->connection();
        $model = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'minimax/h3-max/director', 'upstream_model_id' => 'minimax/h3-max/director',
            'display_name' => 'Director', 'category' => 'video', 'is_available' => true, 'is_enabled' => false, 'token_cost' => null,
        ]);
        $candidate = app(MediaCatalogService::class)->renormalize($model, $admin);
        $this->assertSame('realtime_video', $candidate['operation']);
        $this->assertTrue($candidate['source_recovered']);
        $this->assertSame([], $candidate['blockers']);
        $bound = MediaCapabilityRevision::findOrFail($candidate['revision_id'])->executionMetadata()['max_session_seconds'];
        $url = '/api/admin/ai/providers/'.$provider->id.'/catalog-bulk';
        $item = ['model_id' => $model->id, 'revision_id' => $candidate['revision_id'], 'token_cost' => 90, 'price_unit' => 'request'];
        $body = fn (array $item): array => ['items' => [$item], 'expected_count' => 1, 'action' => 'publish', 'reviewed' => true, 'confirm' => true];

        $this->actingAs($admin)->postJson($url, $body($item))->assertUnprocessable()->assertJsonValidationErrors('items.0.revision_id');
        $this->assertNull($model->fresh()->token_cost);
        $this->postJson($url, $body([...$item, 'max_session_seconds' => $bound]))->assertOk()->assertJsonPath('items.0.status', 'published');

        $model = $model->fresh('provider');
        $this->assertSame($bound, MediaCapabilityRevision::findOrFail($candidate['revision_id'])->curation_overrides['pricing']['max_session_seconds']);
        $this->assertSame(['realtime_video'], MediaModelConfig::workspaceOperations($admin, $model));
        $this->assertFalse(MediaModelConfig::allowedFor($admin, $model));
    }

    public function test_source_coverage_reports_missing_schema_without_network_or_activation(): void
    {
        [$admin, $provider] = $this->connection();
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'missing-source', 'display_name' => 'Missing', 'category' => 'other', 'is_enabled' => false]);
        Http::preventStrayRequests();
        $report = app(MediaCatalogService::class)->renormalize($model, $admin);
        $this->assertSame('missing_source_schema', $report['blockers'][0]['code']);
        $this->assertSame($model->id, $report['model_id']);
        $this->assertDatabaseCount('media_capabilities', 0);
        $this->assertFalse($model->fresh()->is_enabled);
        Http::assertNothingSent();
    }

    private function connection(): array
    {
        return [User::factory()->create(['role' => 'admin']), AiProviderProfile::create([
            'slug' => 'fal-v2', 'name' => 'Fal', 'protocol' => 'fal', 'base_url' => 'https://fal.run',
            'is_enabled' => true, 'status' => 'healthy', 'authenticated_at' => now(),
        ])];
    }

    private function stored(AiProviderProfile $provider, array $entry, int $version = 2): array
    {
        $model = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => $entry['endpoint_id'], 'upstream_model_id' => $entry['endpoint_id'],
            'display_name' => 'Source fixture', 'category' => MediaModelConfig::catalogCategory($entry['metadata']['category']),
            'is_available' => true, 'is_enabled' => false, 'token_cost' => null,
        ]);
        $normalized = app(FalCapabilityImporter::class)->normalize([...$entry, 'model_public_id' => $model->model_id], $version);
        $revision = MediaCapabilityRevision::create([
            'ai_model_profile_id' => $model->id, 'operation' => $normalized['operation'], 'contract_version' => $version,
            'revision' => 1, 'status' => 'imported', 'source_schema' => $entry['openapi'],
            'source_metadata' => ['endpoint_id' => $entry['endpoint_id'], 'metadata' => $entry['metadata']],
            'source_hash' => FalCapabilityImporter::hash($entry['openapi']), 'definition' => $normalized['capability']->toArray(),
            'provider_bindings' => $normalized['provider_bindings'], 'compatibility_report' => $normalized['report'],
        ]);

        return [$model, $revision];
    }
}
