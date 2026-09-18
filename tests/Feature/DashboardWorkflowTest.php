<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_keeps_the_amount_snapshotted_at_checkout_when_pricing_changes(): void
    {
        $member = User::factory()->create();

        $checkout = $this->actingAs($member)
            ->postJson('/api/period/checkout', ['package' => '1_week'])
            ->assertCreated()
            ->assertJsonPath('checkout.amount_idr', 20000)
            ->json('checkout');

        // Admin publishes a price change while the QR is still valid.
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->putJson('/api/pricing/durations/1_week', [
                'price_idr' => 99000,
                'price_usd' => 6.50,
                'is_active' => true,
            ])
            ->assertOk();

        $this->actingAs($member)
            ->postJson('/api/period/order', [
                'package' => '1_week',
                'payment_reference' => $checkout['payment_reference'],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('duration_orders', [
            'user_id' => $member->id,
            'package' => '1_week',
            'price' => 20000,
            'payment_reference' => $checkout['payment_reference'],
        ]);
    }

    public function test_member_with_no_model_tiers_sees_no_models_and_cannot_stream(): void
    {
        $member = User::factory()->create([
            'permissions' => [
                ...User::DEFAULT_PERMISSIONS,
                'model_original' => false,
                'model_authentic' => false,
            ],
        ]);

        Http::fake(['*' => Http::response(['data' => [
            ['id' => 'chat-standard', 'name' => 'Chat Standard', 'category' => 'chat', 'tier' => 'Standard'],
            ['id' => 'chat-max', 'name' => 'Chat Max', 'category' => 'chat', 'tier' => 'MAX'],
        ]])]);

        $provider = AiProviderProfile::create([
            'slug' => 'ai-proxy',
            'name' => 'AI Proxy',
            'status' => 'healthy',
            'is_enabled' => true,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'curated-standard',
            'display_name' => 'Curated Standard',
            'provider_name' => 'AI Proxy',
            'category' => 'chat',
            'tier' => 'Original',
            'capabilities' => ['chat'],
            'input_modalities' => ['text'],
            'output_modalities' => ['text'],
            'is_enabled' => true,
            'is_available' => true,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'curated-authentic',
            'display_name' => 'Curated Authentic',
            'provider_name' => 'AI Proxy',
            'category' => 'chat',
            'tier' => 'Authentic',
            'capabilities' => ['chat'],
            'input_modalities' => ['text'],
            'output_modalities' => ['text'],
            'is_enabled' => true,
            'is_available' => true,
        ]);

        $models = collect($this->actingAs($member)->getJson('/api/c/am')->assertOk()->json('models'));
        $this->assertCount(0, $models);

        $this->actingAs($member)->postJson('/api/c/s', [
            'model' => 'chat-standard',
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ])->assertForbidden();
    }

    public function test_curated_models_keep_tier_labels_and_tier_gates(): void
    {
        $standardOnly = User::factory()->create();
        $provider = AiProviderProfile::create([
            'slug' => 'ai-proxy',
            'name' => 'AI Proxy',
            'status' => 'healthy',
            'is_enabled' => true,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'authentic-db-model',
            'display_name' => 'Authentic DB Model',
            'provider_name' => 'AI Proxy',
            'category' => 'chat',
            'tier' => 'Authentic',
            'capabilities' => ['chat'],
            'input_modalities' => ['text'],
            'output_modalities' => ['text'],
            'is_enabled' => true,
            'is_available' => true,
        ]);

        Http::fake(['*' => Http::response(['data' => []])]);

        $standardModels = collect(
            $this->actingAs($standardOnly)->getJson('/api/c/am')->assertOk()->json('models')
        );
        $this->assertFalse($standardModels->contains('id', 'authentic-db-model'));

        $authentic = User::factory()->create([
            'permissions' => [...User::DEFAULT_PERMISSIONS, 'model_authentic' => true],
        ]);
        $authenticModels = collect(
            $this->actingAs($authentic)->getJson('/api/c/am')->assertOk()->json('models')
        );
        $model = $authenticModels->firstWhere('id', 'authentic-db-model');
        $this->assertNotNull($model);
        $this->assertSame('Authentic', $model['tier']);
    }

    public function test_chat_history_titles_stay_with_the_owner_for_shared_conversation_ids(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $sharedConversationId = 'conv-shared-001';

        foreach ([[$owner, 'Owner private title'], [$intruder, 'Intruder private title']] as [$user, $title]) {
            DB::table('chat_history')->insert([
                'user_id' => $user->id,
                'conversation_id' => $sharedConversationId,
                'role' => 'user',
                'content' => $title,
                'model' => 'chat-alpha',
                'created_at' => now(),
            ]);
        }

        $ownerHistory = collect(
            $this->actingAs($owner)->getJson('/api/c/h')->assertOk()->json('conversations')
        );
        $this->assertSame('Owner private title', $ownerHistory->firstWhere('conversation_id', $sharedConversationId)['title']);

        $intruderHistory = collect(
            $this->actingAs($intruder)->getJson('/api/c/h')->assertOk()->json('conversations')
        );
        $this->assertSame('Intruder private title', $intruderHistory->firstWhere('conversation_id', $sharedConversationId)['title']);

        $this->actingAs($intruder)->getJson('/api/c/h/'.$sharedConversationId)
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.content', 'Intruder private title');
    }

    public function test_dashboard_activity_counts_real_chat_history_conversations(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        foreach ([['conv-one', 'First real chat'], ['conv-two', 'Second real chat']] as [$conversationId, $title]) {
            DB::table('chat_history')->insert([
                'user_id' => $member->id,
                'conversation_id' => $conversationId,
                'role' => 'user',
                'content' => $title,
                'model' => 'chat-alpha',
                'created_at' => now(),
            ]);
        }
        DB::table('chat_history')->insert([
            'user_id' => $other->id,
            'conversation_id' => 'conv-foreign',
            'role' => 'user',
            'content' => 'Not mine',
            'model' => 'chat-alpha',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($member)->getJson('/api/dashboard')->assertOk();
        $response->assertJsonPath('activity.conversation_count', 2);
        $this->assertNotNull($response->json('activity.recent.0.occurred_at'));

        $recent = collect($response->json('activity.recent'));
        $this->assertTrue($recent->contains(fn ($item) => $item['type'] === 'conversation' && $item['title'] === 'First real chat'));
        $this->assertFalse($recent->contains(fn ($item) => $item['title'] === 'Not mine'));
    }

    public function test_disabling_the_last_curated_model_does_not_republish_upstream_fallbacks(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['id' => 'withdrawn-model', 'name' => 'Withdrawn upstream model', 'category' => 'chat', 'tier' => 'Standard'],
        ]])]);
        $provider = AiProviderProfile::create(['slug' => 'curated', 'name' => 'Curated Provider', 'is_enabled' => true]);
        $model = AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => 'withdrawn-model', 'display_name' => 'Withdrawn curated model',
            'category' => 'chat', 'is_enabled' => true, 'is_available' => true,
        ]);
        $this->get('/en/models')->assertOk()->assertSee('Withdrawn curated model');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson("/api/admin/ai/models/{$model->id}", ['is_enabled' => false])->assertOk();
        $this->get('/en/models')->assertOk()->assertDontSee('Withdrawn upstream model');
        Cache::forget('public-model-catalog-v3');
        Http::fake(['*' => Http::response(['data' => []])]);
        $this->get('/models')->assertOk()->assertViewHas('models', []);
    }

    public function test_enabling_a_draft_model_activates_its_saved_rates_for_real_billing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $provider = AiProviderProfile::create(['slug' => 'draft-provider', 'name' => 'Draft Provider', 'is_enabled' => true]);
        $model = $this->postJson('/api/admin/ai/models', [
            'model_id' => 'draft-priced-model', 'display_name' => 'Draft priced model',
            'provider_slug' => $provider->slug,
            'category' => 'chat', 'tier' => 'Original', 'is_enabled' => false,
            'rates' => ['input_tokens' => 0.4, 'output_tokens' => 1.2],
        ])->assertCreated()->json('model');
        $this->patchJson('/api/admin/ai/models/'.$model['id'], ['is_enabled' => true])->assertOk();
        $this->assertSame(1_600_000, app(UsageBillingService::class)
            ->estimateApiMaximum('draft-priced-model', 1_000_000, 1_000_000));
        $this->get('/en/models')->assertOk()->assertSee('$0.400')->assertSee('$1.200');
        $this->patchJson('/api/admin/ai/models/'.$model['id'], ['is_enabled' => false])->assertOk();
        $this->assertDatabaseMissing('usage_rates', ['model' => 'draft-priced-model', 'is_active' => true]);
    }

    public function test_curated_chat_publication_and_provider_gates_apply_to_list_and_send(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['id' => 'gated-chat', 'name' => 'Gated Chat', 'category' => 'chat', 'tier' => 'Standard'],
        ]])]);
        $provider = AiProviderProfile::create(['slug' => 'gate', 'name' => 'Gate Provider', 'is_enabled' => true]);
        $profile = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'gated-chat', 'display_name' => 'Gated Chat',
            'category' => 'chat', 'tier' => 'Original', 'is_enabled' => false, 'is_available' => true,
        ]);
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/c/am')->assertOk()->assertJsonCount(0, 'models');
        $this->getJson('/api/c/m')->assertOk()->assertJsonCount(0, 'models');
        $this->postJson('/api/c/s', [
            'model' => 'gated-chat', 'messages' => [['role' => 'user', 'content' => 'Do not send this.']],
        ])->assertForbidden();
        $profile->update(['is_enabled' => true]);
        $provider->update(['is_enabled' => false]);
        $this->getJson('/api/c/am')->assertOk()->assertJsonCount(0, 'models');
        $this->postJson('/api/c/s', [
            'model' => 'gated-chat', 'messages' => [['role' => 'user', 'content' => 'Provider disabled.']],
        ])->assertForbidden();
    }

    public function test_available_curated_model_is_authorized_when_live_listing_is_unavailable(): void
    {
        config()->set('services.ai_proxy.url', 'http://127.0.0.1:1');
        Http::fake(['*' => Http::response(['data' => []])]);
        $provider = AiProviderProfile::create(['slug' => 'last-known', 'name' => 'Last Known Provider', 'is_enabled' => true]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'last-known-chat', 'display_name' => 'Last Known Chat',
            'category' => 'chat', 'tier' => 'Original', 'is_enabled' => true, 'is_available' => true,
        ]);
        $member = User::factory()->create();
        $this->actingAs($member)->getJson('/api/c/am')->assertOk()->assertJsonPath('models.0.id', 'last-known-chat');
        $this->postJson('/api/c/s', [
            'model' => 'last-known-chat', 'conversation_id' => 'curated-send',
            'messages' => [['role' => 'user', 'content' => 'A real authorized request.']],
        ])->assertOk();
        $this->assertDatabaseHas('chat_history', [
            'user_id' => $member->id, 'conversation_id' => 'curated-send', 'content' => 'A real authorized request.',
        ]);
    }
}
