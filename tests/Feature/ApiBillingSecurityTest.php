<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ApiKey;
use App\Models\UsageLog;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AiProviderEndpoint;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiBillingSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'billing-security';

    private const COMPLETION_URL = 'https://openai.example.test/v1/chat/completions';

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
        config(['services.ai_proxy.url' => 'https://legacy.example.test', 'services.ai_proxy.key' => 'legacy-fixture-key']);
        Http::fake(['https://legacy.example.test/v1/models' => Http::response(['data' => []])]);
        $provider = AiProviderProfile::create([
            'slug' => 'billing-security', 'name' => 'Billing fixture', 'protocol' => 'openai',
            'base_url' => 'https://openai.example.test/v1', 'api_key' => 'billing-fixture-key',
            'is_enabled' => true, 'status' => 'healthy',
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => self::MODEL, 'upstream_model_id' => 'upstream-billing',
            'display_name' => 'Billing fixture', 'category' => 'chat', 'is_enabled' => true, 'is_available' => true,
        ]);
        foreach (['input_tokens', 'output_tokens'] as $meter) {
            UsageRate::create([
                'service' => 'api', 'meter' => $meter, 'model' => self::MODEL, 'label' => $meter,
                'unit' => '1M tokens', 'price_usd' => 1, 'price_idr' => 16000, 'is_active' => true,
            ]);
        }
    }

    private function account(int $balance): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        Wallet::credit($user->id, $balance, 'Fixture balance');
        $key = ApiKey::generate($user->id);
        $this->withToken($key->plainKey);

        return $user;
    }

    private function payload(bool $stream): array
    {
        return ['model' => self::MODEL, 'messages' => [['role' => 'user', 'content' => 'Hi']], 'max_tokens' => 1, 'stream' => $stream];
    }

    private function completion(bool $stream, ?array $usage, bool $complete = true): void
    {
        $body = [
            'id' => 'chatcmpl-billing', 'object' => 'chat.completion', 'model' => 'upstream-billing',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Delivered answer'], 'finish_reason' => 'stop']],
        ];
        if ($usage !== null) {
            $body['usage'] = $usage;
        }
        if ($stream) {
            $frames = [['id' => 'chatcmpl-billing', 'choices' => [['index' => 0, 'delta' => ['content' => 'Delivered answer'], 'finish_reason' => null]]]];
            if ($complete) {
                $frames[] = ['id' => 'chatcmpl-billing', 'choices' => [['index' => 0, 'delta' => new \stdClass, 'finish_reason' => 'stop']]];
                if ($usage !== null) {
                    $frames[] = ['id' => 'chatcmpl-billing', 'choices' => [], 'usage' => $usage];
                }
            }
            $body = implode('', array_map(static fn (array $frame): string => 'data: '.json_encode($frame)."\n\n", $frames));
            if ($complete) {
                $body .= "data: [DONE]\n\n";
            }
        }
        Http::fake([self::COMPLETION_URL => Http::response($body, 200, ['Content-Type' => $stream ? 'text/event-stream' : 'application/json'])]);
    }

    public static function apiVariants(): array
    {
        return [
            'chat JSON' => ['/v1/chat/completions', false],
            'chat SSE' => ['/v1/chat/completions', true],
            'messages JSON' => ['/v1/messages', false],
            'messages SSE' => ['/v1/messages', true],
        ];
    }

    public static function expensiveInputs(): array
    {
        $tools = ['tools' => [['type' => 'function', 'function' => [
            'name' => 'lookup', 'description' => str_repeat('d', 40000),
            'parameters' => ['type' => 'object', 'properties' => new \stdClass],
        ]]]];
        $schema = ['response_format' => ['type' => 'json_schema', 'json_schema' => [
            'name' => 'answer', 'schema' => ['type' => 'object', 'description' => str_repeat('s', 40000)],
        ]]];

        return [
            'tools JSON' => ['/v1/chat/completions', false, $tools],
            'tools SSE' => ['/v1/chat/completions', true, $tools],
            'schema JSON' => ['/v1/chat/completions', false, $schema],
            'schema SSE' => ['/v1/chat/completions', true, $schema],
            'conflicting output limits' => ['/v1/chat/completions', true, ['max_tokens' => 20000, 'max_completion_tokens' => 1]],
            'messages long prompt' => ['/v1/messages', true, ['messages' => [['role' => 'user', 'content' => str_repeat('m', 40000)]]]],
        ];
    }

    #[DataProvider('expensiveInputs')]
    public function test_unaffordable_forwarded_input_is_rejected_before_generation(string $path, bool $stream, array $options): void
    {
        $user = $this->account(10000);
        $response = $this->postJson($path, array_replace($this->payload($stream), $options))->assertStatus(402);
        if ($path === '/v1/messages') {
            $response->assertJsonPath('type', 'error')->assertJsonPath('error.type', 'billing_error');
        } else {
            $response->assertJsonPath('error.type', 'insufficient_quota')->assertJsonPath('error.code', 'insufficient_balance');
        }

        Http::assertNotSent(fn ($request) => $request->url() === self::COMPLETION_URL);
        $this->assertSame(10000, Wallet::balance($user->id));
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $user->id, 'type' => 'reserve']);
        $this->assertDatabaseMissing('usage_logs', ['user_id' => $user->id]);
    }

    #[DataProvider('apiVariants')]
    public function test_completed_work_cannot_be_discounted_or_refunded_when_actual_cost_exceeds_balance(string $path, bool $stream): void
    {
        $user = $this->account(10000);
        $this->completion($stream, ['prompt_tokens' => 20000, 'completion_tokens' => 1, 'total_tokens' => 20001]);
        $response = $this->postJson($path, $this->payload($stream));
        if ($stream) {
            $body = $response->assertOk()->streamedContent();
            $this->assertStringContainsString('Delivered answer', $body);
            $this->assertStringContainsString('"error"', $body);
            $this->assertStringNotContainsString('data: [DONE]', $body);
            $this->assertStringNotContainsString('event: message_stop', $body);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('wallet');
        }
        $this->assertReservationHeld($user, 10000);
    }

    #[DataProvider('apiVariants')]
    public function test_completed_work_without_usage_cannot_be_refunded_as_a_free_response(string $path, bool $stream): void
    {
        $user = $this->account(10000);
        $this->completion($stream, null);
        $response = $this->postJson($path, $this->payload($stream));
        if ($stream) {
            $body = $response->assertOk()->streamedContent();
            $this->assertStringContainsString('Delivered answer', $body);
            $this->assertStringContainsString('"error"', $body);
            $this->assertStringNotContainsString('data: [DONE]', $body);
            $this->assertStringNotContainsString('event: message_stop', $body);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('usage');
        }
        $this->assertReservationHeld($user, 10000);
    }

    public static function nativeVariants(): array
    {
        $cases = [];
        foreach (['anthropic', 'fal'] as $protocol) {
            foreach (self::apiVariants() as $name => [$path, $stream]) {
                $cases[$protocol.' '.$name] = [$protocol, $path, $stream];
            }
        }

        return $cases;
    }

    private function nativeCompletion(string $protocol, bool $stream, ?array $usage, bool $complete = true, int $httpStatus = 200): void
    {
        $base = $protocol === 'anthropic' ? 'https://anthropic.example.test/v1' : 'https://fal.run';
        AiProviderProfile::where('slug', 'billing-security')->update(['protocol' => $protocol, 'base_url' => $base]);
        if ($protocol === 'fal') {
            AiModelProfile::where('model_id', self::MODEL)->update(['upstream_model_id' => \App\Services\FalProtocol::CHAT_MODEL]);
            $url = $base.'/'.\App\Services\FalProtocol::ROUTER;
            $body = ['output' => 'Delivered answer', 'partial' => ! $complete];
            if ($usage !== null) {
                $body['usage'] = $usage;
            }
        } else {
            $url = $base.'/messages';
            $nativeUsage = $usage === null ? null : array_combine(array_map(static fn (string $key): string => match ($key) {
                'prompt_tokens' => 'input_tokens', 'completion_tokens' => 'output_tokens', default => $key,
            }, array_keys($usage)), array_values($usage));
            $body = ['id' => 'msg-native-billing', 'type' => 'message', 'role' => 'assistant', 'model' => 'upstream-billing',
                'content' => [['type' => 'text', 'text' => 'Delivered answer']], 'stop_reason' => $complete ? 'end_turn' : null];
            if ($nativeUsage !== null) {
                $body['usage'] = $nativeUsage;
            }
            if ($stream) {
                $start = ['id' => 'msg-native-billing', 'model' => 'upstream-billing'];
                $delta = ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']];
                if ($nativeUsage !== null) {
                    $start['usage'] = array_diff_key($nativeUsage, ['output_tokens' => true]);
                    $delta['usage'] = array_intersect_key($nativeUsage, ['output_tokens' => true]);
                }
                $frames = [
                    ['type' => 'message_start', 'message' => $start],
                    ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                    ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Delivered answer']],
                    ['type' => 'content_block_stop', 'index' => 0],
                    $delta,
                ];
                if ($complete) {
                    $frames[] = ['type' => 'message_stop'];
                }
                $body = implode('', array_map(static fn (array $frame): string => 'event: '.$frame['type']."\ndata: ".json_encode($frame)."\n\n", $frames));
            }
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://legacy.example.test/v1/models' => Http::response(['data' => []]),
            $url => Http::response($body, $httpStatus, ['Content-Type' => $protocol === 'anthropic' && $stream ? 'text/event-stream' : 'application/json']),
        ]);
    }

    #[DataProvider('nativeVariants')]
    public function test_completed_native_results_with_unverifiable_usage_keep_the_reservation(string $protocol, string $path, bool $stream): void
    {
        foreach ([null, [], ['completion_tokens' => 4], ['prompt_tokens' => 12], ['prompt_tokens' => -1, 'completion_tokens' => 4],
            ['prompt_tokens' => PHP_INT_MAX, 'completion_tokens' => 0]] as $usage) {
            $user = $this->account(10000);
            $this->nativeCompletion($protocol, $stream, $usage);
            $response = $this->postJson($path, $this->payload($stream));
            if ($stream) {
                $body = $response->assertOk()->streamedContent();
                $this->assertStringContainsString('Delivered answer', $body);
                $this->assertStringContainsString('"error"', $body);
                $this->assertStringNotContainsString('data: [DONE]', $body);
                $this->assertStringNotContainsString('event: message_stop', $body);
            } else {
                $response->assertUnprocessable()->assertJsonValidationErrors('usage');
            }
            $this->assertReservationHeld($user, 10000);
        }
    }

    #[DataProvider('nativeVariants')]
    public function test_native_zero_usage_and_real_usage_settle_exactly(string $protocol, string $path, bool $stream): void
    {
        foreach ([[0, 0], [12, 6]] as [$input, $output]) {
            $user = $this->account(10000);
            $this->nativeCompletion($protocol, $stream, ['prompt_tokens' => $input, 'completion_tokens' => $output]);
            $response = $this->postJson($path, $this->payload($stream))->assertOk();
            $body = $stream ? $response->streamedContent() : $response->getContent();
            $this->assertStringContainsString('Delivered answer', $body);
            $this->assertStringNotContainsString('"error"', $body);
            $this->assertSame(10000 - $input - $output, Wallet::balance($user->id));
            $this->assertSame($input + $output, (int) UsageLog::where('user_id', $user->id)->sole()->cost_microusd);
            $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'settlement')->count());
            $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $user->id, 'type' => 'release']);
        }
    }

    #[DataProvider('nativeVariants')]
    public function test_native_upstream_failures_and_incomplete_results_still_refund(string $protocol, string $path, bool $stream): void
    {
        foreach ([true, false] as $httpFailure) {
            $user = $this->account(10000);
            $this->nativeCompletion($protocol, $stream, ['prompt_tokens' => 12, 'completion_tokens' => 6], $httpFailure, $httpFailure ? 401 : 200);
            $response = $this->postJson($path, $this->payload($stream));
            if ($stream) {
                $body = $response->assertOk()->streamedContent();
                $this->assertStringContainsString('"error"', $body);
                $this->assertStringNotContainsString('data: [DONE]', $body);
                $this->assertStringNotContainsString('event: message_stop', $body);
            } else {
                $response->assertStatus(502);
            }
            $this->assertSame(10000, Wallet::balance($user->id));
            $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'release')->count());
            $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $user->id, 'type' => 'settlement']);
        }
    }

    private function assertReservationHeld(User $user, int $openingBalance): void
    {
        $ledger = WalletTransaction::where('user_id', $user->id)->where('service', 'api')->get();
        $this->assertSame(['reserve'], $ledger->pluck('type')->all());
        $this->assertLessThan(0, $ledger->sole()->amount_microusd);
        $this->assertSame($openingBalance + $ledger->sole()->amount_microusd, Wallet::balance($user->id));
        $this->assertGreaterThanOrEqual(0, Wallet::balance($user->id));
        $this->assertDatabaseMissing('usage_logs', ['user_id' => $user->id]);
    }

    #[DataProvider('apiVariants')]
    public function test_successful_completion_charges_reported_usage_and_releases_only_unused_reservation(string $path, bool $stream): void
    {
        $user = $this->account(1000000);
        $this->completion($stream, ['prompt_tokens' => 12, 'completion_tokens' => 6, 'total_tokens' => 18]);
        $response = $this->postJson($path, $this->payload($stream))->assertOk();
        $body = $stream ? $response->streamedContent() : $response->getContent();
        $this->assertStringContainsString('Delivered answer', $body);
        if ($stream) {
            $this->assertStringContainsString($path === '/v1/messages' ? 'event: message_stop' : 'data: [DONE]', $body);
            $this->assertStringNotContainsString('"error"', $body);
        }
        $this->assertSame(999982, Wallet::balance($user->id));
        $this->assertSame(18, (int) UsageLog::where('user_id', $user->id)->sole()->cost_microusd);
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'settlement')->count());
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $user->id, 'type' => 'release']);
    }

    #[DataProvider('apiVariants')]
    public function test_provider_failure_still_releases_the_reservation_without_billing(string $path, bool $stream): void
    {
        $user = $this->account(10000);
        Http::fake([self::COMPLETION_URL => Http::response(['error' => ['message' => 'fixture provider denied']], 401)]);
        $response = $this->postJson($path, $this->payload($stream));
        if ($stream) {
            $body = $response->assertOk()->streamedContent();
            $this->assertStringContainsString('"error"', $body);
            $this->assertStringNotContainsString('Delivered answer', $body);
        } else {
            $response->assertStatus(502);
        }
        $this->assertSame(10000, Wallet::balance($user->id));
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'release')->count());
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $user->id, 'type' => 'settlement']);
        $this->assertDatabaseMissing('usage_logs', ['user_id' => $user->id]);
    }

    public function test_incomplete_provider_streams_still_release_the_reservation(): void
    {
        foreach (['/v1/chat/completions', '/v1/messages'] as $path) {
            $user = $this->account(10000);
            $this->completion(true, null, false);
            $body = $this->postJson($path, $this->payload(true))->assertOk()->streamedContent();
            $this->assertStringContainsString('"error"', $body);
            $this->assertStringNotContainsString('data: [DONE]', $body);
            $this->assertStringNotContainsString('event: message_stop', $body);
            $this->assertSame(10000, Wallet::balance($user->id));
            $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'release')->count());
        }
    }

    public function test_missing_token_counts_are_not_equivalent_to_explicit_zero_usage(): void
    {
        $user = $this->account(10000);
        $billing = app(UsageBillingService::class);
        $reservation = $billing->reserveApi($user->id, self::MODEL, 5000, 1000, 'api-zero-usage');
        $this->assertThrows(
            fn () => $billing->settleApi($user->id, self::MODEL, ['completion_tokens' => 0], $reservation),
            ValidationException::class,
        );
        $this->assertReservationHeld($user, 10000);

        $this->assertSame(0, $billing->settleApi($user->id, self::MODEL, [
            'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0,
        ], $reservation));
        $this->assertSame(10000, Wallet::balance($user->id));
        $this->assertSame(1, WalletTransaction::where('reference_id', 'api-zero-usage')->where('type', 'settlement')->count());
    }

    public function test_shortfall_can_settle_at_the_reserved_rates_after_funding_exactly_once(): void
    {
        $user = $this->account(10000);
        $billing = app(UsageBillingService::class);
        $reservation = $billing->reserveApi($user->id, self::MODEL, 5000, 1000, 'api-exact-recovery');
        $usage = ['prompt_tokens' => 20000, 'completion_tokens' => 1, 'total_tokens' => 20001];
        $this->assertThrows(fn () => $billing->settleApi($user->id, self::MODEL, $usage, $reservation), ValidationException::class);
        $this->assertReservationHeld($user, 10000);
        $this->assertSame(4000, Wallet::balance($user->id));

        Wallet::credit($user->id, 15000, 'Fixture topup');
        UsageRate::where('model', self::MODEL)->update(['price_usd' => 10]);
        $this->assertSame(20001, $billing->settleApi($user->id, self::MODEL, $usage, $reservation));
        $this->assertSame(20001, $billing->settleApi($user->id, self::MODEL, $usage, $reservation));
        $this->assertSame(4999, Wallet::balance($user->id));
        $this->assertSame(1, WalletTransaction::where('reference_id', 'api-exact-recovery')->where('type', 'settlement')->count());
        $this->assertSame(1, WalletTransaction::where('reference_id', 'api-exact-recovery')->where('type', 'settlement_adjustment')->count());
        $this->assertDatabaseMissing('wallet_transactions', ['reference_id' => 'api-exact-recovery', 'type' => 'release']);
    }
}
