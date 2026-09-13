<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatModelSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_requires_an_explicit_model(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->postJson('/api/c/s', [
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ])->assertUnprocessable()->assertJsonValidationErrors('model');
    }

    public function test_removed_router_model_cannot_be_selected(): void
    {
        config(['services.ai_proxy.url' => 'https://proxy.test', 'services.ai_proxy.key' => 'key']);
        Http::fake([
            'https://proxy.test/v1/models' => Http::response(['data' => [
                ['id' => 'au'.'to', 'name' => 'Auto Router', 'category' => 'chat', 'tier' => 'Standard'],
                ['id' => 'gpt-visible', 'name' => 'Visible', 'category' => 'chat', 'tier' => 'Standard'],
            ]]),
        ]);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->postJson('/api/c/s', [
            'model' => 'au'.'to',
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ])->assertForbidden();
    }
}
