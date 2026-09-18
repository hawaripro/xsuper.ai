<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardSearchContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_conversation_ids_cannot_leak_foreign_matches_and_wildcards_are_literal(): void
    {
        Http::preventStrayRequests();
        $member = User::factory()->create(['is_active' => true, 'permissions' => User::DEFAULT_PERMISSIONS]);
        $other = User::factory()->create();
        foreach ([[$member, 'shared', 'A harmless conversation'], [$other, 'shared', 'Private treasury notes'], [$member, 'literal', 'A literal %_! marker'], [$member, 'ordinary', 'An ordinary marker']] as [$owner, $conversation, $content]) {
            DB::table('chat_history')->insert([
                'user_id' => $owner->id, 'conversation_id' => $conversation, 'role' => 'user',
                'content' => $content, 'model' => 'search-fixture', 'created_at' => now(),
            ]);
        }
        $foreign = $this->actingAs($member)->getJson('/api/dashboard/search?q=treasury')->assertOk();
        $this->assertSame([], $this->results($foreign->json('groups'), 'conversation'));
        $literal = $this->getJson('/api/dashboard/search?'.http_build_query(['q' => '%_!']))->assertOk();
        $matches = $this->results($literal->json('groups'), 'conversation');
        $this->assertSame(['A literal %_! marker'], array_column($matches, 'title'));
        $this->assertStringContainsString('conversation=literal', $matches[0]['url']);
        Http::assertNothingSent();
    }

    public function test_search_never_advertises_unpublished_or_forbidden_models_or_admin_destinations(): void
    {
        Http::preventStrayRequests();
        $member = User::factory()->create(['is_active' => true, 'permissions' => User::DEFAULT_PERMISSIONS]);
        $provider = AiProviderProfile::create(['name' => 'Search provider', 'slug' => 'search-provider', 'protocol' => 'openai', 'base_url' => 'https://media.example.test/v1', 'api_key' => 'fixture-only-key', 'is_enabled' => true]);
        foreach ([['public-model', 'Original', true], ['private-model', 'Original', false], ['premium-model', 'Authentic', true]] as [$id, $tier, $enabled]) {
            AiModelProfile::create([
                'provider_id' => $provider->id, 'model_id' => $id, 'upstream_model_id' => $id,
                'display_name' => 'Distinctneedle '.$id, 'category' => 'chat', 'tier' => $tier,
                'is_enabled' => $enabled, 'is_available' => true,
            ]);
        }
        $response = $this->actingAs($member)->getJson('/api/dashboard/search?q=Distinctneedle')->assertOk();
        $this->assertSame(['Distinctneedle public-model'], array_column($this->results($response->json('groups'), 'model'), 'title'));
        $destinations = $this->getJson('/api/dashboard/search')->assertOk()->json('groups');
        foreach ($destinations as $group) {
            foreach ($group['results'] as $result) {
                $this->assertFalse(str_starts_with($result['url'], '/admin'));
            }
        }
        $member->update(['permissions' => ['chat' => false, 'chat_history' => false, 'model_original' => true]]);
        $denied = $this->getJson('/api/dashboard/search?q=Distinctneedle')->assertOk();
        $this->assertSame([], $this->results($denied->json('groups'), 'model'));
        Http::assertNothingSent();
    }

    public function test_job_identifier_fragments_match_only_owned_media_and_tool_jobs(): void
    {
        Http::preventStrayRequests();
        $member = User::factory()->create(['is_active' => true, 'permissions' => User::DEFAULT_PERMISSIONS]);
        $other = User::factory()->create(['is_active' => true, 'permissions' => User::DEFAULT_PERMISSIONS]);
        $owned = '7d1f3c2a-5b6e-4f80-9a1b-2c3d4e5f6a7b';
        $foreign = '7d1f3c2a-1111-4f80-9a1b-2c3d4e5f6a7b';
        foreach ([[$member, $owned], [$other, $foreign]] as [$owner, $jobId]) {
            DB::table('image_jobs')->insert(['user_id' => $owner->id, 'job_id' => $jobId, 'model' => 'gpt-image-1', 'prompt' => 'Unrelated prompt', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('media_tool_jobs')->insert(['user_id' => $owner->id, 'job_id' => $jobId, 'kind' => 'convert', 'status' => 'completed', 'title' => 'Unrelated title', 'input_name' => 'clip.mov', 'format' => 'mp4', 'created_at' => now(), 'updated_at' => now()]);
        }
        $groups = $this->actingAs($member)->getJson('/api/dashboard/search?q=7D1F3C2A')->assertOk()->json('groups');
        $this->assertSame(['image:'.$owned], array_column($this->results($groups, 'image'), 'id'));
        $this->assertSame(['convert:'.$owned], array_column($this->results($groups, 'convert'), 'id'));
        Http::assertNothingSent();
    }

    private function results(array $groups, string $type): array
    {
        return array_values(array_filter(array_merge([], ...array_column($groups, 'results')), static fn (array $row): bool => $row['type'] === $type));
    }
}
