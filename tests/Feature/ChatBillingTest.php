<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ChatOperation;
use App\Models\PricingSetting;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AiProviderEndpoint;
use App\Services\AiProxyService;
use App\Services\ChatOperationService;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $provider = AiProviderProfile::create([
            'slug' => 'paid-chat', 'name' => 'Private provider', 'protocol' => 'openai',
            'base_url' => 'https://chat.example.test/v1', 'api_key' => 'test-only-key',
            'is_enabled' => true, 'status' => 'healthy',
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'paid-chat', 'upstream_model_id' => 'private-chat',
            'display_name' => 'Paid chat', 'category' => 'chat', 'input_modalities' => ['text'],
            'output_modalities' => ['text'], 'is_enabled' => true, 'is_available' => true,
        ]);
        foreach (['input_tokens' => 0.34, 'output_tokens' => 1.33, 'cache_read' => 0.1, 'cache_write' => 0.5] as $meter => $price) {
            UsageRate::create([
                'service' => 'api', 'model' => 'paid-chat', 'meter' => $meter, 'label' => $meter,
                'unit' => '1M tokens', 'price_usd' => $price, 'price_idr' => $price * 16000, 'is_active' => true,
            ]);
        }
    }

    public function test_member_is_charged_actual_usage_once_and_replay_does_not_reserve_again(): void
    {
        $user = $this->member();
        PricingSetting::current()->update(['chat_output_cap' => 1024]);
        $this->answer();
        $input = $this->input();
        $stream = $this->actingAs($user)->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $operation = ChatOperation::query()->sole();
        $this->assertSame(999982, Wallet::balance($user->id)); // ceil(.34 * 7) + ceil(1.33 * 11) = 18.
        $this->assertSame('settled', $operation->billing['status']);
        $this->assertSame(18, $operation->billing['cost_microusd']);
        $this->assertDatabaseHas('usage_logs', ['source' => 'web', 'user_id' => $user->id, 'cost_microusd' => 18]);
        Http::assertSent(fn ($request) => $request['max_tokens'] === 1024
            && $operation->billing['input_estimate'] === app(UsageBillingService::class)->estimateInputTokens($request['messages']));
        preg_match('/event: final\ndata: (.+)\n/', $stream, $final);
        $this->assertSame(['status' => 'settled', 'cost_usd' => 0.000018], json_decode($final[1], true)['billing']);
        $poll = $this->getJson('/api/c/operations/'.$operation->id)->assertOk()->json('operation');
        $this->assertSame(['status' => 'settled', 'cost_usd' => 0.000018], $poll['billing']);
        $this->assertArrayNotHasKey('rate_snapshot', $poll['billing']);
        $this->assertArrayNotHasKey('billing', $operation->toArray());

        // An admitted request remains replayable even after the price is unpublished and the balance spent.
        UsageRate::query()->update(['is_active' => false]);
        $this->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $this->assertSame(999982, Wallet::balance($user->id));
        $this->assertDatabaseCount('chat_operations', 1);
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'reserve')->where('service', 'chat')->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'settlement')->where('service', 'chat')->count());
        Http::assertSentCount(1);
    }

    public function test_output_cap_fits_wallet_after_the_full_system_and_user_input_estimate(): void
    {
        $user = $this->member(2000);
        $this->answer();
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $billing = ChatOperation::query()->sole()->billing;
        $expected = (int) floor((2000 - ceil(0.5 * $billing['input_estimate']) - 2) / 1.33);
        $this->assertGreaterThanOrEqual(256, $expected);
        $this->assertLessThan(8192, $expected);
        Http::assertSent(fn ($request) => $request['max_tokens'] === $expected);
        $this->assertSame(1982, Wallet::balance($user->id));
    }

    public function test_insufficient_balance_is_402_without_saved_chat_rows_or_provider_request(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertStatus(402)
            ->assertJsonPath('code', 'insufficient_balance')->assertJsonPath('kind', 'wallet')
            ->assertJsonStructure(['message', 'required_usd', 'balance_usd']);
        $this->assertDatabaseCount('chat_operations', 0);
        $this->assertDatabaseCount('chat_history', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_admin_can_use_an_unpriced_model_without_wallet_rows(): void
    {
        UsageRate::query()->delete();
        $this->answer();
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseHas('usage_logs', ['user_id' => $user->id, 'cost_microusd' => 0]);
        $this->getJson('/api/c/m')->assertOk()->assertJsonPath('models.0.id', 'paid-chat');
    }

    public function test_unpriced_model_is_hidden_and_rejected_before_any_writes(): void
    {
        Http::fake();
        UsageRate::query()->where('meter', 'output_tokens')->update(['is_active' => false]);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/c/m')->assertOk()->assertJsonPath('models', []);
        $this->getJson('/api/c/am')->assertOk()->assertJsonPath('models', []);
        $this->getJson('/api/c/capabilities?model=paid-chat')->assertUnprocessable();
        $this->postJson('/api/c/s', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('chat_operations', 0);
        $this->assertDatabaseCount('chat_history', 0);
        Http::assertNothingSent();
    }

    public function test_catalog_prices_are_member_safe_numbers_per_million_tokens(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/api/c/m', '/api/c/am'] as $path) {
            $model = $this->getJson($path)->assertOk()->json('models.0');
            $this->assertEquals(['input_usd' => 0.34, 'output_usd' => 1.33, 'input_idr' => 5440, 'output_idr' => 21280], $model['price']);
            $this->assertIsFloat($model['price']['input_usd']);
            $json = json_encode($model);
            foreach (['private-chat', 'Private provider', 'provider_id', 'base_url', 'api_key', 'rate_snapshot'] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
        }
    }

    public function test_stop_with_text_estimates_once_and_ignores_late_usage(): void
    {
        $user = $this->member();
        $proxy = \Mockery::mock(AiProxyService::class)->makePartial();
        $proxy->shouldReceive('streamChatCompletion')->once()->andReturnUsing(function () use ($user) {
            yield ['choices' => [['delta' => ['content' => 'Partial saved'], 'finish_reason' => null]]];
            $this->actingAs($user)->postJson('/api/c/operations/'.ChatOperation::query()->sole()->id.'/stop')->assertOk();
            yield ['choices' => [['delta' => ['content' => 'Too late'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]];
        });
        $this->app->instance(AiProxyService::class, $proxy);
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $operation = ChatOperation::query()->sole();
        $cost = (int) ceil(0.34 * $operation->billing['input_estimate']) + 7; // 13 characters -> 5 output tokens -> ceil(6.65).
        $this->assertSame('stopped', $operation->status);
        $this->assertSame('Partial saved', $operation->partial_content);
        $this->assertTrue($operation->billing['estimated']);
        $this->assertSame($cost, $operation->billing['cost_microusd']);
        $this->assertSame(1000000 - $cost, Wallet::balance($user->id));
        $this->postJson('/api/c/operations/'.$operation->id.'/stop')->assertOk();
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'settlement')->count());
    }

    public function test_a_rate_change_after_admission_does_not_reprice_the_saved_reservation(): void
    {
        $user = $this->member();
        $input = $this->input();
        app(ChatOperationService::class)->admit($user, $input);
        UsageRate::query()->update(['price_usd' => 100]);
        $this->answer();
        $this->actingAs($user)->postJson('/api/c/s', $input)->assertOk()->streamedContent();
        $this->assertSame(999982, Wallet::balance($user->id));
        $this->assertSame(18, ChatOperation::query()->sole()->billing['cost_microusd']);
    }

    public function test_continuation_and_retry_are_new_charges_not_replays(): void
    {
        $user = $this->member();
        $body = $this->answerBody();
        Http::fakeSequence('https://chat.example.test/v1/chat/completions')
            ->push($body, 200, ['Content-Type' => 'text/event-stream'])
            ->push($body, 200, ['Content-Type' => 'text/event-stream'])
            ->push([], 503)
            ->push($body, 200, ['Content-Type' => 'text/event-stream']);
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $first = ChatOperation::query()->sole();
        $continuation = [...$this->input(), 'continuation' => true, 'continuation_of' => $first->assistant_message_id];
        $this->postJson('/api/c/s', $continuation)->assertOk()->streamedContent();
        $failedInput = $this->input();
        $this->postJson('/api/c/s', $failedInput)->assertOk()->streamedContent();
        $failed = ChatOperation::query()->where('client_request_id', $failedInput['client_request_id'])->sole();
        $this->postJson('/api/c/s', [...$this->input(), 'retry_of' => $failed->assistant_message_id])->assertOk()->streamedContent();
        $this->assertSame(999946, Wallet::balance($user->id));
        $this->assertSame(4, DB::table('wallet_transactions')->where('type', 'reserve')->count());
        $this->assertSame(3, DB::table('wallet_transactions')->where('type', 'settlement')->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'release')->count());
    }

    public function test_provider_failure_before_text_releases_the_whole_reservation(): void
    {
        $user = $this->member();
        Http::fake(['https://chat.example.test/v1/chat/completions' => Http::response(['error' => ['message' => 'Unavailable']], 503)]);
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $operation = ChatOperation::query()->sole();
        $this->assertSame('failed', $operation->status);
        $this->assertSame('released', $operation->billing['status']);
        $this->assertSame(1000000, Wallet::balance($user->id));
        $this->assertDatabaseHas('wallet_transactions', ['type' => 'release', 'reference_id' => 'chat:'.$operation->id]);
        $this->assertSame(0, DB::table('usage_logs')->sum('cost_microusd'));
    }

    public function test_a_lone_zero_text_before_interruption_is_charged_as_output_not_released(): void
    {
        $user = $this->member();
        $interrupted = 'data: '.json_encode(['choices' => [['delta' => ['content' => '0'], 'finish_reason' => null]]])."\n\n";
        Http::fake(['https://chat.example.test/v1/chat/completions' => Http::response($interrupted, 200, ['Content-Type' => 'text/event-stream'])]);
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $operation = ChatOperation::query()->sole();
        $this->assertSame(['failed', '0', 'settled'], [$operation->status, $operation->partial_content, $operation->billing['status']]);
        $cost = (int) ceil(0.34 * $operation->billing['input_estimate']) + 2; // 1 character -> 1 estimated output token -> ceil(1.33).
        $this->assertSame(1000000 - $cost, Wallet::balance($user->id));
        $this->assertDatabaseMissing('wallet_transactions', ['type' => 'release']);
    }

    public function test_expiry_and_deletion_release_queued_reservations_without_waiting_for_a_stream(): void
    {
        Http::fake();
        $user = $this->member();
        [$expired] = app(ChatOperationService::class)->admit($user, $this->input());
        $expired->update(['heartbeat_at' => now()->subMinutes(4)]);
        $this->actingAs($user)->getJson('/api/c/operations/'.$expired->id)->assertOk()
            ->assertJsonPath('operation.billing.status', 'released');
        [$deleted] = app(ChatOperationService::class)->admit($user, $this->input());
        $this->deleteJson('/api/c/h/billing-chat')->assertOk();
        $this->assertSame('released', $deleted->fresh()->billing['status']);
        $this->assertSame(1000000, Wallet::balance($user->id));
        Http::assertNothingSent();
    }

    public function test_settlement_shortfall_keeps_hold_without_breaking_final_stream(): void
    {
        $user = $this->member(2000);
        $this->answer(['prompt_tokens' => 7, 'completion_tokens' => 1000000, 'total_tokens' => 1000007]);
        $stream = $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $operation = ChatOperation::query()->sole();
        $this->assertSame('held', $operation->billing['status']);
        $this->assertStringContainsString('event: final', $stream);
        $this->assertStringNotContainsString('operation_error', $stream);
        $this->assertSame(2000 - $operation->billing['amount_microusd'], Wallet::balance($user->id));
        $this->assertSame(0, DB::table('usage_logs')->sum('cost_microusd'));
    }

    public function test_anthropic_cached_input_is_billed_at_its_own_rates(): void
    {
        $user = $this->member();
        AiProviderProfile::query()->update(['protocol' => 'anthropic']);
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg_test', 'model' => 'private-chat', 'usage' => ['input_tokens' => 100, 'cache_read_input_tokens' => 20, 'cache_creation_input_tokens' => 30, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Cached answer']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 7]],
            ['type' => 'message_stop'],
        ];
        $body = implode('', array_map(fn ($event) => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", $events));
        Http::fake(['https://chat.example.test/v1/messages' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
        $this->actingAs($user)->postJson('/api/c/s', $this->input())->assertOk()->streamedContent();
        $this->assertSame(999939, Wallet::balance($user->id)); // 34 uncached + 2 cache read + 15 cache write + 10 output.
        $operation = ChatOperation::query()->sole();
        $this->assertSame(150, $operation->usage['prompt_tokens']);
        $this->assertSame(20, $operation->usage['cache_read_tokens']);
        $this->assertSame(30, $operation->usage['cache_write_tokens']);
        $this->assertSame(61, $operation->billing['cost_microusd']);
    }

    private function member(int $balance = 1000000): User
    {
        $user = User::factory()->create(['expires_at' => now()->subDay()]);
        Wallet::credit($user->id, $balance, 'Test opening balance');
        return $user;
    }

    private function input(): array
    {
        return [
            'model' => 'paid-chat', 'conversation_id' => 'billing-chat', 'client_request_id' => (string) Str::uuid(),
            'stream_protocol' => 'workspace_v2', 'messages' => [['role' => 'user', 'content' => 'Hello']],
        ];
    }

    private function answer(array $usage = ['prompt_tokens' => 7, 'completion_tokens' => 11, 'total_tokens' => 18]): void
    {
        $body = $this->answerBody($usage);
        Http::fake(['https://chat.example.test/v1/chat/completions' => fn () => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
    }

    private function answerBody(array $usage = ['prompt_tokens' => 7, 'completion_tokens' => 11, 'total_tokens' => 18]): string
    {
        return 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Saved answer'], 'finish_reason' => 'stop']], 'usage' => $usage])."\n\ndata: [DONE]\n\n";
    }
}
