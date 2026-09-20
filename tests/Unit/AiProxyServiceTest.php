<?php

namespace Tests\Unit;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Services\AiProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProxyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_model_listing_removes_auto_and_internal_identifiers(): void
    {
        $provider = AiProviderProfile::create(['slug' => 'catalog', 'name' => 'Catalog provider', 'is_enabled' => true]);
        foreach ([
            ['model_id' => 'au'.'to', 'display_name' => 'Auto Router'],
            ['model_id' => 'eno'.'wx-secret', 'display_name' => 'Internal Model'],
            ['model_id' => 'innocent-id', 'display_name' => 'Auto Router'],
            ['model_id' => 'other-id', 'display_name' => 'Visible', 'provider_name' => 'eno'.'wx internal'],
            ['model_id' => 'gpt-visible', 'display_name' => 'Visible'],
        ] as $metadata) {
            AiModelProfile::create([...$metadata, 'provider_id' => $provider->id, 'category' => 'chat', 'is_enabled' => true, 'is_available' => true]);
        }

        $service = app(AiProxyService::class);

        $this->assertSame(['gpt-visible'], array_column($service->getModels(), 'id'));
        $this->assertSame(['gpt-visible'], array_column($service->getAllModels(), 'id'));
        $this->assertSame(['gpt-visible'], array_column($service->getAllModelsFiltered(), 'id'));
    }

    public function test_empty_catalog_does_not_restore_environment_or_marketing_models(): void
    {
        config(['services.ai_proxy.url' => 'https://proxy.test', 'services.ai_proxy.key' => 'key']);
        Http::fake(['*' => Http::response(['data' => [['id' => 'unwanted-fallback', 'category' => 'chat']]])]);

        $this->assertSame([], app(AiProxyService::class)->getAllModels());
        $this->get('/en/models')->assertOk()->assertViewHas('models', []);
        Http::assertNothingSent();
    }
}
