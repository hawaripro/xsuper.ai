<?php

namespace Tests\Feature\Pricing;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Models\ModelCost;
use App\Services\FalProtocol;
use App\Services\Pricing\CostCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CostCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_reference_matching_filters_non_chat_and_free_models_and_prefers_direct_vendor(): void
    {
        $provider = $this->provider('openai');
        $ids = ['claude-sonnet-4-5-20250929', 'anthropic-claude-opus-4-7', 'deepseek-chat', 'gpt-4o-mini', 'black-forest-labs/flux-dev', 'free-model', 'claude-opus-4', 'fallback'];
        $models = collect($ids)->map(fn ($id) => $this->model($provider, $id));
        $row = fn ($id, $input = '0.000003', $output = '0.000015', $modality = 'text->text') => ['id' => $id, 'architecture' => ['modality' => $modality], 'pricing' => ['prompt' => $input, 'completion' => $output]];
        Http::fake([
            '*openrouter.ai*' => Http::response(['data' => [
                $row('anthropic/claude-sonnet-4.5'), $row('anthropic/claude-opus-4.7'), $row('deepseek/deepseek-chat'), $row('openai/gpt-4o-mini'),
                $row('black-forest-labs/flux-dev', '0.1', '0.1', 'text->image'), $row('free-model', '0', '0'),
                $row('gmi/anthropic/claude-opus-4', '0.00002'), $row('anthropic/claude-opus-4', '0.00001'),
            ]]),
            '*litellm*' => Http::response(['fallback' => ['mode' => 'chat', 'input_cost_per_token' => 0.000001, 'output_cost_per_token' => 0.000002]]),
        ]);
        app(CostCollector::class)->refresh($provider);
        foreach ($models->take(4) as $model) {
            $this->assertSame('estimate', $model->cost()->first()->status);
        }
        $this->assertSame('anthropic/claude-sonnet-4.5', $models[0]->cost()->first()->reference_id);
        $this->assertSame('unknown', $models[4]->cost()->first()->status);
        $this->assertSame('unknown', $models[5]->cost()->first()->status);
        $this->assertSame('anthropic/claude-opus-4', $models[6]->cost()->first()->reference_id);
        $this->assertEquals(10, $models[6]->cost()->first()->input_per_million);
        $this->assertEquals(1, $models[7]->cost()->first()->input_per_million);
        app(CostCollector::class)->refresh($provider);
        Http::assertSentCount(2);
    }

    public function test_runware_doc_token_rates_and_measured_media_are_collected_without_guessing(): void
    {
        $provider = $this->provider('openai', 'https://api.runware.ai/v1');
        $model = $this->model($provider, 'anthropic/claude-opus-4.7');
        Http::fake([
            '*/index.json' => Http::response(['models' => [['id' => 'anthropic/claude-opus-4.7', 'capabilities' => ['text-to-text']]]]),
            '*/schema.json' => Http::response(['info' => ['x-pricing' => ['rates' => [
                ['unit' => 'inputToken', 'amount' => 0.000003], ['unit' => 'outputToken', 'amount' => 0.000015], ['unit' => 'cachedInputToken', 'amount' => 0.0000003],
            ]]]]),
        ]);
        app(CostCollector::class)->refresh($provider);
        $cost = $model->cost()->first();
        $this->assertSame('ok', $cost->status);
        $this->assertEquals(3, $cost->input_per_million);
        $this->assertEquals(0.3, $cost->cache_read_per_million);
        $runware = $this->provider('runware');
        $media = $this->model($runware, 'runware:101@1', 'image');
        $this->revision($media, ['pricing' => ['catalog_unit' => 'generation', 'rates' => [], 'measured' => [['price' => 0.02], ['price' => 0.08]]]]);
        app(CostCollector::class)->refresh($runware);
        $this->assertSame('estimate', $media->cost()->first()->status);
        $this->assertEquals(0.08, $media->cost()->first()->unit_cost);
    }

    public function test_fal_maps_known_units_and_normalizes_fixed_video_tiers_per_selectable_second(): void
    {
        $provider = $this->provider('fal');
        $image = $this->model($provider, FalProtocol::IMAGE_DEV, 'image');
        $mp = $this->model($provider, FalProtocol::IMAGE_PRO, 'image');
        $video = $this->model($provider, FalProtocol::VIDEO, 'video');
        $avatar = $this->model($provider, FalProtocol::AVATAR, 'avatar');
        $gpu = $this->model($provider, 'fal-ai/gpu', 'image');
        Http::fake(['*api.fal.ai*' => Http::response(['prices' => [
            ['endpoint_id' => FalProtocol::IMAGE_DEV, 'unit_price' => 0.025, 'unit' => 'image', 'currency' => 'USD'],
            ['endpoint_id' => FalProtocol::IMAGE_PRO, 'unit_price' => 0.03, 'unit' => 'megapixel', 'currency' => 'USD'],
            ['endpoint_id' => FalProtocol::VIDEO, 'unit_price' => 0.2, 'unit' => 'video', 'currency' => 'USD'],
            ['endpoint_id' => FalProtocol::VIDEO_REFERENCE, 'unit_price' => 0.3, 'unit' => 'video', 'currency' => 'USD'],
            ['endpoint_id' => FalProtocol::VIDEO, 'unit_price' => 0.8, 'unit' => 'video', 'currency' => 'USD', 'pro' => true],
            ['endpoint_id' => FalProtocol::AVATAR, 'unit_price' => 0.02, 'unit' => 'second', 'currency' => 'USD'],
            ['endpoint_id' => 'fal-ai/gpu', 'unit_price' => 0.01, 'unit' => 'GPU-second', 'currency' => 'USD'],
        ]])]);
        app(CostCollector::class)->refresh($provider);
        $this->assertEquals(0.025, $image->cost()->first()->unit_cost);
        $this->assertGreaterThanOrEqual(0.03 * 1024 * 1792 / 1_000_000, (float) $mp->cost()->first()->unit_cost);
        $this->assertSame('second', $video->cost()->first()->unit);
        $this->assertEquals(0.2, $video->cost()->first()->unit_cost); // Pro tier: $0.80 / shortest 2s / Pro ×2
        $this->assertEquals(0.02, $avatar->cost()->first()->unit_cost);
        $this->assertSame('unknown', $gpu->cost()->first()->status);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Key test-key') && count(explode(',', $request['endpoint_id'])) <= 50);
    }

    public function test_kinovi_uses_credit_columns_at_sent_tiers_and_preserves_manual_rows(): void
    {
        $provider = $this->provider('kinovi');
        $video = $this->model($provider, 'seedance-20', 'video');
        $music = $this->model($provider, 'suno-music', 'audio');
        $avatar = $this->model($provider, 'minimax-h3-turbo-avatar-talking', 'avatar');
        $manual = $this->model($provider, 'gpt-image-2', 'image');
        ModelCost::create(['ai_model_profile_id' => $manual->id, 'source' => 'manual', 'currency' => 'credit', 'status' => 'ok', 'unit' => 'generation', 'unit_cost' => 99]);
        Http::fake([
            '*/seedance-20.md' => Http::response("## Pricing\nPriced per second of video.\n| | 480p | 720p | 1080p |\n|:--|--:|--:|--:|\n| per second | $0.07 · 15 cr | $0.18 · 40 cr | $0.42 · 90 cr |\n| per second, with reference video | $0.09 · 19 cr | $0.22 · 48 cr | $0.51 · 108 cr |\n## Response"),
            '*/suno-music.md' => Http::response("## Pricing\n| Variant | Public rate | Credits |\n| --- | --- | --- |\n| Suno Music · Default | 16 credits/request | 16/request |\n## About"),
            '*/minimax-h3-turbo-avatar-talking.md' => Http::response("## Pricing\n| Variant | Public rate | Credits |\n| --- | --- | --- |\n| Avatar · 480p | $0.0071/s | 1.56/s |\n| Avatar · 720p | $0.0107/s | 2.33/s |\n## About"),
        ]);
        app(CostCollector::class)->refresh($provider);
        $this->assertEquals(40, $video->cost()->first()->unit_cost);
        $this->assertSame('second', $video->cost()->first()->unit);
        $this->assertEquals(16, $music->cost()->first()->unit_cost);
        $this->assertSame('request', $music->cost()->first()->unit);
        $this->assertEquals(1.56, $avatar->cost()->first()->unit_cost);
        $this->assertEquals(99, $manual->cost()->first()->unit_cost);
    }

    private function provider(string $protocol, ?string $url = null): AiProviderProfile
    {
        return AiProviderProfile::create(['name' => $protocol, 'slug' => $protocol, 'protocol' => $protocol, 'base_url' => $url ?? 'https://example.com', 'api_key' => 'test-key', 'is_enabled' => true]);
    }

    private function model(AiProviderProfile $provider, string $id, string $category = 'chat'): AiModelProfile
    {
        return AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => $id, 'upstream_model_id' => $id, 'display_name' => $id, 'category' => $category, 'is_enabled' => true]);
    }

    private function revision(AiModelProfile $model, array $metadata): void
    {
        MediaCapabilityRevision::create(['ai_model_profile_id' => $model->id, 'operation' => 'text_to_image', 'revision' => 1, 'contract_version' => 2, 'status' => 'imported', 'source_metadata' => $metadata, 'definition' => [], 'source_hash' => str_repeat('a', 64)]);
    }
}
