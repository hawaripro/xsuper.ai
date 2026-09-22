<?php

namespace Tests\Feature\Media;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use App\Services\AiProviderEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediaCatalogQueriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_filters_sorting_and_pagination_are_applied_before_reading_rows(): void
    {
        $provider = AiProviderProfile::create(['slug' => 'catalog-pages', 'name' => 'Catalog', 'protocol' => 'openai']);
        foreach ([['a', 'Catalog Alpha', 'image', null], ['z', 'Catalog Zeta', 'image', null], ['chat', 'Catalog Chat', 'chat', null], ['priced', 'Catalog Priced', 'image', 12]] as [$id, $label, $category, $price]) {
            AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => $id, 'upstream_model_id' => 'upstream-'.$id, 'display_name' => $label, 'category' => $category, 'token_cost' => $price]);
        }
        $other = AiProviderProfile::create(['slug' => 'other-pages', 'name' => 'Other']);
        AiModelProfile::create(['provider_id' => $other->id, 'model_id' => 'other-a', 'display_name' => 'Catalog Other', 'category' => 'image']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/ai/providers/'.$provider->id.'/models?category=image&status=unpriced&q=CATALOG&sort=display_name&direction=desc&per_page=1&page=2')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('models.0.model_id', 'a')->assertJsonCount(1, 'models')
            ->assertJsonMissingPath('models.0.source_schema');
        $summary = $this->getJson('/api/admin/ai/catalog/summary')->assertOk()->assertJsonMissingPath('models');
        $row = collect($summary->json('providers'))->firstWhere('id', $provider->id);
        $this->assertSame(4, $row['model_counts']['total']);
        $this->assertSame(2, $row['model_counts']['unpriced']);
        $this->assertSame(0, $row['counts']['published']);
    }

    public function test_static_kinovi_discovery_never_claims_authenticated_health_and_effective_config_is_readonly(): void
    {
        Http::preventStrayRequests();
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $provider = AiProviderProfile::create([
            'slug' => 'kinovi-static', 'name' => 'Kinovi', 'protocol' => 'kinovi', 'is_enabled' => true,
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'not-a-verified-key',
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'public-kinovi', 'upstream_model_id' => 'gpt-image-2',
            'display_name' => 'Curated image', 'category' => 'image', 'generation_config' => ['sizes' => ['ignored-size']],
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/ai/providers/'.$provider->id.'/check')->assertOk()
            ->assertJsonPath('provider.status', 'discovered')->assertJsonPath('provider.verification.authenticated', false)
            ->assertJsonPath('provider.verification.catalog_source', 'static_documentation');
        $response = $this->getJson('/api/admin/ai/providers/'.$provider->id.'/models')->assertOk()
            ->assertJsonPath('models.0.generation_config_readonly', true);
        $this->assertNotContains('ignored-size', $response->json('models.0.generation_config.sizes'));
        $this->assertStringNotContainsString('not-a-verified-key', $response->getContent());
        Http::assertNothingSent();
    }
}
