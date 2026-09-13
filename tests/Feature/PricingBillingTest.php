<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ExternalApiController;
use App\Http\Controllers\Api\VideoController;
use App\Models\ApiKey;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Models\Wallet;
use App\Services\AiProxyService;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class PricingBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_publish_independent_duration_and_usage_prices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/pricing/durations/1_month', [
            'price_idr' => 60000,
            'price_usd' => 4.25,
            'is_active' => true,
        ])->assertOk();

        $this->actingAs($admin)->postJson('/api/pricing/rates', [
            'service' => 'api',
            'meter' => 'input_tokens',
            'model' => 'model-a',
            'label' => 'Model A input',
            'unit' => '1M tokens',
            'price_idr' => 16000,
            'price_usd' => 1,
            'is_active' => false,
        ])->assertCreated();

        $this->assertDatabaseHas('duration_package_prices', [
            'package' => '1_month',
            'price_idr' => 60000,
            'price_usd' => 4.25,
        ]);
        $this->assertDatabaseHas('usage_rates', [
            'service' => 'api',
            'meter' => 'input_tokens',
            'model' => 'model-a',
            'is_active' => false,
        ]);
    }

    public function test_api_billing_uses_distinct_input_output_rates_and_debits_wallet_atomically(): void
    {
        $user = User::factory()->create();
        Wallet::credit($user->id, 10_000_000, 'test balance');
        UsageRate::create([
            'service' => 'api', 'meter' => 'input_tokens', 'model' => 'model-a', 'label' => 'Input', 'unit' => '1M tokens',
            'price_idr' => 16000, 'price_usd' => 1, 'is_active' => true,
        ]);
        UsageRate::create([
            'service' => 'api', 'meter' => 'output_tokens', 'model' => 'model-a', 'label' => 'Output', 'unit' => '1M tokens',
            'price_idr' => 32000, 'price_usd' => 2, 'is_active' => true,
        ]);

        $cost = app(UsageBillingService::class)->chargeApiUsage($user->id, 'model-a', [
            'prompt_tokens' => 1_000_000,
            'completion_tokens' => 500_000,
            'total_tokens' => 1_500_000,
        ], 'request-1');

        $this->assertSame(2_000_000, $cost);
        $this->assertSame(8_000_000, Wallet::balance($user->id));
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'service' => 'api',
            'model' => 'model-a',
            'amount_microusd' => -2_000_000,
        ]);
    }

    public function test_usage_charge_fails_when_balance_or_complete_rates_are_missing(): void
    {
        $user = User::factory()->create();
        UsageRate::create([
            'service' => 'api', 'meter' => 'input_tokens', 'model' => 'model-a', 'label' => 'Input', 'unit' => '1M tokens',
            'price_idr' => 16000, 'price_usd' => 1, 'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(UsageBillingService::class)->chargeApiUsage($user->id, 'model-a', [
            'prompt_tokens' => 100,
            'completion_tokens' => 100,
        ]);
    }

    public function test_video_unit_rate_charges_each_successful_requested_result(): void
    {
        $user = User::factory()->create();
        Wallet::credit($user->id, 5_000_000, 'test balance');
        UsageRate::create([
            'service' => 'video', 'meter' => 'unit', 'model' => 'sora-2', 'label' => 'Sora 2', 'unit' => 'video',
            'price_idr' => 24000, 'price_usd' => 1.5, 'is_active' => true,
        ]);

        $cost = app(UsageBillingService::class)->chargeUnit($user->id, 'video', 'sora-2', 2, 'video-test');

        $this->assertSame(3_000_000, $cost);
        $this->assertSame(2_000_000, Wallet::balance($user->id));
    }

    public function test_api_reservation_refunds_unused_maximum_and_records_actual_cost(): void
    {
        $user = User::factory()->create();
        Wallet::credit($user->id, 10_000_000, 'test balance');
        foreach (['input_tokens' => 1, 'output_tokens' => 2] as $meter => $price) {
            UsageRate::create([
                'service' => 'api', 'meter' => $meter, 'model' => 'model-a', 'label' => $meter, 'unit' => '1M tokens',
                'price_idr' => $price * 16000, 'price_usd' => $price, 'is_active' => true,
            ]);
        }

        $billing = app(UsageBillingService::class);
        $reservation = $billing->reserveApi($user->id, 'model-a', 1_000_000, 1_000_000, 'request-reserve');
        $cost = $billing->settleApi($user->id, 'model-a', [
            'prompt_tokens' => 500_000,
            'completion_tokens' => 250_000,
            'total_tokens' => 750_000,
        ], $reservation);

        $this->assertSame(1_000_000, $cost);
        $this->assertSame(9_000_000, Wallet::balance($user->id));
    }

    public function test_user_catalog_returns_active_database_pricing_and_wallet(): void
    {
        $user = User::factory()->create();
        Wallet::credit($user->id, 2_500_000, 'test balance');
        UsageRate::create([
            'service' => 'image', 'meter' => 'unit', 'model' => 'image-a', 'label' => 'Image A', 'unit' => 'image',
            'price_idr' => 8000, 'price_usd' => 0.5, 'is_active' => true,
        ]);

        $this->actingAs($user)->getJson('/api/pricing/catalog')
            ->assertOk()
            ->assertJsonPath('wallet.balance_microusd', 2_500_000)
            ->assertJsonCount(1, 'usage_rates.image');
    }

    public function test_non_streaming_api_reserves_then_settles_from_reported_usage(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Wallet::credit($user->id, 10_000_000, 'test balance');
        foreach (['input_tokens' => 1, 'output_tokens' => 2] as $meter => $price) {
            UsageRate::create([
                'service' => 'api', 'meter' => $meter, 'model' => 'model-a', 'label' => $meter, 'unit' => '1M tokens',
                'price_idr' => $price * 16000, 'price_usd' => $price, 'is_active' => true,
            ]);
        }
        config(['services.ai_proxy.url' => 'https://proxy.test', 'services.ai_proxy.key' => 'test']);
        Http::fake([
            'https://proxy.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'model' => 'model-a',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'done']]],
                'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 500_000, 'total_tokens' => 1_500_000],
            ]),
        ]);
        $proxy = Mockery::mock(AiProxyService::class);
        $proxy->shouldReceive('getModels')->once()->andReturn([['id' => 'model-a']]);
        $request = Request::create('/v1/chat/completions', 'POST', [
            'model' => 'model-a',
            'messages' => [['role' => 'user', 'content' => 'hello']],
            'max_tokens' => 1_000_000,
        ]);
        $request->merge(['_api_user' => $user, '_api_key' => new ApiKey(['allowed_models' => null])]);

        $response = (new ExternalApiController($proxy, app(UsageBillingService::class)))->chatCompletions($request);
        $payload = $response->getData(true);

        $this->assertEquals(2.0, $payload['usage']['cost_usd']);
        $this->assertEquals(8.0, $payload['usage']['balance_usd']);
        $this->assertSame(8_000_000, Wallet::balance($user->id));
    }

    public function test_video_payg_reserves_without_legacy_tokens_then_settles_once(): void
    {
        $user = User::factory()->create();
        Wallet::credit($user->id, 5_000_000, 'test balance');
        UsageRate::create([
            'service' => 'video', 'meter' => 'unit', 'model' => 'sora-2', 'label' => 'Sora 2', 'unit' => 'video',
            'price_idr' => 24000, 'price_usd' => 1.5, 'is_active' => true,
        ]);
        UserToken::topup($user->id, 100, 'legacy balance');

        $request = Request::create('/api/v/gen', 'POST', [
            'prompt' => 'Generate a product clip',
            'model' => 'sora-2',
            'aspect_ratio' => '16:9',
            'count' => 1,
            'mode' => 'prompt',
        ]);
        $request->setUserResolver(fn () => $user);
        $controller = new VideoController;
        $payload = $controller->generate($request, app(UsageBillingService::class))->getData(true);

        $this->assertSame('payg', $payload['billing_mode']);
        $this->assertSame(100, UserToken::getBalance($user->id));
        $this->assertSame(3_500_000, Wallet::balance($user->id));
        $job = VideoJob::findOrFail($payload['jobs'][0]['id']);
        $job->update(['status' => 'completed']);

        $this->actingAs($user);
        $controller->status($job->job_id);
        $controller->status($job->job_id);

        $this->assertSame(3_500_000, Wallet::balance($user->id));
        $this->assertSame('settled', $job->fresh()->billing_status);
    }

    public function test_api_reservation_settles_with_the_reserved_rate_snapshot(): void
    {
        $user = User::factory()->create();
        Wallet::credit($user->id, 10_000_000, 'test balance');
        foreach (['input_tokens' => 1, 'output_tokens' => 2] as $meter => $price) {
            UsageRate::create([
                'service' => 'api', 'meter' => $meter, 'model' => 'model-a', 'label' => $meter, 'unit' => '1M tokens',
                'price_idr' => $price * 16000, 'price_usd' => $price, 'is_active' => true,
            ]);
        }

        $billing = app(UsageBillingService::class);
        $reservation = $billing->reserveApi($user->id, 'model-a', 1_000_000, 1_000_000, 'snapshot-request');
        UsageRate::where('model', 'model-a')->update(['price_usd' => 9]);
        $cost = $billing->settleApi($user->id, 'model-a', [
            'prompt_tokens' => 500_000,
            'completion_tokens' => 250_000,
            'total_tokens' => 750_000,
        ], $reservation);

        $this->assertSame(1_000_000, $cost);
        $this->assertSame(9_000_000, Wallet::balance($user->id));
    }

    public function test_releasing_the_same_reservation_twice_only_refunds_once(): void
    {
        $user = User::factory()->create();
        Wallet::credit($user->id, 2_000_000, 'test balance');
        $reservation = Wallet::reserve($user->id, 1_000_000, 'release-once', ['service' => 'api']);

        Wallet::release($user->id, $reservation, 'failed request');
        Wallet::release($user->id, $reservation, 'duplicate failure callback');

        $this->assertSame(2_000_000, Wallet::balance($user->id));
    }

    public function test_admin_activates_and_deactivates_api_rate_pairs_together(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $input = UsageRate::create([
            'service' => 'api', 'meter' => 'input_tokens', 'model' => 'paired-model', 'label' => 'Input', 'unit' => '1M tokens',
            'price_idr' => 16000, 'price_usd' => 1, 'is_active' => false,
        ]);
        $output = UsageRate::create([
            'service' => 'api', 'meter' => 'output_tokens', 'model' => 'paired-model', 'label' => 'Output', 'unit' => '1M tokens',
            'price_idr' => 32000, 'price_usd' => 2, 'is_active' => false,
        ]);

        $this->actingAs($admin)->putJson('/api/pricing/rates/'.$input->id, [
            ...$input->only(['service', 'meter', 'model', 'label', 'unit', 'price_idr', 'price_usd', 'sort_order']),
            'is_active' => true,
        ])->assertOk();
        $this->assertTrue($input->fresh()->is_active);
        $this->assertTrue($output->fresh()->is_active);

        $this->actingAs($admin)->putJson('/api/pricing/rates/'.$output->id, [
            ...$output->only(['service', 'meter', 'model', 'label', 'unit', 'price_idr', 'price_usd', 'sort_order']),
            'is_active' => false,
        ])->assertOk();
        $this->assertFalse($input->fresh()->is_active);
        $this->assertFalse($output->fresh()->is_active);
    }
}
