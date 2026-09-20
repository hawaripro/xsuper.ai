<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\UsageRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_pricing_creates_input_and_output_rates_for_chat_models(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = AiProviderProfile::create([
            'name' => 'Auto', 'slug' => 'auto', 'protocol' => 'fal',
            'base_url' => 'https://fal.run', 'is_enabled' => true, 'status' => 'healthy',
        ]);
        $model = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'auto-chat', 'upstream_model_id' => 'openai/gpt-4o-mini',
            'display_name' => 'Auto Chat', 'category' => 'chat',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 1,
        ]);
        $this->assertSame(0, UsageRate::where('model', 'auto-chat')->count());

        $response = $this->actingAs($admin)->postJson('/api/pricing/rates/auto', ['margin' => 2]);
        $response->assertOk()->assertJsonPath('updated_count', 2);

        // Flat base rate is 0.15 / 0.60 USD per 1M tokens, doubled by margin=2.
        $this->assertEqualsWithDelta(0.30, (float) UsageRate::where(['model' => 'auto-chat', 'meter' => 'input_tokens'])->value('price_usd'), 0.0001);
        $this->assertEqualsWithDelta(1.20, (float) UsageRate::where(['model' => 'auto-chat', 'meter' => 'output_tokens'])->value('price_usd'), 0.0001);
        $this->assertTrue((bool) UsageRate::where(['model' => 'auto-chat', 'meter' => 'input_tokens'])->value('is_active'));

        unset($model);
    }

    public function test_auto_pricing_skips_existing_rates_unless_overwrite(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = AiProviderProfile::create([
            'name' => 'Auto2', 'slug' => 'auto2', 'protocol' => 'fal',
            'base_url' => 'https://fal.run', 'is_enabled' => true, 'status' => 'healthy',
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'auto-chat-2', 'upstream_model_id' => 'openai/gpt-4o-mini',
            'display_name' => 'Auto Chat 2', 'category' => 'chat',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 1,
        ]);
        foreach (['input_tokens', 'output_tokens'] as $meter) {
            UsageRate::create(['service' => 'api', 'meter' => $meter, 'model' => 'auto-chat-2', 'label' => 'x', 'unit' => '1M tokens', 'price_usd' => 9.99, 'price_idr' => 159840, 'is_active' => true, 'sort_order' => 0]);
        }

        $this->actingAs($admin)->postJson('/api/pricing/rates/auto', ['margin' => 1])->assertOk()->assertJsonPath('updated_count', 0);
        $this->assertEqualsWithDelta(9.99, (float) UsageRate::where(['model' => 'auto-chat-2', 'meter' => 'input_tokens'])->value('price_usd'), 0.0001);

        $this->actingAs($admin)->postJson('/api/pricing/rates/auto', ['margin' => 1, 'overwrite' => true])->assertOk()->assertJsonPath('updated_count', 2);
        $this->assertEqualsWithDelta(0.15, (float) UsageRate::where(['model' => 'auto-chat-2', 'meter' => 'input_tokens'])->value('price_usd'), 0.0001);
    }

    public function test_wallet_topup_route_is_removed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/pricing/wallet/topup', ['user_id' => $admin->id, 'amount_usd' => 5])->assertNotFound();
    }
}
