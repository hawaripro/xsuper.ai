<?php

namespace Tests\Feature\Pricing;

use App\Media\FalCapabilityImporter;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\DurationPackagePrice;
use App\Models\MediaCapabilityRevision;
use App\Models\ModelCost;
use App\Models\TokenPackage;
use App\Models\UsageRate;
use App\Models\User;
use App\Services\Pricing\PricingApplier;
use App\Services\WorkspaceMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FalCatalogFixture;
use Tests\TestCase;

class PricingApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_prices_real_costs_deactivates_unknowns_skips_locks_and_keeps_reviewed_operations_sellable(): void
    {
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create();
        TokenPackage::query()->update(['is_active' => false]);
        TokenPackage::create(['code' => 'pricing-floor', 'name' => 'Pricing floor', 'base_tokens' => 4500, 'bonus_tokens' => 0, 'price_idr' => 399000, 'is_active' => true, 'sort_order' => 1]);
        $provider = AiProviderProfile::create(['name' => 'Fal', 'slug' => 'fal-price', 'protocol' => 'fal', 'base_url' => 'https://fal.run', 'is_enabled' => true, 'status' => 'healthy', 'authenticated_at' => now(), 'cost_currency' => 'usd', 'cost_idr_per_unit' => 19000]);
        $model = $this->model($provider, 'chat-priced', 'chat');
        ModelCost::create(['ai_model_profile_id' => $model->id, 'source' => 'reference', 'currency' => 'usd', 'status' => 'estimate', 'input_per_million' => 3, 'output_per_million' => 15, 'cache_read_per_million' => 0.3]);
        $unknown = $this->model($provider, 'chat-unknown', 'chat');
        $locked = $this->model($provider, 'chat-locked', 'chat');
        ModelCost::create(['ai_model_profile_id' => $locked->id, 'source' => 'manual', 'currency' => 'usd', 'status' => 'ok', 'input_per_million' => 3, 'output_per_million' => 15, 'price_locked' => true]);
        foreach ([$unknown, $locked] as $entry) {
            foreach (['input_tokens', 'output_tokens', 'cache_read'] as $meter) {
                UsageRate::create(['service' => 'api', 'meter' => $meter, 'model' => $entry->model_id, 'label' => 'Old rate', 'unit' => '1M tokens', 'price_usd' => 1, 'price_idr' => 16000, 'is_active' => true]);
            }
        }
        $media = $this->model($provider, 'fal-ai/priced-fixture', 'image');
        $entry = FalCatalogFixture::model($media->model_id);
        $normalized = app(FalCapabilityImporter::class)->normalize([...$entry, 'model_public_id' => $media->model_id], 2);
        $revision = MediaCapabilityRevision::create(['ai_model_profile_id' => $media->id, 'operation' => $normalized['operation'], 'contract_version' => 2,
            'revision' => 1, 'status' => 'published', 'source_schema' => $entry['openapi'], 'source_hash' => FalCapabilityImporter::hash($entry['openapi']),
            'definition' => $normalized['capability']->toArray(), 'provider_bindings' => $normalized['provider_bindings'], 'compatibility_report' => $normalized['report'],
            'reviewed_at' => now(), 'reviewed_by' => $admin->id, 'published_at' => now(),
            'curation_overrides' => ['pricing' => ['token_cost' => 50, 'unit' => 'request', 'variable_configuration' => true, 'reviewed_by' => $admin->id, 'reviewed_at' => now()->toISOString()]]]);
        ModelCost::create(['ai_model_profile_id' => $media->id, 'source' => 'fal_api', 'currency' => 'usd', 'status' => 'ok', 'unit' => 'request', 'unit_cost' => 0.025]);
        $kinovi = AiProviderProfile::create(['name' => 'Kinovi', 'slug' => 'kinovi-price', 'protocol' => 'kinovi', 'cost_currency' => 'credit', 'cost_idr_per_unit' => 85.814]);
        $music = $this->model($kinovi, 'suno-music', 'audio');
        ModelCost::create(['ai_model_profile_id' => $music->id, 'source' => 'kinovi_docs', 'currency' => 'credit', 'status' => 'ok', 'unit' => 'request', 'unit_cost' => 16]);
        $run = app(PricingApplier::class)->apply($admin);
        $rates = UsageRate::activeForModel('api', $model->model_id);
        $this->assertEquals(6.65, $rates['input_tokens']->price_usd);
        $this->assertEquals(33.21, $rates['output_tokens']->price_usd);
        $this->assertEquals(0.67, $rates['cache_read']->price_usd);
        $this->assertEquals(6.65 * 16000, $rates['input_tokens']->price_idr);
        $this->assertSame(0, UsageRate::activeForModel('api', $unknown->model_id)->count());
        $this->assertEquals(1, UsageRate::activeForModel('api', $locked->model_id)['input_tokens']->price_usd);
        $this->assertSame(10, $media->fresh()->token_cost);
        $this->assertSame(10, $revision->fresh()->curation_overrides['pricing']['token_cost']);
        $this->assertTrue($revision->fresh()->hasReviewedPrice(10));
        $this->assertContains($media->model_id, array_column(app(WorkspaceMediaService::class)->models($member)['models'], 'model_id'));
        $this->assertSame(30, $music->fresh()->token_cost);
        $this->assertEquals(3.44, DurationPackagePrice::where('package', '1_month')->value('price_usd'));
        $this->assertDatabaseHas('pricing_runs', ['id' => $run->id, 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'pricing.auto.applied', 'subject_id' => $run->id]);
        $second = app(PricingApplier::class)->apply($admin);
        $this->assertSame(0, $second->summary['rates_written']);
        $this->assertSame(0, $second->summary['media_written']);
    }

    private function model(AiProviderProfile $provider, string $id, string $category): AiModelProfile
    {
        return AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => $id, 'upstream_model_id' => $id, 'display_name' => $id, 'category' => $category, 'is_enabled' => true, 'is_available' => true, 'token_cost' => $category === 'image' ? 50 : null]);
    }
}
