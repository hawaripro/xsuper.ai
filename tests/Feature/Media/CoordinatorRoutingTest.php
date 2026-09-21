<?php

namespace Tests\Feature\Media;

use App\Exceptions\ImageGenerationException;
use App\Jobs\ProcessImageJob;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use App\Models\UserToken;
use App\Services\ImageGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Limited activation ROUTES, never blocks: the pilot (or everyone once unrestricted) uses the
 * capability coordinator; other members keep the existing verified Kinovi path. Kill switch
 * pauses everyone. Path is chosen before reservation; there is no cross-path retry.
 */
class CoordinatorRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function model(): AiModelProfile
    {
        $provider = AiProviderProfile::create([
            'slug' => 'kinovi-ai', 'name' => 'Kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'k', 'is_enabled' => true,
        ]);

        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'kinovi-ai/gpt-image-2', 'upstream_model_id' => 'gpt-image-2',
            'display_name' => 'GPT Image 2', 'category' => 'image', 'is_enabled' => true, 'is_available' => true, 'token_cost' => 10,
        ]);
    }

    private function generate(User $user): \App\Models\ImageJob
    {
        return app(ImageGenerationService::class)->generate($user, 'kinovi-ai/gpt-image-2', 'a red apple', '1024x1024', 1);
    }

    public function test_pilot_uses_coordinator_path(): void
    {
        Queue::fake();
        $this->model();
        $pilot = User::factory()->create();
        UserToken::topup($pilot->id, 100);
        config(['media.coordinator_restricted' => true, 'media.restricted_user_id' => $pilot->id]);

        $job = $this->generate($pilot);

        $this->assertSame('pending', $job->status);
        $this->assertNotNull($job->capability_revision_id, 'pilot goes through the capability coordinator');
        $this->assertSame(90, UserToken::getBalance($pilot->id));
        Queue::assertPushed(ProcessImageJob::class);
    }

    public function test_non_pilot_keeps_existing_path_not_blocked(): void
    {
        Queue::fake();
        $this->model();
        $pilot = User::factory()->create();
        $other = User::factory()->create();
        UserToken::topup($other->id, 100);
        config(['media.coordinator_restricted' => true, 'media.restricted_user_id' => $pilot->id]);

        $job = $this->generate($other);

        $this->assertSame('pending', $job->status, 'non-pilot is NOT blocked');
        $this->assertNull($job->capability_revision_id, 'non-pilot uses the existing no-capability path');
        $this->assertSame(10, (int) $job->tokens_reserved);
        $this->assertSame(90, UserToken::getBalance($other->id));
        Queue::assertPushed(ProcessImageJob::class);
    }

    public function test_empty_restricted_id_routes_everyone_to_existing_path(): void
    {
        Queue::fake();
        $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        config(['media.coordinator_restricted' => true, 'media.restricted_user_id' => null]);

        $job = $this->generate($user);

        $this->assertSame('pending', $job->status, 'existing service preserved for everyone');
        $this->assertNull($job->capability_revision_id, 'coordinator opens for no one when the id is empty');
    }

    public function test_unrestricted_uses_coordinator_for_all(): void
    {
        Queue::fake();
        $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        config(['media.coordinator_restricted' => false]);

        $job = $this->generate($user);

        $this->assertNotNull($job->capability_revision_id, 'unrestricted routes everyone to the coordinator');
    }

    public function test_kill_switch_blocks_both_paths(): void
    {
        Queue::fake();
        $this->model();
        $pilot = User::factory()->create();
        $other = User::factory()->create();
        UserToken::topup($pilot->id, 100);
        UserToken::topup($other->id, 100);
        config(['media.kill_switch' => true, 'media.coordinator_restricted' => true, 'media.restricted_user_id' => $pilot->id]);

        foreach ([$pilot, $other] as $u) {
            try {
                $this->generate($u);
                $this->fail('kill switch must reject new submissions');
            } catch (ImageGenerationException $e) {
                $this->assertSame(503, $e->responseStatus());
            }
        }
        $this->assertDatabaseCount('image_jobs', 0);
        $this->assertSame(100, UserToken::getBalance($pilot->id));
        $this->assertSame(100, UserToken::getBalance($other->id));
    }

    public function test_unavailable_model_rejected_on_both_paths(): void
    {
        Queue::fake();
        $model = $this->model();
        $model->update(['is_available' => false]);
        $pilot = User::factory()->create();
        $other = User::factory()->create();
        UserToken::topup($pilot->id, 100);
        UserToken::topup($other->id, 100);
        config(['media.coordinator_restricted' => true, 'media.restricted_user_id' => $pilot->id]);

        foreach ([$pilot, $other] as $u) {
            try {
                $this->generate($u);
                $this->fail('unavailable model must be rejected on either path');
            } catch (ImageGenerationException $e) {
                $this->assertSame(503, $e->responseStatus());
            }
        }
        $this->assertDatabaseCount('image_jobs', 0);
    }
}
