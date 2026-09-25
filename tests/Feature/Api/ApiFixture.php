<?php

namespace Tests\Feature\Api;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ApiKey;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AiProviderEndpoint;
use Illuminate\Support\Facades\Http;

trait ApiFixture
{
    protected function apiFixture(string $protocol = 'openai'): array
    {
        Http::preventStrayRequests();
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint {
            protected function resolveAddresses(string $host): array { return ['93.184.216.34']; }
        });
        $provider = AiProviderProfile::create([
            'slug' => 'private-provider', 'name' => 'Private provider', 'protocol' => $protocol,
            'base_url' => 'https://provider.example.test/v1', 'api_key' => 'upstream-secret', 'is_enabled' => true,
        ]);
        $model = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'public-model', 'upstream_model_id' => 'private-model',
            'display_name' => 'Coding model', 'category' => 'chat', 'context_window' => 200000,
            'is_enabled' => true, 'is_available' => true,
        ]);
        foreach (['input_tokens' => 2, 'output_tokens' => 4, 'cache_read' => 0.5, 'cache_write' => 3] as $meter => $price) {
            UsageRate::create(['service' => 'api', 'model' => $model->model_id, 'meter' => $meter, 'label' => $meter,
                'unit' => '1M tokens', 'price_usd' => $price, 'price_idr' => $price * 16000, 'is_active' => true]);
        }
        $user = User::factory()->create(['expires_at' => now()->subDay(), 'permissions' => ['ai_api' => true]]);
        Wallet::credit($user->id, 1_000_000, 'Fixture');
        $key = ApiKey::generate($user->id, 'Coding');

        return [$user, $key, $model, $provider];
    }

    protected function openAiAnswer(array $usage = []): array
    {
        return ['id' => 'chatcmpl-fixture', 'object' => 'chat.completion', 'model' => 'private-model',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Done'], 'finish_reason' => 'stop']],
            'usage' => $usage ?: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]];
    }

    protected function eventStream(array $events, bool $openAi = false): string
    {
        return implode('', array_map(static fn (array $event): string => ($openAi ? '' : 'event: '.$event['type']."\n")
            .'data: '.json_encode($event, JSON_UNESCAPED_SLASHES)."\n\n", $events)).($openAi ? "data: [DONE]\n\n" : '');
    }
}
