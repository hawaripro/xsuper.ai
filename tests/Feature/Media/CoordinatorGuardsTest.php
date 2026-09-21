<?php

namespace Tests\Feature\Media;

use App\Exceptions\ImageGenerationException;
use App\Media\Enums\MediaOperation;
use App\Media\MediaGenerationCoordinator;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use App\Models\UserToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CoordinatorGuardsTest extends TestCase
{
    use RefreshDatabase;

    private ?AiModelProfile $model = null;

    private function model(): AiModelProfile
    {
        if ($this->model !== null) {
            return $this->model;
        }
        $provider = AiProviderProfile::create([
            'slug' => 'kinovi-ai', 'name' => 'Kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'k', 'is_enabled' => true,
        ]);

        return $this->model = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'kinovi-ai/gpt-image-2', 'upstream_model_id' => 'gpt-image-2',
            'display_name' => 'GPT Image 2', 'category' => 'image', 'is_enabled' => true, 'is_available' => true, 'token_cost' => 10,
        ]);
    }

    private function start(User $user, array $options = [])
    {
        return app(MediaGenerationCoordinator::class)->startImage(
            $user, $this->model(), MediaOperation::TextToImage, ['prompt' => 'a red apple', 'size' => '1024x1024'], 'studio-image', $options
        );
    }

    public function test_stale_price_is_rejected_before_reservation(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        try {
            $this->start($user, ['expected_price_tokens' => 9]); // model is 10 now
            $this->fail('expected 409 stale price');
        } catch (ImageGenerationException $e) {
            $this->assertSame(409, $e->responseStatus());
        }

        $this->assertDatabaseCount('image_jobs', 0);
        $this->assertDatabaseCount('token_reservations', 0);
        $this->assertSame(100, UserToken::getBalance($user->id));
    }

    public function test_matching_price_proceeds(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        $job = $this->start($user, ['expected_price_tokens' => 10]);
        $this->assertSame('pending', $job->status);
        $this->assertSame(90, UserToken::getBalance($user->id));
    }

    public function test_stale_capability_hash_is_rejected(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        try {
            $this->start($user, ['expected_capability_hash' => 'not-the-current-hash']);
            $this->fail('expected 409 stale capability');
        } catch (ImageGenerationException $e) {
            $this->assertSame(409, $e->responseStatus());
        }
        $this->assertDatabaseCount('image_jobs', 0);
    }

    public function test_kill_switch_rejects_new_submissions(): void
    {
        Queue::fake();
        config(['media.kill_switch' => true]);
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        try {
            $this->start($user);
            $this->fail('expected kill switch to reject');
        } catch (ImageGenerationException $e) {
            $this->assertSame(503, $e->responseStatus());
        }
        $this->assertDatabaseCount('image_jobs', 0);
        $this->assertSame(100, UserToken::getBalance($user->id));
    }

    public function test_limited_activation_restricts_to_one_user(): void
    {
        Queue::fake();
        $allowed = User::factory()->create();
        $other = User::factory()->create();
        UserToken::topup($allowed->id, 100);
        UserToken::topup($other->id, 100);
        config(['media.restricted_user_id' => $allowed->id]);

        try {
            $this->start($other);
            $this->fail('expected restriction to reject the other user');
        } catch (ImageGenerationException $e) {
            $this->assertSame(503, $e->responseStatus());
        }

        $job = $this->start($allowed);
        $this->assertSame('pending', $job->status);
        $this->assertSame(1, \App\Models\ImageJob::count());
    }
}
