<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\AiProxyService;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AnthropicApiCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBilling(): void
    {
        $billing = Mockery::mock(UsageBillingService::class);
        $billing->shouldReceive('estimateInputTokens')->andReturn(10);
        $billing->shouldReceive('reserveApi')->andReturn(['reservation' => 'test']);
        $billing->shouldReceive('settleApi')->andReturn(1000);
        $this->app->instance(UsageBillingService::class, $billing);
    }

    public function test_messages_endpoint_translates_openai_response_to_anthropic_shape(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $key = ApiKey::generate($admin->id, 'Test', ['rate_limit' => 60]);

        $proxy = Mockery::mock(AiProxyService::class);
        $proxy->shouldReceive('getModels')->andReturn([['id' => 'claude-test', 'name' => 'Claude Test']]);
        $proxy->shouldReceive('chatCompletion')->once()->andReturn([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello there'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $this->app->instance(AiProxyService::class, $proxy);
        $this->fakeBilling();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$key->plainKey])
            ->postJson('/v1/messages', [
                'model' => 'claude-test',
                'max_tokens' => 100,
                'system' => 'You are helpful.',
                'messages' => [['role' => 'user', 'content' => 'Hi']],
            ]);

        $response->assertOk();
        $response->assertJson([
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-test',
            'content' => [['type' => 'text', 'text' => 'Hello there']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
        $this->assertStringStartsWith('msg_', $response->json('id'));
    }

    public function test_messages_endpoint_rejects_unknown_model(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $key = ApiKey::generate($admin->id, 'Test', ['rate_limit' => 60]);

        $proxy = Mockery::mock(AiProxyService::class);
        $proxy->shouldReceive('getModels')->andReturn([['id' => 'claude-test']]);
        $proxy->shouldReceive('chatCompletion')->never();
        $this->app->instance(AiProxyService::class, $proxy);
        $this->fakeBilling();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$key->plainKey])
            ->postJson('/v1/messages', [
                'model' => 'not-a-real-model',
                'max_tokens' => 100,
                'messages' => [['role' => 'user', 'content' => 'Hi']],
            ]);

        $response->assertStatus(403);
        $response->assertJson(['type' => 'error', 'error' => ['type' => 'permission_error']]);
    }

    public function test_messages_endpoint_requires_valid_api_key(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer invalid-key'])
            ->postJson('/v1/messages', [
                'model' => 'claude-test',
                'max_tokens' => 100,
                'messages' => [['role' => 'user', 'content' => 'Hi']],
            ]);

        $response->assertStatus(401);
    }
}
