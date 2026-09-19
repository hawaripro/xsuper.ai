<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\FalCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FalCatalogModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_cheap_image_models_appear_in_the_image_picker(): void
    {
        $this->seed(FalCatalogSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);

        $ids = array_column(
            $this->actingAs($admin)->getJson('/api/images/models')->assertOk()->json('models'),
            'id',
        );

        foreach (['flux-dev', 'fast-sdxl', 'sana'] as $model) {
            $this->assertContains($model, $ids, "Image model {$model} should be selectable.");
        }
    }

    public function test_seeded_cheap_chat_models_appear_in_the_chat_picker_with_rates(): void
    {
        $this->seed(FalCatalogSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);

        $ids = array_column(
            $this->actingAs($admin)->getJson('/api/c/am')->assertOk()->json('models'),
            'id',
        );

        foreach (['gemini-flash-lite', 'gpt-4o-mini', 'deepseek-chat'] as $model) {
            $this->assertContains($model, $ids, "Chat model {$model} should be selectable.");
        }

        // Auto-priced: every chat model carries input + output token rates.
        $this->assertDatabaseHas('usage_rates', ['model' => 'gemini-flash-lite', 'meter' => 'input_tokens', 'is_active' => true]);
        $this->assertDatabaseHas('usage_rates', ['model' => 'gemini-flash-lite', 'meter' => 'output_tokens', 'is_active' => true]);
    }
}
