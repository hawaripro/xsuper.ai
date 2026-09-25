<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ApiKey;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AiProviderEndpoint;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsageBillingCoreTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'billing-core';

    private function rates(bool $cache = true): void
    {
        $prices = ['input_tokens' => 3, 'output_tokens' => 15];
        if ($cache) {
            $prices += ['cache_read' => 0.3, 'cache_write' => 3.75];
        }
        foreach ($prices as $meter => $price) {
            UsageRate::create([
                'service' => 'api', 'model' => self::MODEL, 'meter' => $meter, 'label' => $meter,
                'unit' => '1M tokens', 'price_usd' => $price, 'is_active' => true,
            ]);
        }
    }

    public static function inputEstimates(): array
    {
        return [
            'unicode text' => [[['role' => 'user', 'content' => 'Halo🙂']], [], 6],
            'text and two image encodings' => [[['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'hello!'],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/a.png']],
                ['type' => 'image', 'source' => ['type' => 'base64', 'data' => 'abcd']],
            ]]], [], 3206],
            'text document' => [[['role' => 'user', 'content' => [[
                'type' => 'document', 'source' => ['type' => 'text', 'data' => 'hello'],
            ]]]], [], 6],
            'binary document' => [[['role' => 'user', 'content' => [[
                'type' => 'file', 'file' => ['file_id' => 'owned-file'],
            ]]]], [], 12004],
            'tool input' => [[['role' => 'assistant', 'content' => [[
                'type' => 'tool_use', 'id' => 'a', 'name' => 'lookup', 'input' => ['q' => 'x'],
            ]]]], [], 25],
            'options count toward reservation' => [[['role' => 'user', 'content' => 'abc']], [
                'tools' => [['name' => 'lookup']], 'response_format' => ['type' => 'json_object'], 'system' => 'system',
            ], 23],
        ];
    }

    #[DataProvider('inputEstimates')]
    public function test_estimate_accounts_for_billable_text_media_and_tools(array $messages, array $options, int $expected): void
    {
        $this->assertSame($expected, app(UsageBillingService::class)->estimateInputTokens($messages, $options));
    }

    public function test_usage_normalization_does_not_double_count_cached_input(): void
    {
        $billing = app(UsageBillingService::class);
        $expected = ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120, 'cache_read_tokens' => 40, 'cache_write_tokens' => 10];
        $this->assertSame($expected, $billing->normalizeUsage([
            'input_tokens' => 50, 'cache_read_input_tokens' => 40, 'cache_creation_input_tokens' => 10, 'output_tokens' => 20,
        ]));
        $this->assertSame($expected, $billing->normalizeUsage($expected));
        $this->assertSame(['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120, 'cache_read_tokens' => 40, 'cache_write_tokens' => 0],
            $billing->normalizeUsage(['prompt_tokens' => 100, 'completion_tokens' => 20, 'prompt_tokens_details' => ['cached_tokens' => 40]]));
        $this->assertArrayNotHasKey('prompt_tokens', $billing->normalizeUsage(['completion_tokens' => 20]));
    }

    public function test_cached_usage_settles_once_at_reserved_prices_and_keeps_chat_ledger_service(): void
    {
        $this->rates();
        $user = User::factory()->create();
        Wallet::credit($user->id, 1000000, 'Fixture');
        $billing = app(UsageBillingService::class);
        $reservation = $billing->reserveApi($user->id, self::MODEL, 100, 40, 'chat:cache-snapshot', 'chat');
        $this->assertSame(999100, Wallet::balance($user->id));
        UsageRate::where('model', self::MODEL)->update(['price_usd' => 100]);
        $usage = ['input_tokens' => 50, 'cache_read_input_tokens' => 40, 'cache_creation_input_tokens' => 10, 'output_tokens' => 20];
        $this->assertSame(500, $billing->settleApi($user->id, self::MODEL, $usage, $reservation, 'chat'));
        $this->assertSame(500, $billing->settleApi($user->id, self::MODEL, $usage, $reservation, 'chat'));
        $this->assertSame(999500, Wallet::balance($user->id));
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $user->id, 'service' => 'chat', 'reference_id' => 'chat:cache-snapshot']);
    }

    public function test_cache_without_its_own_rate_bills_at_the_input_rate(): void
    {
        $this->rates(false);
        $user = User::factory()->create();
        Wallet::credit($user->id, 1000000, 'Fixture');
        $billing = app(UsageBillingService::class);
        $reservation = $billing->reserveApi($user->id, self::MODEL, 100, 40, 'cache-fallback');
        $this->assertSame(600, $billing->settleApi($user->id, self::MODEL, [
            'prompt_tokens' => 100, 'completion_tokens' => 20, 'cache_read_tokens' => 40, 'cache_write_tokens' => 10,
        ], $reservation));
        $this->assertSame(999400, Wallet::balance($user->id));
    }

    public function test_output_cap_fits_the_balance_and_rejects_less_than_minimum(): void
    {
        $this->rates(false);
        $user = User::factory()->create();
        Wallet::credit($user->id, 4800, 'Fixture');
        $billing = app(UsageBillingService::class);
        $this->assertSame(300, $billing->affordableOutputTokens($user->id, self::MODEL, 100, 8192));
        $this->assertSame(256, $billing->affordableOutputTokens($user->id, self::MODEL, 100, 256));
        $this->expectException(InsufficientBalanceException::class);
        $billing->affordableOutputTokens($user->id, self::MODEL, 400, 8192);
    }

    public function test_desired_output_below_the_minimum_is_not_admitted(): void
    {
        $this->rates(false);
        $user = User::factory()->create();
        Wallet::credit($user->id, 1000000, 'Fixture');
        $this->expectException(InsufficientBalanceException::class);
        app(UsageBillingService::class)->affordableOutputTokens($user->id, self::MODEL, 10, 128);
    }

    public function test_only_complete_active_rate_pairs_are_sellable(): void
    {
        $this->rates(false);
        UsageRate::create(['service' => 'api', 'model' => 'partial', 'meter' => 'input_tokens', 'label' => 'Partial', 'unit' => '1M tokens', 'price_usd' => 1, 'is_active' => true]);
        $this->assertContains(self::MODEL, UsageRate::sellableModelIds());
        $this->assertNotContains('partial', UsageRate::sellableModelIds());
        UsageRate::where('model', self::MODEL)->where('meter', 'output_tokens')->update(['is_active' => false]);
        $this->assertNotContains(self::MODEL, UsageRate::sellableModelIds());
    }

    private function apiFixture(int $balance): User
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
            'slug' => 'billing-core', 'name' => 'Billing fixture', 'protocol' => 'openai',
            'base_url' => 'https://billing.example.test/v1', 'api_key' => 'fixture-only', 'is_enabled' => true, 'status' => 'healthy',
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => self::MODEL, 'display_name' => 'Billing fixture',
            'category' => 'chat', 'is_enabled' => true, 'is_available' => true,
        ]);
        $this->rates();
        $user = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now(), 'expires_at' => now()->subDay()]);
        if ($balance > 0) {
            Wallet::credit($user->id, $balance, 'Fixture');
        }
        $this->withToken(ApiKey::generate($user->id)->plainKey);

        return $user;
    }

    public function test_empty_wallet_returns_each_api_family_billing_envelope_without_dispatch(): void
    {
        $this->apiFixture(0);
        Http::fake();
        $body = ['model' => self::MODEL, 'messages' => [['role' => 'user', 'content' => 'Hi']], 'max_tokens' => 256];
        $this->postJson('/v1/chat/completions', $body)->assertStatus(402)->assertJsonPath('error.type', 'insufficient_quota')->assertJsonPath('error.code', 'insufficient_balance');
        $this->postJson('/v1/messages', $body)->assertStatus(402)->assertJsonPath('type', 'error')->assertJsonPath('error.type', 'billing_error');
        Http::assertNothingSent();
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_expired_membership_does_not_block_funded_api_or_chat_history_but_permissions_still_do(): void
    {
        $user = $this->apiFixture(1000000);
        Http::fake(['https://billing.example.test/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-fixture', 'object' => 'chat.completion', 'model' => self::MODEL,
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Paid answer'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ])]);
        $this->postJson('/v1/chat/completions', ['model' => self::MODEL, 'messages' => [['role' => 'user', 'content' => 'Hi']], 'max_tokens' => 256])->assertOk()->assertJsonPath('choices.0.message.content', 'Paid answer');
        $this->assertSame(999895, Wallet::balance($user->id));
        $this->actingAs($user)->getJson('/api/c/h')->assertOk();
        $user->update(['permissions' => ['chat' => false]]);
        $this->actingAs($user->fresh())->getJson('/api/c/h')->assertForbidden();
        $user->update(['permissions' => null, 'is_active' => false]);
        $this->actingAs($user->fresh())->getJson('/api/c/h')->assertForbidden();
    }
}
