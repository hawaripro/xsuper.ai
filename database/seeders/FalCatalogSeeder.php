<?php

namespace Database\Seeders;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\UsageRate;
use Illuminate\Database\Seeder;

/**
 * Seeds a curated set of cheap, ready-to-use fal.ai models across categories.
 *
 * Idempotent (updateOrCreate) so it can be run against an existing database
 * without disturbing other data: `php artisan db:seed --class=FalCatalogSeeder`.
 *
 * Image models route through fal endpoints (must exist in FalProtocol::MEDIA_MODELS);
 * chat models route through the fal openrouter/router endpoint by upstream id.
 * Retail prices are seeded automatically so no per-model manual entry is needed.
 */
class FalCatalogSeeder extends Seeder
{
    private const IDR_PER_USD = 16000;

    public function run(): void
    {
        $provider = AiProviderProfile::updateOrCreate(
            ['slug' => 'fal-cheap'],
            [
                'name' => 'fal.ai Value Models',
                'protocol' => 'fal',
                'base_url' => 'https://fal.run',
                'is_enabled' => true,
                'status' => 'healthy',
                'capabilities' => ['chat', 'image'],
                'last_checked_at' => now(),
                'last_error' => null,
            ],
        );

        // [model_id, upstream, display_name, vendor, category, tier, token_cost, in_usd, out_usd, badges, sort]
        $models = [
            // Image — share the flux image_size schema, priced per image (token_cost).
            ['flux-dev', 'fal-ai/flux/dev', 'FLUX.1 Dev', 'fal.ai', 'image', 'Original', 12, null, null, ['Image', 'Hemat'], 110],
            ['fast-sdxl', 'fal-ai/fast-sdxl', 'Fast SDXL', 'fal.ai', 'image', 'Original', 8, null, null, ['Image', 'Cepat'], 120],
            ['sana', 'fal-ai/sana', 'SANA', 'fal.ai', 'image', 'Original', 6, null, null, ['Image', 'Termurah'], 130],
            // LLM — routed via fal openrouter/router by upstream id, priced per 1M tokens.
            ['gemini-flash-lite', 'google/gemini-2.0-flash-lite-001', 'Gemini Flash Lite', 'Google', 'chat', 'Original', 1, 0.10, 0.40, ['Chat', 'Hemat'], 210],
            ['llama-3-2-3b', 'meta-llama/llama-3.2-3b-instruct', 'Llama 3.2 3B', 'Meta', 'chat', 'Original', 1, 0.05, 0.10, ['Chat', 'Termurah'], 220],
            ['llama-3-1-8b', 'meta-llama/llama-3.1-8b-instruct', 'Llama 3.1 8B', 'Meta', 'chat', 'Original', 1, 0.08, 0.16, ['Chat'], 230],
            ['mistral-7b', 'mistralai/mistral-7b-instruct', 'Mistral 7B', 'Mistral', 'chat', 'Original', 1, 0.10, 0.20, ['Chat'], 240],
            ['gpt-4o-mini', 'openai/gpt-4o-mini', 'GPT-4o mini', 'OpenAI', 'chat', 'Original', 1, 0.20, 0.80, ['Chat', 'Populer'], 250],
            ['claude-3-5-haiku', 'anthropic/claude-3.5-haiku', 'Claude 3.5 Haiku', 'Anthropic', 'chat', 'Original', 1, 1.00, 4.00, ['Chat'], 260],
            ['deepseek-chat', 'deepseek/deepseek-chat', 'DeepSeek Chat', 'DeepSeek', 'chat', 'Original', 1, 0.15, 0.60, ['Chat', 'Reasoning'], 270],
        ];

        foreach ($models as [$modelId, $upstream, $name, $vendor, $category, $tier, $tokenCost, $inUsd, $outUsd, $badges, $sort]) {
            $isChat = $category === 'chat';
            $profile = AiModelProfile::updateOrCreate(
                ['model_id' => $modelId],
                [
                    'provider_id' => $provider->id,
                    'upstream_model_id' => $upstream,
                    'display_name' => $name,
                    'provider_name' => $vendor,
                    'category' => $category,
                    'tier' => $tier,
                    'token_cost' => $tokenCost,
                    'description_id' => $isChat
                        ? 'Model chat hemat biaya untuk tugas harian.'
                        : 'Generator gambar cepat dan terjangkau.',
                    'description_en' => $isChat
                        ? 'Cost-efficient chat model for everyday tasks.'
                        : 'Fast, affordable image generator.',
                    'logo_url' => '/xsuper-mark.png',
                    'capabilities' => $isChat ? ['chat'] : ['text-to-image'],
                    'input_modalities' => ['text'],
                    'output_modalities' => $isChat ? ['text'] : ['image'],
                    'badges' => $badges,
                    'context_window' => $isChat ? 128000 : null,
                    'max_output_tokens' => $isChat ? 8192 : null,
                    'sort_order' => $sort,
                    'is_enabled' => true,
                    'is_available' => true,
                    'last_seen_at' => now(),
                ],
            );

            if ($isChat) {
                $this->autoRate($profile, 'input_tokens', $inUsd, $sort);
                $this->autoRate($profile, 'output_tokens', $outUsd, $sort);
            }
        }
    }

    private function autoRate(AiModelProfile $profile, string $meter, float $priceUsd, int $sort): void
    {
        UsageRate::updateOrCreate(
            ['service' => 'api', 'meter' => $meter, 'model' => $profile->model_id],
            [
                'label' => $profile->display_name.' '.str_replace('_', ' ', $meter),
                'unit' => '1M tokens',
                'price_usd' => $priceUsd,
                'price_idr' => round($priceUsd * self::IDR_PER_USD, 6),
                'is_active' => true,
                'sort_order' => $sort,
            ],
        );
    }
}
