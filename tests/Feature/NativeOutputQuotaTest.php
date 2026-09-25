<?php

namespace Tests\Feature;

use App\Media\Enums\MediaOperation;
use App\Media\MediaGenerationCoordinator;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use App\Models\UserToken;
use App\Services\AiProviderEndpoint;
use App\Services\ImageGenerationService;
use App\Services\StorageQuotaService;
use App\Services\WorkspaceMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NativeOutputQuotaTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->mock(AiProviderEndpoint::class, function ($mock): void {
            $mock->shouldReceive('normalize')->andReturnUsing(fn (string $url): string => rtrim($url, '/'));
            $mock->shouldReceive('requestOptions')->andReturn(['allow_redirects' => false, 'proxy' => '', 'verify' => true]);
        });
    }

    private function imageModel(): AiModelProfile
    {
        $provider = AiProviderProfile::create(['slug' => 'quota-native', 'name' => 'Quota native', 'protocol' => 'openai',
            'base_url' => 'https://quota.example.test/v1', 'api_key' => 'fixture-only', 'is_enabled' => true]);
        Http::fake(['https://quota.example.test/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG]]])]);

        return AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'gpt-image-2', 'upstream_model_id' => 'gpt-image-2',
            'display_name' => 'Image quota fixture', 'category' => 'image', 'token_cost' => 15, 'is_enabled' => true, 'is_available' => true]);
    }

    public static function imageAdmissions(): array
    {
        return ['native synchronous' => [false], 'native queued' => [true]];
    }

    #[DataProvider('imageAdmissions')]
    public function test_completed_provider_image_waits_for_storage_and_retries_only_its_original_result(bool $queued): void
    {
        $model = $this->imageModel();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $bytes = base64_decode(self::PNG);
        config(['storage_quota.base_bytes' => strlen($bytes) - 1]);
        $service = app(ImageGenerationService::class);
        if ($queued) {
            $job = app(MediaGenerationCoordinator::class)->startImage($user, $model, MediaOperation::TextToImage,
                ['prompt' => 'An original image'], 'studio-image');
            $service->process($job->id);
        } else {
            $job = $service->generate($user, $model->model_id, 'An original image', 'auto', 1);
        }
        $job->refresh();

        $this->assertSame('save_failed', $job->stage);
        $this->assertSame('reserved', $job->billing_status);
        $this->assertSame(85, UserToken::getBalance($user->id));
        $this->assertSame(0, app(StorageQuotaService::class)->usedBytes($user));
        $this->assertSame([], Storage::disk('local')->allFiles('generated/images/'.$job->job_id));
        $payload = app(WorkspaceMediaService::class)->payload($job);
        $this->assertSame('save_failed', $payload['status']);
        $this->assertTrue($payload['can_retry_save']);
        Http::assertSentCount(1);

        $this->travel(1)->hours();
        $service->reconcileStaleReservations(6);
        $this->assertSame('reserved', $job->fresh()->billing_status);
        config(['storage_quota.base_bytes' => strlen($bytes)]);
        $this->actingAs($user)->postJson('/api/media/workspace/jobs/image:'.$job->job_id.'/retry-save')->assertSuccessful();
        $service->poll($job->id);
        $job->refresh();

        $this->assertSame('completed', $job->status);
        $this->assertSame('settled', $job->billing_status);
        $this->assertSame(85, UserToken::getBalance($user->id));
        $this->assertSame(strlen($bytes), app(StorageQuotaService::class)->usedBytes($user));
        $this->assertSame($bytes, Storage::disk('local')->get($job->asset_paths[0]['path']));
        $this->get($job->result_urls[0])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->postJson('/api/media/workspace/jobs/image:'.$job->job_id.'/retry-save')->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_two_admitted_outputs_compete_for_remaining_storage_at_finalization(): void
    {
        $model = $this->imageModel();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $size = strlen(base64_decode(self::PNG));
        config(['storage_quota.base_bytes' => $size * 2 - 1]);
        $coordinator = app(MediaGenerationCoordinator::class);
        $first = $coordinator->startImage($user, $model, MediaOperation::TextToImage, ['prompt' => 'First'], 'studio-image');
        $second = $coordinator->startImage($user, $model, MediaOperation::TextToImage, ['prompt' => 'Second'], 'studio-image');
        $service = app(ImageGenerationService::class);
        $service->process($first->id);
        $service->process($second->id);

        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('save_failed', $second->fresh()->stage);
        $this->assertSame($size, app(StorageQuotaService::class)->usedBytes($user));
        $this->assertSame(70, UserToken::getBalance($user->id));
        $this->assertSame('reserved', $second->fresh()->billing_status);
        Http::assertSentCount(2);
    }
}
