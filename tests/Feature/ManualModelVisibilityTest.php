<?php

namespace Tests\Feature;

use App\Models\AiProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualModelVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manually_created_enabled_model_appears_in_the_chat_picker_immediately(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['permissions' => ['chat' => true], 'expires_at' => now()->addDays(7)]);
        $provider = AiProviderProfile::create([
            'name' => 'Manual QA', 'slug' => 'manual-qa', 'protocol' => 'openai',
            'base_url' => 'https://manual.test/v1', 'is_enabled' => true, 'status' => 'online',
        ]);

        $created = $this->actingAs($admin)->postJson('/api/admin/ai/models', [
            'provider_slug' => $provider->slug,
            'model_id' => 'manual-chat-model',
            'upstream_model_id' => 'manual-chat-model',
            'display_name' => 'Manual Chat Model',
            'category' => 'chat',
            'token_cost' => 1,
            'is_enabled' => true,
        ])->assertCreated();

        // The admin asserted the model exists; it must be available to features at once.
        $this->assertTrue($created->json('model.is_available'));

        $models = $this->actingAs($member)->getJson('/api/c/am')->assertOk()->json('models');
        $this->assertContains('manual-chat-model', array_column($models, 'id'));
    }

    public function test_a_manually_created_media_model_appears_in_its_studio_picker(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = AiProviderProfile::create([
            'name' => 'Manual Media', 'slug' => 'manual-media', 'protocol' => 'fal',
            'base_url' => 'https://fal.run', 'is_enabled' => true, 'status' => 'online',
        ]);

        $this->actingAs($admin)->postJson('/api/admin/ai/models', [
            'provider_slug' => $provider->slug,
            'model_id' => 'manual-image-model',
            'upstream_model_id' => 'fal-ai/flux/schnell',
            'display_name' => 'Manual Image Model',
            'category' => 'image',
            'token_cost' => 15,
            'is_enabled' => true,
        ])->assertCreated();

        $models = $this->actingAs($admin)->getJson('/api/images/models')->assertOk()->json('models');
        $this->assertContains('manual-image-model', array_column($models, 'id'));
    }
}
