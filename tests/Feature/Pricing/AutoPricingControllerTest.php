<?php

namespace Tests\Feature\Pricing;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoPricingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_guard_calculator_and_confirmed_apply(): void
    {
        Http::preventStrayRequests();
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($member)->getJson('/api/admin/pricing/auto')->assertForbidden();
        $this->actingAs($admin)->putJson('/api/admin/pricing/auto/settings', ['margin_pct' => 95, 'payment_fee_pct' => 1])->assertUnprocessable()->assertJsonValidationErrors('margin_pct');
        $this->putJson('/api/admin/pricing/auto/settings', ['margin_pct' => 94, 'payment_fee_pct' => 1])->assertOk();
        $provider = AiProviderProfile::create(['name' => 'Costs', 'slug' => 'costs', 'protocol' => 'openai']);
        $this->putJson('/api/admin/pricing/auto/providers/'.$provider->id, ['cost_currency' => 'usd', 'paid_idr' => 190000, 'units_received' => 10])->assertOk();
        $this->assertEquals('19000.0000', $provider->fresh()->cost_idr_per_unit);
        $this->postJson('/api/admin/pricing/auto/apply', [])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->postJson('/api/admin/pricing/auto/apply', ['confirm' => true])->assertOk()->assertJsonStructure(['summary']);
        $this->assertDatabaseHas('audit_events', ['action' => 'pricing.settings.updated']);
    }

    public function test_preview_filters_and_manual_cost_lock_are_independent(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $provider = AiProviderProfile::create(['name' => 'Costs', 'slug' => 'costs', 'protocol' => 'openai', 'cost_currency' => 'usd']);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'sonnet', 'display_name' => 'Sonnet', 'category' => 'chat', 'is_enabled' => true]);
        $this->getJson('/api/admin/pricing/auto/preview?kind=llm&status=unknown&q=Sonnet')->assertOk()->assertJsonPath('total', 1);
        $this->putJson('/api/admin/pricing/auto/models/'.$model->id, ['currency' => 'usd', 'input_per_million' => 3, 'output_per_million' => 15])->assertOk();
        $this->putJson('/api/admin/pricing/auto/models/'.$model->id, ['price_locked' => true])->assertOk();
        $this->getJson('/api/admin/pricing/auto/preview?status=locked')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.new_price.input_tokens', 6.65);
        $this->getJson('/api/admin/pricing/auto/preview?kind=media')->assertOk()->assertJsonPath('total', 0);
        $this->deleteJson('/api/admin/pricing/auto/models/'.$model->id.'/cost')->assertOk();
        $this->assertTrue($model->cost()->first()->price_locked);
        $this->assertNotSame('manual', $model->cost()->first()->source);
    }
}
