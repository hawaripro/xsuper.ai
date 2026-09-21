<?php

namespace Tests\Feature\Media;

use App\Media\Enums\MediaOperation;
use App\Media\MediaGenerationCoordinator;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\User;
use App\Models\UserToken;
use App\Services\ImageGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Full F0b image lifecycle through capability -> coordinator -> adapter -> job -> asset
 * -> reserve/settle, plus rejection / uncertain-submit / poll-failure / finalization-retry.
 * All provider calls are Http::fake (feature/mock) — NOT live Kinovi.
 */
class ImageLifecycleTest extends TestCase
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

    private function png(): string
    {
        $img = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function start(User $user): ImageJob
    {
        return app(MediaGenerationCoordinator::class)->startImage(
            $user, $this->model(), MediaOperation::TextToImage, ['prompt' => 'a red apple', 'size' => '1024x1024'], 'studio-image'
        );
    }

    public function test_full_happy_lifecycle_stores_result_and_settles_credit(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['taskId' => 'task_1']),
            'https://kinovi.ai/api/v1/jobs/recordInfo*' => Http::response(['status' => 'success', 'output' => [['url' => 'https://static.seedance2-pro.com/x.png']]]),
            'https://static.seedance2-pro.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $svc = app(ImageGenerationService::class);

        $job = $this->start($user);
        $this->assertSame(90, UserToken::getBalance($user->id), 'reserved on start');

        $svc->process($job->id);
        $job->refresh();
        $this->assertSame('rendering', $job->stage);
        $this->assertSame('task_1', $job->upstream_job_id);
        $this->assertNotNull($job->capability_revision_id);

        $this->travel(10)->seconds();
        $svc->poll($job->id);
        $job->refresh();

        $this->assertSame('completed', $job->status);
        $this->assertSame('settled', $job->billing_status);
        $this->assertNotEmpty($job->result_urls);
        $this->assertSame(90, UserToken::getBalance($user->id), 'settled, not refunded');

        // Refresh / reopen: owner can re-read the completed job and its private asset.
        $this->actingAs($user)->getJson('/api/images/'.$job->job_id)->assertOk()->assertJsonPath('job.status', 'completed');
        $this->actingAs($user)->get($job->result_urls[0])->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_definitive_rejection_releases_credit(): void
    {
        Storage::fake('local');
        Http::fake(['https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['message' => 'Invalid inputs for model'], 422)]);
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        $job = $this->start($user);
        app(ImageGenerationService::class)->process($job->id);
        $job->refresh();

        $this->assertSame('failed', $job->status);
        $this->assertSame('released', $job->billing_status);
        $this->assertSame(100, UserToken::getBalance($user->id));
    }

    public function test_uncertain_submit_does_not_refund_or_resubmit(): void
    {
        Storage::fake('local');
        Http::fake(['https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['message' => 'gateway timeout'], 503)]);
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        $job = $this->start($user);
        app(ImageGenerationService::class)->process($job->id);
        $job->refresh();

        // Reservation held (no refund), no upstream job, left for bounded reconciliation.
        $this->assertSame('processing', $job->status);
        $this->assertSame('reserved', $job->billing_status);
        $this->assertNull($job->upstream_job_id);
        $this->assertSame(90, UserToken::getBalance($user->id));
    }

    public function test_provider_failure_status_releases_credit(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['taskId' => 'task_2']),
            'https://kinovi.ai/api/v1/jobs/recordInfo*' => Http::response(['status' => 'fail', 'error' => ['message' => 'nope']]),
        ]);
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $svc = app(ImageGenerationService::class);

        $job = $this->start($user);
        $svc->process($job->id);
        $this->travel(10)->seconds();
        $svc->poll($job->id);
        $job->refresh();

        $this->assertSame('failed', $job->status);
        $this->assertSame('released', $job->billing_status);
        $this->assertSame(100, UserToken::getBalance($user->id));
    }

    public function test_output_finalization_failure_retries_without_refund_or_new_generation(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['taskId' => 'task_3']),
            'https://kinovi.ai/api/v1/jobs/recordInfo*' => Http::response(['status' => 'success', 'output' => [['url' => 'https://static.seedance2-pro.com/y.png']]]),
            'https://static.seedance2-pro.com/*' => Http::response('broken', 503), // download fails
        ]);
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $svc = app(ImageGenerationService::class);

        $job = $this->start($user);
        $svc->process($job->id);
        $this->travel(10)->seconds();
        $svc->poll($job->id);
        $job->refresh();

        // Provider succeeded but save failed -> retry finalization, no refund/settle, no new createTask.
        $this->assertSame('processing', $job->status);
        $this->assertSame('rendering', $job->stage);
        $this->assertSame('reserved', $job->billing_status);
        $this->assertSame(90, UserToken::getBalance($user->id));
        Http::assertSentCount(3); // 1 createTask + 1 recordInfo + 1 failed download; NOT a second createTask
    }

    public function test_non_pilot_legacy_job_completes_on_the_shared_pipeline(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['taskId' => 'task_legacy']),
            'https://kinovi.ai/api/v1/jobs/recordInfo*' => Http::response(['status' => 'success', 'output' => [['url' => 'https://static.seedance2-pro.com/x.png']]]),
            'https://static.seedance2-pro.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
        $this->model();
        $pilot = User::factory()->create();
        $other = User::factory()->create();
        UserToken::topup($other->id, 100);
        config(['media.coordinator_restricted' => true, 'media.restricted_user_id' => $pilot->id]);
        $svc = app(ImageGenerationService::class);

        // Non-pilot submits -> existing path (no capability revision), still accepted + reserved.
        $job = $svc->generate($other, 'kinovi-ai/gpt-image-2', 'a red apple', '1024x1024', 1);
        $this->assertNull($job->capability_revision_id, 'non-pilot job carries no capability revision');
        $this->assertSame(90, UserToken::getBalance($other->id), 'reserved on submit');

        // Accepted job finishes on the SAME shared pipeline: submit -> render -> poll -> complete.
        $svc->process($job->id);
        $job->refresh();
        $this->assertSame('rendering', $job->stage);
        $this->assertSame('task_legacy', $job->upstream_job_id);

        $this->travel(10)->seconds();
        $svc->poll($job->id);
        $job->refresh();

        $this->assertSame('completed', $job->status);
        $this->assertSame('settled', $job->billing_status);
        $this->assertNotEmpty($job->result_urls);
        $this->assertSame(90, UserToken::getBalance($other->id), 'settled once, never refunded or double-charged');
        $this->actingAs($other)->get($job->result_urls[0])->assertOk()->assertHeader('Content-Type', 'image/png');
    }
}
