<?php

namespace Tests\Feature\Media;

use App\Jobs\ProcessImageJob;
use App\Media\Enums\MediaOperation;
use App\Media\Exceptions\CapabilityValidationException;
use App\Exceptions\ImageGenerationException;
use App\Media\MediaGenerationCoordinator;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\TokenReservation;
use App\Models\User;
use App\Models\UserToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CoordinatorImageFlowTest extends TestCase
{
    use RefreshDatabase;

    private function model(): AiModelProfile
    {
        $provider = AiProviderProfile::create([
            'slug' => 'kinovi-ai', 'name' => 'Kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'k', 'is_enabled' => true,
        ]);

        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'kinovi-ai/gpt-image-2',
            'upstream_model_id' => 'gpt-image-2', 'display_name' => 'GPT Image 2', 'category' => 'image',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 10,
        ]);
    }

    public function test_missing_required_input_creates_no_reservation_or_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        try {
            app(MediaGenerationCoordinator::class)->startImage($user, $this->model(), MediaOperation::TextToImage, ['size' => '1024x1024'], 'studio-image');
            $this->fail('expected validation to reject missing prompt');
        } catch (CapabilityValidationException $e) {
            $this->assertArrayHasKey('prompt', $e->errors());
        }

        $this->assertDatabaseCount('image_jobs', 0);
        $this->assertDatabaseCount('token_reservations', 0);
        $this->assertSame(100, UserToken::getBalance($user->id));
        Queue::assertNothingPushed();
    }

    public function test_valid_input_reserves_and_creates_pending_job_with_capability_revision(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        $job = app(MediaGenerationCoordinator::class)->startImage(
            $user, $this->model(), MediaOperation::TextToImage, ['prompt' => 'a red apple', 'size' => '1024x1024'], 'studio-image'
        );

        $this->assertSame('pending', $job->status);
        $this->assertSame('queued', $job->stage);
        $this->assertNotNull($job->capability_revision_id);
        $this->assertSame('gpt-image-2', $job->routing_identity);
        $this->assertSame(10, $job->price_tokens);
        $this->assertSame(90, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenReservation::where('user_id', $user->id)->where('status', 'reserved')->count());
        Queue::assertPushed(ProcessImageJob::class, 1);
    }

    public function test_without_idempotency_key_each_submission_is_a_new_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $model = $this->model();
        $coordinator = app(MediaGenerationCoordinator::class);
        $inputs = ['prompt' => 'same prompt', 'size' => '1024x1024'];

        $first = $coordinator->startImage($user, $model, MediaOperation::TextToImage, $inputs, 'studio-image');
        $second = $coordinator->startImage($user, $model, MediaOperation::TextToImage, $inputs, 'studio-image');

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('image_jobs', 2);
    }

    public function test_same_idempotency_key_retry_returns_same_job_but_new_key_creates_another(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $model = $this->model();
        $coordinator = app(MediaGenerationCoordinator::class);
        $inputs = ['prompt' => 'same prompt', 'size' => '1024x1024'];

        // Accidental retry of the SAME action (same key) -> same job.
        $a1 = $coordinator->startImage($user, $model, MediaOperation::TextToImage, $inputs, 'studio-image', ['idempotency_key' => 'act-1']);
        $a2 = $coordinator->startImage($user, $model, MediaOperation::TextToImage, $inputs, 'studio-image', ['idempotency_key' => 'act-1']);
        $this->assertSame($a1->id, $a2->id);

        // A NEW Generate action (new key) with identical input -> a new job.
        $b = $coordinator->startImage($user, $model, MediaOperation::TextToImage, $inputs, 'studio-image', ['idempotency_key' => 'act-2']);
        $this->assertNotSame($a1->id, $b->id);
        $this->assertDatabaseCount('image_jobs', 2);
    }

    public function test_same_key_with_different_input_is_a_conflict(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $model = $this->model();
        $coordinator = app(MediaGenerationCoordinator::class);

        $coordinator->startImage($user, $model, MediaOperation::TextToImage, ['prompt' => 'first', 'size' => '1024x1024'], 'studio-image', ['idempotency_key' => 'act-9']);
        try {
            $coordinator->startImage($user, $model, MediaOperation::TextToImage, ['prompt' => 'DIFFERENT', 'size' => '1024x1024'], 'studio-image', ['idempotency_key' => 'act-9']);
            $this->fail('expected an idempotency conflict');
        } catch (ImageGenerationException $e) {
            $this->assertSame(409, $e->responseStatus());
        }
        $this->assertDatabaseCount('image_jobs', 1);
    }
}
