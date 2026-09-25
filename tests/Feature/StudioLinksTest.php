<?php

namespace Tests\Feature;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\MediaAsset;
use App\Models\Notification;
use App\Models\User;
use App\Models\VideoJob;
use App\Services\FalProtocol;
use App\Services\GeneratedAudioStore;
use App\Services\GeneratedVideoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every server link into the media studios opens the unified /studio page: kind first, then the
 * same native job id (and audio track) the legacy studio pages used.
 */
class StudioLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_search_destinations_open_the_studio_filtered_to_each_permitted_kind(): void
    {
        $member = $this->member();

        $this->assertSame(['/chat', '/studio', '/studio?kind=image', '/studio?kind=audio', '/studio?kind=model3d'],
            $this->urls($this->actingAs($member)->getJson('/api/dashboard/search')->assertOk()->json('groups'), 'studios'));

        $member->update(['permissions' => [...User::DEFAULT_PERMISSIONS, 'image_generator' => false, 'audio_generator' => false]]);
        $this->assertSame(['/chat'], $this->urls($this->getJson('/api/dashboard/search')->assertOk()->json('groups'), 'studios'));
        Http::assertNothingSent();
    }

    public function test_search_opens_owned_jobs_and_models_in_their_studio_kind(): void
    {
        $member = $this->member(['video_generator' => true]);
        $image = $this->imageJob($member, 'completed');
        $video = $this->videoJob($member, 'prompt', 'completed');
        $avatar = $this->videoJob($member, 'avatar', 'completed');
        $provider = AiProviderProfile::create(['slug' => 'fal', 'name' => 'fal', 'protocol' => 'fal', 'base_url' => 'https://fal.run', 'api_key' => 'fixture-only', 'is_enabled' => true]);
        foreach ([[FalProtocol::VIDEO, 'video'], [FalProtocol::AVATAR, 'avatar']] as [$modelId, $category]) {
            AiModelProfile::create([
                'provider_id' => $provider->id, 'model_id' => $modelId, 'upstream_model_id' => $modelId,
                'display_name' => 'Studiolink '.$category.' model', 'category' => $category, 'token_cost' => 200, 'is_enabled' => true, 'is_available' => true,
            ]);
        }

        $groups = $this->actingAs($member)->getJson('/api/dashboard/search?q=studiolink')->assertOk()->json('groups');

        $this->assertSame(['/studio?kind=image&job='.$image->job_id], $this->urls($groups, 'image'));
        $this->assertSame(['/studio?kind=video&job='.$video->job_id], $this->urls($groups, 'video'));
        $this->assertSame(['/studio?kind=avatar&job='.$avatar->job_id], $this->urls($groups, 'avatar'));
        // An avatar model renders video, yet opens the avatar studio.
        $this->assertSame([
            '/studio?kind=video&model='.rawurlencode(FalProtocol::VIDEO),
            '/studio?kind=avatar&model='.rawurlencode(FalProtocol::AVATAR),
        ], $this->urls($groups, 'models'));
        Http::assertNothingSent();
    }

    public function test_library_items_open_their_job_and_track_in_the_studio(): void
    {
        $member = $this->member(['video_generator' => true]);
        $image = $this->imageJob($member, 'completed');
        $avatar = $this->videoJob($member, 'avatar', 'completed');
        $audio = $this->audioJob($member, 'completed');
        $portrait = MediaAsset::create([
            'user_id' => $member->id, 'media_type' => 'image', 'role' => 'avatar_photo', 'storage_disk' => 'local',
            'storage_path' => 'media-assets/portrait.png', 'size_bytes' => 9, 'mime' => 'image/png',
            'signature_ok' => true, 'retention_status' => 'active',
        ]);
        Storage::disk('local')->put('media-assets/portrait.png', 'png-bytes');

        $items = $this->actingAs($member)->getJson('/api/library?per_page=60')->assertOk()->json('items');

        $this->assertEquals([
            'image:'.$image->job_id.':0' => '/studio?kind=image&job='.$image->job_id,
            'avatar:'.$avatar->job_id => '/studio?kind=avatar&job='.$avatar->job_id,
            'audio:'.$audio->job_id.':0' => '/studio?kind=audio&job='.$audio->job_id.'&track=0',
            'audio:'.$audio->job_id.':1' => '/studio?kind=audio&job='.$audio->job_id.'&track=1',
            'reference:'.$portrait->id => '/studio?kind=avatar',
        ], array_column($items, 'page_url', 'id'));
        Http::assertNothingSent();
    }

    public function test_finished_media_notifications_open_the_job_in_its_studio_kind(): void
    {
        $member = $this->member(['video_generator' => true]);
        $image = $this->imageJob($member, 'processing');
        $video = $this->videoJob($member, 'prompt', 'processing');
        $avatar = $this->videoJob($member, 'avatar', 'processing');
        $audio = $this->audioJob($member, 'processing');

        $image->update(['status' => 'completed']);
        $video->update(['status' => 'failed']);
        $avatar->update(['status' => 'completed']);
        $audio->update(['status' => 'completed']);

        $this->assertSame([
            '/studio?kind=image&job='.$image->job_id,
            '/studio?kind=video&job='.$video->job_id,
            '/studio?kind=avatar&job='.$avatar->job_id,
            '/studio?kind=audio&job='.$audio->job_id,
        ], Notification::query()->where('user_id', $member->id)->where('kind', 'media')->orderBy('id')->pluck('action_url')->all());
    }

    public function test_dashboard_video_action_opens_the_video_studio(): void
    {
        $member = $this->member(['video_generator' => true]);

        $actions = $this->actingAs($member)->getJson('/api/dashboard')->assertOk()->json('actions');

        $this->assertSame('/studio?kind=video', collect($actions)->firstWhere('key', 'video')['href'] ?? null);
    }

    public function test_chat_image_tool_links_to_the_image_studio_only_when_permitted(): void
    {
        $provider = AiProviderProfile::create([
            'slug' => 'studio-link-chat', 'name' => 'Chat', 'protocol' => 'openai',
            'base_url' => 'https://chat.example.test/v1', 'api_key' => 'fixture-only', 'is_enabled' => true,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'studio-link-chat', 'upstream_model_id' => 'private-chat',
            'display_name' => 'Chat', 'category' => 'chat', 'input_modalities' => ['text'],
            'output_modalities' => ['text'], 'is_enabled' => true, 'is_available' => true,
        ]);
        $member = $this->member();

        $this->actingAs($member)->getJson('/api/c/capabilities?model=studio-link-chat')->assertOk()
            ->assertJsonPath('tools.image_generation.href', '/studio?kind=image');
        $member->update(['permissions' => [...User::DEFAULT_PERMISSIONS, 'image_generator' => false]]);
        $this->getJson('/api/c/capabilities?model=studio-link-chat')->assertOk()
            ->assertJsonPath('tools.image_generation.href', null);
        Http::assertNothingSent();
    }

    private function member(array $permissions = []): User
    {
        return User::factory()->create(['is_active' => true, 'permissions' => [...User::DEFAULT_PERMISSIONS, ...$permissions]]);
    }

    private function imageJob(User $user, string $status): ImageJob
    {
        $jobId = (string) Str::uuid();
        $path = 'generated/images/'.$jobId.'/0.png';
        Storage::disk('local')->put($path, 'png-bytes');

        return ImageJob::create([
            'user_id' => $user->id, 'job_id' => $jobId, 'model' => 'studio-link-image', 'prompt' => 'Studiolink image',
            'status' => $status, 'asset_paths' => [['path' => $path, 'mime' => 'image/png']],
        ]);
    }

    private function videoJob(User $user, string $mode, string $status): VideoJob
    {
        $jobId = (string) Str::uuid();
        Storage::disk('local')->put(GeneratedVideoStore::path($jobId), 'mp4-bytes');

        return VideoJob::create([
            'user_id' => $user->id, 'job_id' => $jobId, 'mode' => $mode, 'prompt' => 'Studiolink '.$mode,
            'model' => 'studio-link-video', 'status' => $status, 'video_url' => '/api/v/'.$jobId.'/asset',
        ]);
    }

    private function audioJob(User $user, string $status): AudioJob
    {
        $jobId = (string) Str::uuid();
        $outputs = [];
        foreach ([0, 1] as $index) {
            Storage::disk('local')->put(GeneratedAudioStore::path($jobId, $index), 'wav-bytes');
            $outputs[] = ['path' => GeneratedAudioStore::path($jobId, $index), 'mime_type' => 'audio/wav', 'size_bytes' => 9];
        }

        return AudioJob::create([
            'user_id' => $user->id, 'job_id' => $jobId, 'model' => 'studio-link-audio', 'mode' => 'music',
            'prompt' => 'Studiolink audio', 'provider_prompt' => 'Studiolink audio', 'upstream_model_id' => 'studio-link-audio',
            'connection_fingerprint' => str_repeat('0', 64), 'generation_config' => [], 'status' => $status,
            'outputs' => $outputs, 'billing_reference_id' => 'audio:'.$jobId, 'tokens_reserved' => 0,
        ]);
    }

    private function urls(array $groups, string $group): array
    {
        return array_column(collect($groups)->firstWhere('id', $group)['results'] ?? [], 'url');
    }
}
