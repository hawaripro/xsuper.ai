<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ApiKey;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\Wallet;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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

    public function test_api_charge_refuses_incomplete_active_rates(): void
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

    public function test_admin_api_request_debits_wallet_and_records_only_actual_settled_usage(): void
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
        $provider = AiProviderProfile::create(['slug' => 'paid-provider', 'name' => 'Paid Provider', 'is_enabled' => true]);
        AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'model-a', 'display_name' => 'Paid Model', 'category' => 'chat', 'is_enabled' => true, 'is_available' => true]);
        Http::fake([
            'https://proxy.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'model' => 'model-a',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'done']]],
                'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 500_000, 'total_tokens' => 1_500_000],
            ]),
        ]);
        $key = ApiKey::generate($user->id);

        $payload = $this->withToken($key->plainKey)->postJson('/v1/chat/completions', [
            'model' => 'model-a',
            'messages' => [['role' => 'user', 'content' => 'hello']],
            'max_tokens' => 1_000_000,
        ])->assertOk()->json();

        $this->assertEquals(2.0, $payload['usage']['cost_usd']);
        $this->assertEquals(8.0, $payload['usage']['balance_usd']);
        $this->assertSame(8_000_000, Wallet::balance($user->id));
        $this->assertDatabaseHas('usage_logs', [
            'user_id' => $user->id, 'api_key_id' => $key->id, 'model' => 'model-a', 'source' => 'api', 'cost_microusd' => 2_000_000,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id, 'model' => 'model-a', 'type' => 'settlement', 'amount_microusd' => 0,
        ]);
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

    public function test_admin_api_requests_without_published_rates_are_refused_before_provider_dispatch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Wallet::credit($admin->id, 10_000_000, 'opening balance');
        config(['services.ai_proxy.url' => 'https://proxy.test', 'services.ai_proxy.key' => 'test']);
        $provider = AiProviderProfile::create(['slug' => 'unpriced-provider', 'name' => 'Unpriced Provider', 'is_enabled' => true]);
        AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'unpriced-model', 'display_name' => 'Unpriced Model', 'category' => 'chat', 'is_enabled' => true, 'is_available' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://proxy.test/v1/chat/completions' => Http::response([
                'model' => 'unpriced-model',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'must not be requested']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'total_tokens' => 110],
            ]),
        ]);
        $key = ApiKey::generate($admin->id);

        foreach ([false, true] as $stream) {
            $this->withToken($key->plainKey)->postJson('/v1/chat/completions', [
                'model' => 'unpriced-model',
                'messages' => [['role' => 'user', 'content' => 'hello']],
                'max_tokens' => 100,
                'stream' => $stream,
            ])->assertStatus(400)->assertJsonPath('error.code', 'model_not_found');
        }

        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/chat/completions'));
        $this->assertSame(10_000_000, Wallet::balance($admin->id));
        $this->assertDatabaseMissing('usage_logs', ['user_id' => $admin->id]);
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $admin->id, 'type' => 'reserve']);
    }

    public function test_inactive_api_prices_do_not_allow_a_free_reservation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Wallet::credit($admin->id, 1_000_000, 'opening balance');
        foreach (['input_tokens', 'output_tokens'] as $meter) {
            UsageRate::create([
                'service' => 'api', 'meter' => $meter, 'model' => 'inactive-model', 'label' => $meter,
                'unit' => '1M tokens', 'price_idr' => 16000, 'price_usd' => 1, 'is_active' => false,
            ]);
        }

        try {
            app(UsageBillingService::class)->reserveApi($admin->id, 'inactive-model', 100, 100, 'inactive-request');
            $this->fail('Expected inactive API pricing to refuse the reservation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('model', $exception->errors());
        }

        $this->assertSame(1_000_000, Wallet::balance($admin->id));
        $this->assertDatabaseMissing('wallet_transactions', ['reference_id' => 'inactive-request']);
    }

    public function test_missing_usd_price_is_not_cast_to_a_free_active_rate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Wallet::credit($admin->id, 1_000_000, 'opening balance');
        foreach (['input_tokens' => 1, 'output_tokens' => null] as $meter => $price) {
            UsageRate::create([
                'service' => 'api', 'meter' => $meter, 'model' => 'missing-usd', 'label' => $meter,
                'unit' => '1M tokens', 'price_idr' => 16000, 'price_usd' => $price, 'is_active' => true,
            ]);
        }

        try {
            app(UsageBillingService::class)->reserveApi($admin->id, 'missing-usd', 100, 100, 'missing-usd-request');
            $this->fail('Expected a missing USD price to refuse the reservation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('model', $exception->errors());
        }

        $this->assertSame(1_000_000, Wallet::balance($admin->id));
        $this->assertDatabaseMissing('wallet_transactions', ['reference_id' => 'missing-usd-request']);
    }

    public function test_explicitly_published_zero_api_prices_remain_valid_without_a_wallet_debit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Wallet::credit($admin->id, 1_000_000, 'opening balance');
        foreach (['input_tokens', 'output_tokens'] as $meter) {
            UsageRate::create([
                'service' => 'api', 'meter' => $meter, 'model' => 'published-free', 'label' => $meter,
                'unit' => '1M tokens', 'price_idr' => 0, 'price_usd' => 0, 'is_active' => true,
            ]);
        }
        $billing = app(UsageBillingService::class);

        $reservation = $billing->reserveApi($admin->id, 'published-free', 1_000_000, 1_000_000, 'published-free-request');
        $cost = $billing->settleApi($admin->id, 'published-free', [
            'prompt_tokens' => 500_000, 'completion_tokens' => 100_000, 'total_tokens' => 600_000,
        ], $reservation);

        $this->assertSame(0, $cost);
        $this->assertSame(1_000_000, Wallet::balance($admin->id));
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $admin->id, 'reference_id' => 'published-free-request',
            'type' => 'settlement', 'amount_microusd' => 0, 'quantity' => 600_000,
        ]);
    }

    public function test_admin_api_reservation_requires_sufficient_wallet_balance(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Wallet::credit($admin->id, 299, 'opening balance');
        foreach (['input_tokens' => 1, 'output_tokens' => 2] as $meter => $price) {
            UsageRate::create([
                'service' => 'api', 'meter' => $meter, 'model' => 'paid-model', 'label' => $meter,
                'unit' => '1M tokens', 'price_idr' => 16000 * $price, 'price_usd' => $price, 'is_active' => true,
            ]);
        }

        try {
            app(UsageBillingService::class)->reserveApi($admin->id, 'paid-model', 100, 100, 'admin-insufficient-wallet');
            $this->fail('Expected insufficient wallet balance to refuse the reservation.');
        } catch (InsufficientBalanceException $exception) {
            $this->assertSame(['wallet', 300, 299], [$exception->kind, $exception->required, $exception->balance]);
        }

        $this->assertSame(299, Wallet::balance($admin->id));
        $this->assertDatabaseMissing('wallet_transactions', ['reference_id' => 'admin-insufficient-wallet']);
    }

    public function test_api_settlement_without_a_rate_snapshot_cannot_silently_refund_billable_usage(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Wallet::credit($admin->id, 1000, 'opening balance');
        $reservation = Wallet::reserve($admin->id, 100, 'missing-snapshot', ['service' => 'api', 'model' => 'model-a']);

        try {
            app(UsageBillingService::class)->settleApi($admin->id, 'model-a', [
                'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15,
            ], $reservation);
            $this->fail('Expected a missing rate snapshot to refuse settlement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('model', $exception->errors());
        }

        $this->assertSame(900, Wallet::balance($admin->id));
        $this->assertDatabaseMissing('wallet_transactions', ['reference_id' => 'missing-snapshot', 'type' => 'settlement']);
        $this->assertDatabaseMissing('wallet_transactions', ['reference_id' => 'missing-snapshot', 'type' => 'settlement_refund']);
    }
}
