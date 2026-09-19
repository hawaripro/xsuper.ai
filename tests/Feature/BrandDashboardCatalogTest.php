<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AuditEvent;
use App\Models\ContentBlock;
use App\Models\UsageRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandDashboardCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_header_reflects_authentication_and_unpublished_announcement_is_hidden(): void
    {
        ContentBlock::create([
            'key' => 'system.announcement',
            'locale' => 'id',
            'draft' => ['message' => 'Draft only', 'level' => 'info'],
            'published' => null,
            'is_published' => false,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('href="/login"', false)
            ->assertDontSee('Draft only');

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('href="/dashboard"', false)
            ->assertSee('>Dashboard ', false);
    }

    public function test_english_dashboard_routes_serve_the_spa(): void
    {
        foreach (['/en/login', '/en/dashboard', '/en/profile', '/en/admin/ai'] as $path) {
            $this->get($path)->assertOk()->assertSee('id="app"', false);
        }
    }

    public function test_public_catalog_uses_enabled_database_profiles_and_localized_metadata(): void
    {
        $provider = AiProviderProfile::create(['slug' => 'test', 'name' => 'Test Provider', 'status' => 'healthy']);
        $enabled = AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'test-model',
            'display_name' => 'Test Model',
            'provider_name' => 'Curated Provider',
            'category' => 'chat',
            'description_id' => 'Deskripsi Indonesia unik.',
            'description_en' => 'Unique English description.',
            'logo_url' => '/logo-xsuper.png',
            'context_window' => 1000000,
            'max_output_tokens' => 32000,
            'is_enabled' => true,
            'is_available' => true,
            'capabilities' => ['chat', 'vision'],
            'input_modalities' => ['text', 'image'],
            'output_modalities' => ['text'],
            'badges' => ['Popular'],
            'sort_order' => 1,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'disabled-model',
            'display_name' => 'Disabled Model',
            'is_enabled' => false,
            'is_available' => true,
        ]);
        foreach (['input_tokens' => 1.25, 'output_tokens' => 5.00, 'cache_read' => 0.125, 'cache_write' => 1.50] as $meter => $price) {
            UsageRate::create([
                'service' => 'api',
                'meter' => $meter,
                'model' => $enabled->model_id,
                'label' => $meter,
                'unit' => '1M tokens',
                'price_idr' => $price * 16000,
                'price_usd' => $price,
                'is_active' => true,
            ]);
        }

        $this->get('/models')
            ->assertOk()
            ->assertSee('Deskripsi Indonesia unik.')
            ->assertSee('Rp 20.000')
            ->assertSee('Rp 2.000')
            ->assertSee('1M')
            ->assertDontSee('Disabled Model');

        $this->get('/en/models')
            ->assertOk()
            ->assertSee('Unique English description.')
            ->assertSee('$1.250')
            ->assertSee('$0.125');
    }

    public function test_admin_can_update_full_model_profile_and_pricing_transactionally(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = AiProviderProfile::create(['slug' => 'test', 'name' => 'Test Provider']);
        $model = AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'editable-model',
            'display_name' => 'Before',
            'category' => 'chat',
            'is_enabled' => true,
            'is_available' => true,
        ]);

        $response = $this->actingAs($admin)->patchJson("/api/admin/ai/models/{$model->id}", [
            'display_name' => 'After',
            'provider_name' => 'Edited Provider',
            'description_id' => 'Deskripsi edit',
            'description_en' => 'Edited description',
            'logo_url' => '/logo-xsuper.png',
            'context_window' => 128000,
            'max_output_tokens' => 8192,
            'category' => 'chat',
            'tier' => 'Premium',
            'capabilities' => ['chat', 'vision'],
            'input_modalities' => ['text', 'image'],
            'output_modalities' => ['text'],
            'badges' => ['New'],
            'sort_order' => 7,
            'is_enabled' => true,
            'rates' => [
                'input_tokens' => 0.50,
                'output_tokens' => 2.00,
                'cache_read' => 0.05,
                'cache_write' => 0.60,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('model.display_name', 'After')
            ->assertJsonPath('model.provider_name', 'Edited Provider')
            ->assertJsonPath('model.context_window', 128000)
            ->assertJsonPath('model.rates.input_tokens.price_usd', 0.5)
            ->assertJsonPath('model.rates.cache_write.price_usd', 0.6);

        $this->assertDatabaseHas('ai_model_profiles', [
            'id' => $model->id,
            'display_name' => 'After',
            'context_window' => 128000,
            'sort_order' => 7,
        ]);
        $this->assertDatabaseHas('usage_rates', [
            'service' => 'api',
            'meter' => 'cache_read',
            'model' => 'editable-model',
            'price_usd' => 0.05000000,
        ]);
        $this->assertTrue(AuditEvent::where('action', 'ai_model.updated')->exists());
    }

    public function test_non_admin_cannot_update_model_profile_and_validation_is_enforced(): void
    {
        $user = User::factory()->create(['role' => 'member']);
        $admin = User::factory()->create(['role' => 'admin']);
        $model = AiModelProfile::create(['model_id' => 'secure-model', 'display_name' => 'Secure', 'is_enabled' => true]);

        $this->actingAs($user)
            ->patchJson("/api/admin/ai/models/{$model->id}", ['display_name' => 'Denied'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson("/api/admin/ai/models/{$model->id}", ['logo_url' => 'javascript:alert(1)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo_url');
    }
}
