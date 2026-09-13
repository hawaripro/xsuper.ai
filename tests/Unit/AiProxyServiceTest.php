<?php

namespace Tests\Unit;

use App\Services\AiProxyService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProxyServiceTest extends TestCase
{
    public function test_every_model_listing_removes_auto_and_internal_identifiers(): void
    {
        config(['services.ai_proxy.url' => 'https://proxy.test', 'services.ai_proxy.key' => 'key']);
        Http::fake([
            'https://proxy.test/v1/models' => Http::response(['data' => [
                ['id' => 'au'.'to', 'name' => 'Auto Router', 'category' => 'chat', 'tier' => 'Standard'],
                ['id' => 'eno'.'wx-secret', 'name' => 'Internal Model', 'category' => 'chat', 'tier' => 'Standard'],
                ['id' => 'innocent-id', 'name' => 'Auto Router', 'category' => 'chat', 'tier' => 'Standard'],
                ['id' => 'other-id', 'name' => 'Visible', 'owned_by' => 'eno'.'wx internal', 'category' => 'chat', 'tier' => 'Standard'],
                ['id' => 'gpt-visible', 'name' => 'Visible', 'category' => 'chat', 'tier' => 'Standard'],
            ]]),
        ]);

        $service = app(AiProxyService::class);

        $this->assertSame(['gpt-visible'], array_column($service->getModels(), 'id'));
        $this->assertSame(['gpt-visible'], array_column($service->getAllModels(), 'id'));
        $this->assertSame(['gpt-visible'], array_column($service->getAllModelsFiltered(), 'id'));
    }

    public function test_completion_methods_require_an_explicit_model(): void
    {
        $reflection = new \ReflectionClass(AiProxyService::class);

        $this->assertFalse($reflection->getMethod('chatCompletion')->getParameters()[1]->isDefaultValueAvailable());
        $this->assertFalse($reflection->getMethod('chatCompletionStream')->getParameters()[1]->isDefaultValueAvailable());
    }
}
