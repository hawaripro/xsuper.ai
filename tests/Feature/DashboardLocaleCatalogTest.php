<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\UsageRate;
use App\Models\User;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardLocaleCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_english_dashboard_entry_routes_return_the_spa(): void
    {
        $paths = [
            '/en/login', '/en/dashboard', '/en/profile', '/en/chat', '/en/video',
            '/en/templates', '/en/library', '/en/generate-image', '/en/token-usage',
            '/en/paket', '/en/referral', '/en/bantuan', '/en/notifications',
            '/en/admin', '/en/admin/overview', '/en/admin/users', '/en/admin/token-usage',
            '/en/admin/operations', '/en/admin/content', '/en/admin/ai',
            '/en/admin/system', '/en/admin/settings',
        ];

        foreach ($paths as $path) {
            $this->get($path)->assertOk()->assertSee('id="app"', false);
        }
    }

    public function test_catalog_sync_preserves_curated_fields_while_refreshing_live_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config()->set(['services.ai_proxy.url' => 'https://catalog.example.test', 'services.ai_proxy.key' => 'fixture-secret']);
        $provider = AiProviderProfile::create([
            'slug' => 'ai-proxy',
            'name' => 'AI Proxy',
            'status' => 'healthy',
        ]);
        $model = AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'curated-model',
            'display_name' => 'Curated Name',
            'provider_name' => 'Curated Provider',
            'description_id' => 'Deskripsi tetap',
            'description_en' => 'Description stays',
            'logo_url' => '/brands/ultrai/mark-96.webp',
            'context_window' => 128000,
            'category' => 'chat',
            'tier' => 'Premium',
            'capabilities' => ['chat'],
            'input_modalities' => ['text'],
            'output_modalities' => ['text'],
            'badges' => ['Popular'],
            'sort_order' => 4,
            'is_enabled' => true,
            'is_available' => false,
        ]);

        Http::fake([
            '*' => Http::response([
                'data' => [[
                    'id' => 'curated-model',
                    'name' => 'Upstream Name',
                    'owned_by' => 'Upstream Provider',
                    'context_length' => 999999,
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/ai/providers/'.$provider->id.'/sync')
            ->assertOk();

        $model->refresh();
        $this->assertSame('Curated Name', $model->display_name);
        $this->assertSame('Curated Provider', $model->provider_name);
        $this->assertSame('Deskripsi tetap', $model->description_id);
        $this->assertSame('Description stays', $model->description_en);
        $this->assertSame('/brands/ultrai/mark-96.webp', $model->logo_url);
        $this->assertSame(128000, $model->context_window);
        $this->assertSame(['Popular'], $model->badges);
        $this->assertSame(4, $model->sort_order);
        $this->assertTrue($model->is_available);
        $this->assertSame('Premium', $model->tier);
        $this->get('/en/models')->assertOk()->assertSee('Curated Provider')->assertDontSee('Upstream Provider');
    }

    public function test_partial_rate_update_does_not_delete_unspecified_rates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $model = AiModelProfile::create([
            'model_id' => 'rate-model',
            'display_name' => 'Rate Model',
            'is_enabled' => true,
        ]);
        foreach (['input_tokens' => 1.0, 'output_tokens' => 3.0] as $meter => $price) {
            UsageRate::create([
                'service' => 'api',
                'meter' => $meter,
                'model' => $model->model_id,
                'label' => $meter,
                'unit' => '1M tokens',
                'price_idr' => $price * 16000,
                'price_usd' => $price,
                'is_active' => true,
            ]);
        }

        $this->actingAs($admin)
            ->patchJson("/api/admin/ai/models/{$model->id}", [
                'rates' => ['input_tokens' => 2.5],
            ])
            ->assertOk()
            ->assertJsonPath('model.rates.input_tokens.price_usd', 2.5)
            ->assertJsonPath('model.rates.output_tokens.price_usd', 3);

        $this->assertDatabaseHas('usage_rates', [
            'service' => 'api',
            'model' => 'rate-model',
            'meter' => 'output_tokens',
            'price_usd' => 3.00000000,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/ai/models/{$model->id}", ['rates' => ['input_tokens' => null]])
            ->assertUnprocessable()->assertJsonValidationErrors('rates');
        $this->assertSame(5_500_000, app(UsageBillingService::class)
            ->estimateApiMaximum('rate-model', 1_000_000, 1_000_000));
    }

    public function test_sync_retains_safe_provider_context_and_modality_metadata(): void
    {
        config()->set(['services.ai_proxy.url' => 'https://catalog.example.test', 'services.ai_proxy.key' => 'fixture-secret']);
        $provider = AiProviderProfile::create(['slug' => 'ai-proxy', 'name' => 'AI Proxy', 'is_enabled' => true]);
        Http::fake(['*' => Http::response(['data' => [[
            'id' => 'vision-context-model', 'name' => 'Vision Context Model', 'owned_by' => 'Model Maker',
            'category' => 'chat', 'tier' => 'Standard', 'context_length' => 128000,
            'max_output_tokens' => 8192, 'input_modalities' => ['text', 'image'], 'output_modalities' => ['text'],
        ]]])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/ai/providers/'.$provider->id.'/sync')->assertOk();
        $model = AiModelProfile::where('model_id', 'vision-context-model')->firstOrFail();
        $this->assertSame('Model Maker', $model->provider_name);
        $this->assertSame(128000, $model->context_window);
        $this->assertSame(8192, $model->max_output_tokens);
        $this->assertSame(['text', 'image'], $model->input_modalities);
        $this->get('/en/models')->assertOk()->assertSee('Model Maker')->assertSee('128K');
    }

    public function test_admin_model_creation_rejects_ids_beyond_consumers_limit(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/ai/models', [
                'model_id' => str_repeat('a', 121), 'display_name' => 'Too long for chat', 'category' => 'chat',
            ])->assertUnprocessable()->assertJsonValidationErrors('model_id');
        $this->assertDatabaseCount('ai_model_profiles', 0);
    }

    public function test_valid_maximum_model_metadata_fits_generated_rate_columns(): void
    {
        $id = str_repeat('m', 120);
        $provider = AiProviderProfile::create(['slug' => 'ai-proxy', 'name' => 'AI Proxy', 'is_enabled' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/ai/models', [
                'model_id' => $id, 'display_name' => str_repeat('M', 160), 'category' => 'chat',
                'provider_slug' => $provider->slug,
                'sort_order' => 65535, 'is_enabled' => true,
                'rates' => ['input_tokens' => 0.4, 'output_tokens' => 1.2],
            ])->assertCreated();
        foreach (UsageRate::where('model', $id)->get() as $rate) {
            $this->assertLessThanOrEqual(160, mb_strlen($rate->label));
            $this->assertLessThanOrEqual(65535, $rate->sort_order);
        }
    }
}
