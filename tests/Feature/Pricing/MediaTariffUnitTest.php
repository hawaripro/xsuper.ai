<?php

namespace Tests\Feature\Pricing;

use App\Media\CapabilityResolver;
use App\Media\Enums\MediaOperation;
use App\Media\FalCapabilityImporter;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Models\ModelCost;
use App\Models\TokenPackage;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Services\FalProtocol;
use App\Services\Pricing\PricingApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\FalCatalogFixture;
use Tests\TestCase;

/**
 * A media tariff is charged in the unit it was applied in. Spec §2 defaults: m = 40 %, b = 10 %, f = 1 %,
 * L = 19000 IDR per USD and r = 399000 / 4500 IDR per token, so the media divisor is r × 0.59 = 52.3133.
 */
class MediaTariffUnitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        TokenPackage::query()->update(['is_active' => false]);
        TokenPackage::create(['code' => 'pricing-floor', 'name' => 'Pricing floor', 'base_tokens' => 4500, 'bonus_tokens' => 0, 'price_idr' => 399000, 'is_active' => true, 'sort_order' => 1]);
    }

    public function test_upgraded_video_tariffs_keep_per_generation_billing_while_locked_or_unknown(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $fal = $this->provider('fal');
        $kinovi = $this->provider('kinovi');
        $longcat = $this->model($fal, FalProtocol::VIDEO, 'video', 80);
        $unpriced = $this->model($fal, FalProtocol::VIDEO_REFERENCE, 'video', null);
        $image = $this->model($fal, FalProtocol::IMAGE_DEV, 'image', 15);
        $seedance = $this->model($kinovi, 'seedance-20', 'video', 80);
        $kinoviImage = $this->model($kinovi, 'gpt-image-2', 'image', 30);

        // Re-run the upgrade over rows that predate the persisted tariff unit.
        $migration = require database_path('migrations/2026_09_26_000100_add_token_cost_unit_to_ai_model_profiles.php');
        $migration->down();
        $migration->up();
        $this->assertSame(['generation', null, null, 'generation', null],
            array_map(fn (AiModelProfile $model): ?string => $model->fresh()->token_cost_unit, [$longcat, $unpriced, $image, $seedance, $kinoviImage]));

        ModelCost::create(['ai_model_profile_id' => $longcat->id, 'source' => 'manual', 'currency' => 'usd', 'status' => 'ok', 'unit' => 'second', 'unit_cost' => 0.075, 'price_locked' => true]);
        $summary = app(PricingApplier::class)->apply($admin)->summary;
        $this->assertSame([1, 0], [$summary['locked'], $summary['media_written']]);
        foreach ([$longcat, $seedance] as $model) {
            $this->assertSame([80, 'generation'], [$model->fresh()->token_cost, $model->fresh()->token_cost_unit]);
        }

        // A 5-second Standard video quotes and reserves the retained 80 tokens, never 80 × 5.
        $member = $this->member(1000);
        $this->actingAs($member)->getJson('/api/media/workspace/capabilities?model='.urlencode($longcat->model_id))->assertOk()
            ->assertJsonPath('capabilities.text_to_video.price_tokens', 80)
            ->assertJsonPath('capabilities.text_to_video.billing.price_unit', 'generation')
            ->assertJsonPath('capabilities.text_to_video.billing.duration_field', null);
        $this->postJson('/api/media/workspace/jobs', [
            'model' => $longcat->model_id, 'operation' => 'text_to_video', 'count' => 1, 'pro' => false,
            'inputs' => ['prompt' => 'A quiet garden', 'aspect_ratio' => '16:9', 'duration' => 5],
            'expected_capability_hash' => $this->hash($longcat, MediaOperation::TextToVideo),
            'expected_price_tokens' => 80, 'idempotency_key' => 'retained-longcat',
        ])->assertStatus(202);
        $this->assertSame(80, (int) VideoJob::query()->where('model', $longcat->model_id)->sole()->tokens_reserved);
        $this->postJson('/api/v/gen', ['operation' => 'text_to_video', 'model' => $seedance->model_id, 'prompt' => 'A garden',
            'aspect_ratio' => '16:9', 'duration' => 5, 'expected_price_tokens' => 80])->assertAccepted()->assertJsonPath('tokens_used', 80);
        $this->assertSame(840, UserToken::getBalance($member->id));
        $this->assertSame([$longcat->model_id => 'generation', $seedance->model_id => 'generation'],
            collect($this->getJson('/api/v/models')->assertOk()->json('models'))->pluck('price_unit', 'id')->all());
    }

    public function test_a_known_cost_converts_a_retained_video_tariff_to_per_second_in_one_apply(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $longcat = $this->model($this->provider('fal'), FalProtocol::VIDEO, 'video', 80, 'generation');
        // $0.075 per second: ceil(0.075 × 19000 × 1.1 / 52.3133) = 30 tokens per second.
        ModelCost::create(['ai_model_profile_id' => $longcat->id, 'source' => 'fal_api', 'currency' => 'usd', 'status' => 'ok', 'unit' => 'second', 'unit_cost' => 0.075]);

        $this->actingAs($admin)->getJson('/api/admin/pricing/auto/preview?kind=media')->assertOk()
            ->assertJsonPath('data.0.current_price', ['token_cost' => 80, 'unit' => 'generation'])
            ->assertJsonPath('data.0.new_price', ['token_cost' => 30, 'unit' => 'second'])
            ->assertJsonPath('data.0.price_unit', 'second')
            ->assertJsonPath('data.0.error', null);
        $this->postJson('/api/admin/pricing/auto/apply', ['confirm' => true])->assertOk()->assertJsonPath('summary.media_written', 1);
        $this->assertSame([30, 'second'], [$longcat->fresh()->token_cost, $longcat->fresh()->token_cost_unit]);
        $this->postJson('/api/admin/pricing/auto/apply', ['confirm' => true])->assertOk()->assertJsonPath('summary.media_written', 0);

        // 30 tokens/second × 5 seconds × Pro 2 = 300 tokens.
        $this->actingAs($this->member(1000))->postJson('/api/v/gen', ['operation' => 'text_to_video', 'model' => $longcat->model_id,
            'prompt' => 'A garden', 'aspect_ratio' => '16:9', 'duration' => 5, 'pro' => true, 'expected_price_tokens' => 300])
            ->assertAccepted()->assertJsonPath('tokens_used', 300)->assertJsonPath('balance', 700);
    }

    public function test_a_per_image_cost_prices_every_image_one_request_tariff_can_return(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $fal = $this->provider('fal');
        $schema = $this->model($fal, 'fal-ai/priced-fixture', 'image', 50);
        $revision = $this->publishedSchema($schema, $admin);
        $native = $this->model($fal, FalProtocol::IMAGE_DEV, 'image', null);
        Http::fake(['*api.fal.ai*' => Http::response(['prices' => [
            ['endpoint_id' => 'fal-ai/priced-fixture', 'unit_price' => 0.025, 'unit' => 'image', 'currency' => 'USD'],
            ['endpoint_id' => FalProtocol::IMAGE_DEV, 'unit_price' => 0.025, 'unit' => 'image', 'currency' => 'USD'],
        ]])]);

        $this->actingAs($admin)->postJson('/api/admin/pricing/auto/refresh', ['provider_id' => $fal->id])->assertOk();
        // The request tariff is charged once while num_images allows 4 images: 4 × $0.025. Native execution bills each image.
        $this->assertSame(['request', 0.1], [$schema->cost()->first()->unit, (float) $schema->cost()->first()->unit_cost]);
        $this->assertSame(['generation', 0.025], [$native->cost()->first()->unit, (float) $native->cost()->first()->unit_cost]);
        $this->postJson('/api/admin/pricing/auto/apply', ['confirm' => true])->assertOk();

        // $0.025 → 10 tokens (spec §2); $0.10 → ceil(0.1 × 19000 × 1.1 / 52.3133) = 40 tokens.
        $this->assertSame([40, 'request'], [$schema->fresh()->token_cost, $schema->fresh()->token_cost_unit]);
        $this->assertSame(40, $revision->fresh()->curation_overrides['pricing']['token_cost']);
        $this->assertSame([10, 'generation'], [$native->fresh()->token_cost, $native->fresh()->token_cost_unit]);
    }

    public function test_an_unpublished_schema_candidate_never_multiplies_a_native_per_image_price(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $fal = $this->provider('fal');
        $native = $this->model($fal, FalProtocol::IMAGE_DEV, 'image', null);
        // Catalog discovery attaches an unreviewed v2 candidate (num_images ≤ 4) to the native model.
        $this->importedCandidate($native);
        Http::fake(['*api.fal.ai*' => Http::response(['prices' => [
            ['endpoint_id' => FalProtocol::IMAGE_DEV, 'unit_price' => 0.025, 'unit' => 'image', 'currency' => 'USD'],
        ]])]);

        $this->actingAs($admin)->postJson('/api/admin/pricing/auto/refresh', ['provider_id' => $fal->id])->assertOk();
        // Native execution still charges each image, so the candidate's four-image maximum must not scale the cost.
        $this->assertSame(['generation', 0.025], [$native->cost()->first()->unit, (float) $native->cost()->first()->unit_cost]);
        $this->postJson('/api/admin/pricing/auto/apply', ['confirm' => true])->assertOk();
        $this->assertSame([10, 'generation'], [$native->fresh()->token_cost, $native->fresh()->token_cost_unit]);
    }

    public function test_an_unpublished_schema_candidate_never_widens_a_native_megapixel_bound(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $fal = $this->provider('fal');
        $native = $this->model($fal, FalProtocol::IMAGE_DEV, 'image', null);
        // The discovered schema accepts custom sizes up to 14142 × 14142 (200 MP); native execution offers up to 1792 × 1024.
        $this->importedCandidate($native, ['type' => 'object', 'properties' => ['width' => ['type' => 'integer', 'maximum' => 14142], 'height' => ['type' => 'integer', 'maximum' => 14142]]]);
        Http::fake(['*api.fal.ai*' => Http::response(['prices' => [
            ['endpoint_id' => FalProtocol::IMAGE_DEV, 'unit_price' => 0.025, 'unit' => 'megapixels', 'currency' => 'USD'],
        ]])]);

        $this->actingAs($admin)->postJson('/api/admin/pricing/auto/refresh', ['provider_id' => $fal->id])->assertOk();
        // ceil(1.835 MP) = 2 MP × $0.025, not 200 MP × $0.025 = $5.
        $this->assertSame(['generation', 0.05], [$native->cost()->first()->unit, (float) $native->cost()->first()->unit_cost]);
        $this->postJson('/api/admin/pricing/auto/apply', ['confirm' => true])->assertOk();
        // ceil(0.05 × 19000 × 1.1 / 52.3133) = 20 tokens per image.
        $this->assertSame([20, 'generation'], [$native->fresh()->token_cost, $native->fresh()->token_cost_unit]);
    }

    public function test_a_native_image_price_keeps_following_settings_after_its_first_application(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $fal = $this->provider('fal');
        $image = $this->model($fal, FalProtocol::IMAGE_DEV, 'image', null);
        Http::fake(['*api.fal.ai*' => Http::response(['prices' => [
            ['endpoint_id' => FalProtocol::IMAGE_DEV, 'unit_price' => 0.025, 'unit' => 'image', 'currency' => 'USD'],
        ]])]);
        $this->actingAs($admin)->postJson('/api/admin/pricing/auto/refresh', ['provider_id' => $fal->id])->assertOk();
        $this->postJson('/api/admin/pricing/auto/apply', ['confirm' => true])->assertOk();
        $this->assertSame(10, $image->fresh()->token_cost);

        // Margin 50 %: ceil(0.025 × 19000 × 1.1 / (88.6667 × 0.49)) = 13 tokens.
        $this->putJson('/api/admin/pricing/auto/settings', ['margin_pct' => 50])->assertOk();
        $this->getJson('/api/admin/pricing/auto/preview?kind=media')->assertOk()
            ->assertJsonPath('data.0.current_price', ['token_cost' => 10, 'unit' => 'generation'])
            ->assertJsonPath('data.0.new_price', ['token_cost' => 13, 'unit' => 'generation']);
        $this->postJson('/api/admin/pricing/auto/apply', ['confirm' => true])->assertOk()->assertJsonPath('summary.media_written', 1);
        $this->assertSame([13, 'generation'], [$image->fresh()->token_cost, $image->fresh()->token_cost_unit]);
    }

    public function test_a_cost_stored_in_a_stale_unit_is_reported_and_never_prices_the_model(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $image = $this->model($this->provider('fal'), FalProtocol::IMAGE_DEV, 'image', 15);
        ModelCost::create(['ai_model_profile_id' => $image->id, 'source' => 'manual', 'currency' => 'usd', 'status' => 'ok', 'unit' => 'request', 'unit_cost' => 0.025]);

        $row = $this->actingAs($admin)->getJson('/api/admin/pricing/auto/preview?kind=media')->assertOk()
            ->assertJsonPath('data.0.new_price', null)->json('data.0');
        $this->assertStringContainsString('per request, but this model is priced per generation', $row['error']);
        $this->postJson('/api/admin/pricing/auto/apply', ['confirm' => true])->assertOk()
            ->assertJsonPath('summary.unit_mismatch', 1)->assertJsonPath('summary.media_written', 0);
        $this->assertSame([15, null], [$image->fresh()->token_cost, $image->fresh()->token_cost_unit]);
    }

    public function test_a_cleared_tariff_takes_the_catalog_unit_for_its_next_price(): void
    {
        $longcat = $this->model($this->provider('fal'), FalProtocol::VIDEO, 'video', 80, 'generation');
        $url = '/api/admin/ai/models/'.$longcat->id;
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/admin/ai/providers/'.$longcat->provider_id.'/models')
            ->assertOk()->assertJsonPath('models.0.catalog_price_unit', 'generation');
        $this->patchJson($url, ['token_cost' => 90])->assertOk()->assertJsonPath('model.catalog_price_unit', 'generation');
        $this->patchJson($url, ['token_cost' => null])->assertOk()->assertJsonPath('model.catalog_price_unit', 'second');
        $this->patchJson($url, ['token_cost' => 20])->assertOk()->assertJsonPath('model.generation_config.price_unit', 'second');

        // 20 tokens/second × 5 seconds.
        $this->actingAs($this->member(1000))->postJson('/api/v/gen', ['operation' => 'text_to_video', 'model' => $longcat->model_id,
            'prompt' => 'A garden', 'aspect_ratio' => '16:9', 'duration' => 5, 'expected_price_tokens' => 100])
            ->assertAccepted()->assertJsonPath('tokens_used', 100);
    }

    private function provider(string $protocol): AiProviderProfile
    {
        return AiProviderProfile::create(['name' => ucfirst($protocol), 'slug' => $protocol, 'protocol' => $protocol, 'base_url' => 'https://'.$protocol.'.example',
            'api_key' => 'fixture-key', 'is_enabled' => true, 'status' => 'healthy', 'authenticated_at' => now(),
            'cost_currency' => $protocol === 'kinovi' ? 'credit' : 'usd', 'cost_idr_per_unit' => $protocol === 'kinovi' ? 85.814 : 19000]);
    }

    private function model(AiProviderProfile $provider, string $id, string $category, ?int $tokens, ?string $unit = null): AiModelProfile
    {
        return AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => $id, 'upstream_model_id' => $id, 'display_name' => $id,
            'category' => $category, 'token_cost' => $tokens, 'token_cost_unit' => $unit, 'is_enabled' => true, 'is_available' => true]);
    }

    private function publishedSchema(AiModelProfile $model, User $admin): MediaCapabilityRevision
    {
        $entry = FalCatalogFixture::model($model->model_id);
        $normalized = app(FalCapabilityImporter::class)->normalize([...$entry, 'model_public_id' => $model->model_id], 2);

        return MediaCapabilityRevision::create(['ai_model_profile_id' => $model->id, 'operation' => $normalized['operation'], 'contract_version' => 2,
            'revision' => 1, 'status' => 'published', 'source_schema' => $entry['openapi'], 'source_hash' => FalCapabilityImporter::hash($entry['openapi']),
            'definition' => $normalized['capability']->toArray(), 'provider_bindings' => $normalized['provider_bindings'], 'compatibility_report' => $normalized['report'],
            'reviewed_at' => now(), 'reviewed_by' => $admin->id, 'published_at' => now(),
            'curation_overrides' => ['pricing' => ['token_cost' => $model->token_cost, 'unit' => 'request', 'variable_configuration' => true,
                'reviewed_by' => $admin->id, 'reviewed_at' => now()->toISOString()]]]);
    }

    private function member(int $balance): User
    {
        $user = User::factory()->create(['is_active' => true, 'permissions' => ['video_generator' => true]]);
        UserToken::topup($user->id, $balance);

        return $user;
    }

    private function hash(AiModelProfile $model, MediaOperation $operation): string
    {
        return app(CapabilityResolver::class)->resolve($model, $operation, schemaContracts: true)->sourceHash;
    }

    private function importedCandidate(AiModelProfile $model, ?array $imageSize = null): MediaCapabilityRevision
    {
        $entry = FalCatalogFixture::model($model->model_id);
        $normalized = app(FalCapabilityImporter::class)->normalize([...$entry, 'model_public_id' => $model->model_id], 2);
        $definition = $normalized['capability']->toArray();
        if ($imageSize !== null) {
            $definition['input_schema']['properties']['image_size'] = $imageSize;
        }

        return MediaCapabilityRevision::create(['ai_model_profile_id' => $model->id, 'operation' => $normalized['operation'], 'contract_version' => 2,
            'revision' => 1, 'status' => 'imported', 'source_schema' => $entry['openapi'], 'source_hash' => FalCapabilityImporter::hash($entry['openapi']),
            'definition' => $definition, 'provider_bindings' => $normalized['provider_bindings'], 'compatibility_report' => $normalized['report']]);
    }
}
