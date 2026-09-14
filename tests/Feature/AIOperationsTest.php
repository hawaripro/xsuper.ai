<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AiCatalogController;
use App\Http\Controllers\Api\ImageController;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\VideoJob;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AiProxyService;
use App\Services\AuditService;
use App\Services\ImageGenerationService;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AIOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.ai_proxy.url' => 'https://private-provider.example.test',
            'services.ai_proxy.key' => 'catalog-super-secret',
        ]);
        Http::preventStrayRequests();
    }

    public function test_admin_syncs_sanitized_catalog_and_records_healthy_provider_state(): void
    {
        Http::fake([
            'https://private-provider.example.test/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'image-alpha',
                        'name' => 'Image Alpha',
                        'category' => 'image',
                        'tier' => 'Wavespeed',
                        'capabilities' => ['image_generation', 'api_key' => 'upstream-model-secret'],
                        'api_key' => 'upstream-top-level-secret',
                        'base_url' => 'https://hidden-upstream.example.test',
                    ],
                    [
                        'id' => 'chat-alpha',
                        'name' => 'Chat Alpha',
                        'category' => 'chat',
                        'tier' => 'Standard',
                        'capabilities' => ['streaming'],
                    ],
                ],
            ]),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = app(AiCatalogController::class)->sync(
            $this->requestFor($admin, 'POST', '/api/admin/ai/catalog/sync'),
            app(AiProxyService::class),
            app(AuditService::class),
        );
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $payload['synced_models']);
        $this->assertSame('healthy', $payload['provider']['status']);
        $this->assertDatabaseHas('ai_model_profiles', [
            'model_id' => 'image-alpha',
            'display_name' => 'Image Alpha',
            'category' => 'image',
            'tier' => 'Wavespeed',
        ]);
        $this->assertSame(['image_generation'], AiModelProfile::where('model_id', 'image-alpha')->firstOrFail()->capabilities);
        $this->assertNotNull(AiProviderProfile::where('slug', 'ai-proxy')->firstOrFail()->last_checked_at);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'ai_catalog.synced',
        ]);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('catalog-super-secret', $json);
        $this->assertStringNotContainsString('upstream-model-secret', $json);
        $this->assertStringNotContainsString('upstream-top-level-secret', $json);
        $this->assertStringNotContainsString('private-provider.example.test', $json);
        $this->assertStringNotContainsString('hidden-upstream.example.test', $json);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://private-provider.example.test/v1/models'
            && $request->hasHeader('Authorization', 'Bearer catalog-super-secret'));
    }

    public function test_failed_catalog_health_check_persists_safe_unavailable_state(): void
    {
        Http::fake([
            'https://private-provider.example.test/v1/models' => Http::response([
                'error' => 'catalog-super-secret at https://private-provider.example.test exploded',
            ], 503),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = app(AiCatalogController::class)->sync(
            $this->requestFor($admin, 'POST', '/api/admin/ai/catalog/sync'),
            app(AiProxyService::class),
            app(AuditService::class),
        );
        $payload = $response->getData(true);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('unavailable', $payload['provider']['status']);
        $provider = AiProviderProfile::where('slug', 'ai-proxy')->firstOrFail();
        $this->assertSame('unavailable', $provider->status);
        $this->assertNotNull($provider->last_checked_at);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'ai_catalog.sync_failed',
        ]);

        $json = json_encode([
            'sync' => $payload,
            'catalog' => app(AiCatalogController::class)->index()->getData(true),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('catalog-super-secret', $json);
        $this->assertStringNotContainsString('private-provider.example.test', $json);
        $this->assertStringNotContainsString('exploded', $json);
    }

    public function test_successful_sync_marks_missing_provider_models_unavailable_without_changing_admin_preference(): void
    {
        [$provider, $removed] = $this->createImageModel('removed-image');
        Http::fake([
            'https://private-provider.example.test/v1/models' => Http::response([
                'data' => [[
                    'id' => 'current-image',
                    'name' => 'Current Image',
                    'category' => 'image',
                    'tier' => 'Wavespeed',
                ]],
            ]),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        app(AiCatalogController::class)->sync(
            $this->requestFor($admin, 'POST', '/api/admin/ai/catalog/sync'),
            app(AiProxyService::class),
            app(AuditService::class),
        );

        $this->assertTrue($removed->fresh()->is_enabled);
        $this->assertFalse($removed->fresh()->is_available);
        $this->assertTrue(AiModelProfile::where('model_id', 'current-image')->firstOrFail()->is_available);
        $this->assertSame($provider->id, AiModelProfile::where('model_id', 'current-image')->firstOrFail()->provider_id);
    }

    public function test_models_with_active_but_incomplete_usd_pricing_are_not_advertised(): void
    {
        [, $model] = $this->createImageModel();
        UsageRate::create([
            'service' => 'image',
            'meter' => 'unit',
            'model' => $model->model_id,
            'label' => 'Image Alpha',
            'unit' => 'image',
            'price_idr' => 4000,
            'price_usd' => null,
            'is_active' => true,
        ]);

        $payload = app(ImageController::class)->models()->getData(true);

        $this->assertSame([], $payload['models']);
    }

    public function test_model_update_rolls_back_when_required_audit_write_fails(): void
    {
        [, $model] = $this->createImageModel();
        $admin = User::factory()->create(['role' => 'admin']);
        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));

        try {
            app(AiCatalogController::class)->updateModel(
                $this->requestFor($admin, 'PATCH', "/api/admin/ai/models/{$model->id}", [
                    'display_name' => 'Unaudited label',
                ]),
                $model,
                $audit,
            );
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }

        $this->assertSame('Image Alpha', $model->fresh()->display_name);
    }

    public function test_failed_sync_health_state_rolls_back_when_required_audit_write_fails(): void
    {
        [$provider] = $this->createImageModel();
        Http::fake([
            'https://private-provider.example.test/v1/models' => Http::response([], 503),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));

        try {
            app(AiCatalogController::class)->sync(
                $this->requestFor($admin, 'POST', '/api/admin/ai/catalog/sync'),
                app(AiProxyService::class),
                $audit,
            );
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }

        $this->assertSame('healthy', $provider->fresh()->status);
        $this->assertNull($provider->fresh()->last_error);
    }

    public function test_admin_can_change_safe_model_label_and_enabled_metadata(): void
    {
        [$provider, $model] = $this->createImageModel();
        $admin = User::factory()->create(['role' => 'admin']);
        $request = $this->requestFor($admin, 'PATCH', "/api/admin/ai/models/{$model->id}", [
            'display_name' => 'Studio Image',
            'is_enabled' => false,
            'api_key' => 'must-never-leak',
            'base_url' => 'https://must-never-leak.test',
        ]);

        $response = app(AiCatalogController::class)->updateModel($request, $model, app(AuditService::class));
        $payload = $response->getData(true);

        $this->assertSame('Studio Image', $payload['model']['display_name']);
        $this->assertFalse($payload['model']['is_enabled']);
        $this->assertSame('Studio Image', $model->fresh()->display_name);
        $this->assertFalse($model->fresh()->is_enabled);
        $this->assertSame('healthy', $provider->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'ai_model.updated',
            'subject_id' => $model->id,
        ]);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must-never-leak', $json);
        $this->assertStringNotContainsString('base_url', $json);
        $this->assertStringNotContainsString('api_key', $json);
    }

    public function test_image_generation_uses_active_unit_rate_and_persists_urls(): void
    {
        [, $model] = $this->createImageModel();
        $this->createImageRate($model->model_id, 0.25);
        $user = User::factory()->create();
        Wallet::credit($user->id, 2_000_000, 'Test balance');
        Http::fake([
            'https://private-provider.example.test/v1/images/generations' => Http::response([
                'created' => 1_789_000_000,
                'data' => [
                    ['url' => 'https://cdn.example.test/result-one.png'],
                    ['url' => 'https://cdn.example.test/result-two.png'],
                ],
            ]),
        ]);

        $response = app(ImageController::class)->generate(
            $this->requestFor($user, 'POST', '/api/images', [
                'model' => $model->model_id,
                'prompt' => 'A precise editorial product photograph',
                'size' => '1024x1024',
                'n' => 2,
            ]),
            app(ImageGenerationService::class),
        );
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $job = ImageJob::where('job_id', $payload['job']['job_id'])->firstOrFail();
        $this->assertSame('completed', $job->status);
        $this->assertSame('settled', $job->billing_status);
        $this->assertSame(500_000, $job->billing_reserved_microusd);
        $this->assertSame([
            'https://cdn.example.test/result-one.png',
            'https://cdn.example.test/result-two.png',
        ], $job->result_urls);
        $this->assertSame(1_500_000, Wallet::balance($user->id));
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'type' => 'settlement',
            'reference_id' => $job->billing_reference_id,
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://private-provider.example.test/v1/images/generations'
            && $request['model'] === 'image-alpha'
            && $request['prompt'] === 'A precise editorial product photograph'
            && $request['size'] === '1024x1024'
            && $request['n'] === 2);
    }

    public function test_image_generation_uses_laravel_validation_for_model_prompt_size_and_n(): void
    {
        $user = User::factory()->create();
        $request = $this->requestFor($user, 'POST', '/api/images', [
            'model' => 'not-enabled',
            'prompt' => '',
            'size' => 'poster',
            'n' => 9,
        ]);

        try {
            app(ImageController::class)->generate($request, app(ImageGenerationService::class));
            $this->fail('Expected validation to reject the request.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(
                ['model', 'prompt', 'size', 'n'],
                array_keys($exception->errors()),
            );
        }

        Http::assertNothingSent();
    }

    public function test_image_generation_requires_an_active_image_rate(): void
    {
        [, $model] = $this->createImageModel();
        $user = User::factory()->create();
        Wallet::credit($user->id, 2_000_000, 'Test balance');
        Http::fake();

        $response = app(ImageController::class)->generate(
            $this->requestFor($user, 'POST', '/api/images', [
                'model' => $model->model_id,
                'prompt' => 'A catalog photograph',
                'size' => '1024x1024',
                'n' => 1,
            ]),
            app(ImageGenerationService::class),
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(2_000_000, Wallet::balance($user->id));
        $this->assertSame(0, ImageJob::count());
        Http::assertNothingSent();
    }

    public function test_active_zero_cost_image_rate_is_settled_without_reserved_wallet_balance(): void
    {
        [, $model] = $this->createImageModel();
        $this->createImageRate($model->model_id, 0);
        $user = User::factory()->create();
        Http::fake([
            'https://private-provider.example.test/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://cdn.example.test/free-image.png']],
            ]),
        ]);

        $response = app(ImageController::class)->generate(
            $this->validImageRequest($user, $model->model_id),
            app(ImageGenerationService::class),
        );
        $job = ImageJob::firstOrFail();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('completed', $job->status);
        $this->assertSame('settled', $job->billing_status);
        $this->assertSame(0, $job->billing_reserved_microusd);
        $this->assertSame(0, Wallet::balance($user->id));
        $this->assertDatabaseHas('wallet_transactions', [
            'reference_id' => $job->billing_reference_id,
            'type' => 'settlement',
            'amount_microusd' => 0,
        ]);
    }

    public function test_unit_billing_rejects_a_missing_active_rate(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(UsageBillingService::class)->reserveUnit($user->id, 'image', 'unpriced-model', 1, 'missing-rate');
    }

    public function test_unsupported_image_upstream_returns_502_and_refunds_reservation(): void
    {
        [, $model] = $this->createImageModel();
        $this->createImageRate($model->model_id, 0.75);
        $user = User::factory()->create();
        Wallet::credit($user->id, 1_000_000, 'Test balance');
        Http::fake([
            'https://private-provider.example.test/v1/images/generations' => Http::response([
                'error' => 'catalog-super-secret unsupported at https://private-provider.example.test',
            ], 404),
        ]);

        $response = app(ImageController::class)->generate(
            $this->validImageRequest($user, $model->model_id),
            app(ImageGenerationService::class),
        );
        $payload = $response->getData(true);
        $job = ImageJob::firstOrFail();

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('failed', $job->status);
        $this->assertSame('released', $job->billing_status);
        $this->assertNull($job->result_urls);
        $this->assertSame(1_000_000, Wallet::balance($user->id));
        $this->assertDatabaseMissing('wallet_transactions', [
            'reference_id' => $job->billing_reference_id,
            'type' => 'reserve',
            'amount_microusd' => 0,
        ]);
        $this->assertSame(1, WalletTransaction::where('reference_id', $job->billing_reference_id)->where('type', 'release')->count());
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('catalog-super-secret', $json);
        $this->assertStringNotContainsString('private-provider.example.test', $json);
        $this->assertStringNotContainsString('fallback', strtolower($json));
    }

    public function test_unavailable_image_upstream_returns_503_and_refunds_reservation(): void
    {
        [, $model] = $this->createImageModel();
        $this->createImageRate($model->model_id, 0.5);
        $user = User::factory()->create();
        Wallet::credit($user->id, 1_000_000, 'Test balance');
        Http::fake(function (): never {
            throw new ConnectionException('catalog-super-secret connection failed');
        });

        $response = app(ImageController::class)->generate(
            $this->validImageRequest($user, $model->model_id),
            app(ImageGenerationService::class),
        );
        $job = ImageJob::firstOrFail();

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('failed', $job->status);
        $this->assertSame('released', $job->billing_status);
        $this->assertSame(1_000_000, Wallet::balance($user->id));
        $this->assertSame(1, WalletTransaction::where('reference_id', $job->billing_reference_id)->where('type', 'release')->count());
        $this->assertStringNotContainsString('catalog-super-secret', json_encode($response->getData(true), JSON_THROW_ON_ERROR));
    }

    public function test_reconciliation_command_releases_stale_processing_reservations(): void
    {
        [, $model] = $this->createImageModel();
        $this->createImageRate($model->model_id, 0.5);
        $user = User::factory()->create();
        Wallet::credit($user->id, 1_000_000, 'Test balance');
        $referenceId = 'image:stale-job';
        $reservation = app(UsageBillingService::class)->reserveUnit(
            $user->id,
            'image',
            $model->model_id,
            1,
            $referenceId,
        );
        $job = ImageJob::create([
            'user_id' => $user->id,
            'job_id' => (string) Str::uuid(),
            'model' => $model->model_id,
            'prompt' => 'Stale request',
            'size' => '1024x1024',
            'quantity' => 1,
            'status' => 'processing',
            'billing_reserved_microusd' => $reservation['amount_microusd'],
            'billing_reference_id' => $referenceId,
            'billing_status' => 'reserved',
        ]);
        $job->timestamps = false;
        $job->updated_at = now()->subMinutes(5);
        $job->save();

        $this->artisan('images:reconcile-stale', ['--minutes' => 3])->assertSuccessful();

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('released', $job->fresh()->billing_status);
        $this->assertSame('Image generation timed out.', $job->fresh()->error_message);
        $this->assertSame(1_000_000, Wallet::balance($user->id));
        $this->assertSame(1, WalletTransaction::where('reference_id', $referenceId)->where('type', 'release')->count());
    }

    public function test_member_image_history_and_status_are_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owned = $this->createImageJob($owner, 'completed');
        $foreign = $this->createImageJob($other, 'failed');
        $controller = app(ImageController::class);

        $history = $controller->history($this->requestFor($owner, 'GET', '/api/images'))->getData(true);
        $status = $controller->show(
            $this->requestFor($owner, 'GET', "/api/images/{$owned->job_id}"),
            $owned->job_id,
        )->getData(true);

        $this->assertSame([$owned->job_id], array_column($history['jobs'], 'job_id'));
        $this->assertSame($owned->job_id, $status['job']['job_id']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $controller->show(
            $this->requestFor($owner, 'GET', "/api/images/{$foreign->job_id}"),
            $foreign->job_id,
        );
    }

    public function test_admin_media_queue_is_global_for_images_and_videos(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $firstImage = $this->createImageJob($first, 'completed');
        $secondImage = $this->createImageJob($second, 'failed');
        $firstVideo = $this->createVideoJob($first, 'completed');
        $secondVideo = $this->createVideoJob($second, 'pending');

        $response = app(ImageController::class)->adminQueue(
            $this->requestFor($admin, 'GET', '/api/admin/media/queue'),
        );
        $payload = $response->getData(true);

        $this->assertEqualsCanonicalizing(
            [$firstImage->job_id, $secondImage->job_id],
            array_column($payload['images'], 'job_id'),
        );
        $this->assertEqualsCanonicalizing(
            [$firstVideo->job_id, $secondVideo->job_id],
            array_column($payload['videos'], 'job_id'),
        );
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_column(array_column($payload['images'], 'user'), 'id'),
        );
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('password', strtolower($json));
        $this->assertStringNotContainsString('permissions', strtolower($json));
    }

    private function requestFor(User $user, string $method, string $uri, array $data = []): Request
    {
        $request = Request::create($uri, $method, $data);
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }

    private function validImageRequest(User $user, string $model): Request
    {
        return $this->requestFor($user, 'POST', '/api/images', [
            'model' => $model,
            'prompt' => 'A carefully lit product photograph',
            'size' => '1024x1024',
            'n' => 1,
        ]);
    }

    private function createImageModel(string $modelId = 'image-alpha', bool $enabled = true): array
    {
        $provider = AiProviderProfile::create([
            'slug' => 'ai-proxy',
            'name' => 'AI Proxy',
            'status' => 'healthy',
            'is_enabled' => true,
            'capabilities' => ['models', 'images'],
            'last_checked_at' => now(),
        ]);
        $model = AiModelProfile::create([
            'provider_id' => $provider->id,
            'model_id' => $modelId,
            'display_name' => 'Image Alpha',
            'category' => 'image',
            'tier' => 'Wavespeed',
            'is_enabled' => $enabled,
            'is_available' => true,
            'capabilities' => ['image_generation'],
            'last_seen_at' => now(),
        ]);

        return [$provider, $model];
    }

    private function createImageRate(string $model, float $priceUsd): UsageRate
    {
        return UsageRate::create([
            'service' => 'image',
            'meter' => 'unit',
            'model' => $model,
            'label' => 'Image Alpha',
            'unit' => 'image',
            'price_idr' => 16_000 * $priceUsd,
            'price_usd' => $priceUsd,
            'is_active' => true,
        ]);
    }

    private function createImageJob(User $user, string $status): ImageJob
    {
        return ImageJob::create([
            'user_id' => $user->id,
            'job_id' => (string) Str::uuid(),
            'model' => 'image-alpha',
            'prompt' => 'Test prompt',
            'size' => '1024x1024',
            'quantity' => 1,
            'status' => $status,
            'result_urls' => $status === 'completed' ? ['https://cdn.example.test/image.png'] : null,
            'error_message' => $status === 'failed' ? 'Generation failed.' : null,
            'billing_reserved_microusd' => 0,
            'billing_status' => 'not_required',
        ]);
    }

    private function createVideoJob(User $user, string $status): VideoJob
    {
        return VideoJob::create([
            'user_id' => $user->id,
            'job_id' => (string) Str::uuid(),
            'mode' => 'prompt',
            'prompt' => 'Test video',
            'model' => 'sora-2',
            'aspect_ratio' => '16:9',
            'duration' => 10,
            'tokens_used' => 0,
            'billing_reserved_microusd' => 0,
            'billing_status' => 'none',
            'status' => $status,
        ]);
    }
}
