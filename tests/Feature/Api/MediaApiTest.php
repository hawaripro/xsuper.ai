<?php

namespace Tests\Feature\Api;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ApiKey;
use App\Models\ImageJob;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use App\Models\UserToken;
use App\Models\WorkspaceMediaJob;
use App\Services\AiProviderEndpoint;
use App\Services\ImageGenerationService;
use App\Services\WorkspaceMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class MediaApiTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([]);
        Storage::fake('local');
        config(['media.coordinator_restricted' => false, 'media.kill_switch' => false]);
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_catalog_hides_connections_and_realtime_and_honors_key_scope(): void
    {
        [$user, $model, $revision, $key] = $this->fixture();
        $response = $this->getJson('/v1/media/models')->assertOk()->assertJsonPath('object', 'list')
            ->assertJsonPath('data.0.id', $model->model_id)->assertJsonPath('data.0.operations.0.price_tokens', 10);
        foreach (['provider', 'upstream', 'fal.run', 'test-only-key', 'private-endpoint'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->getJson('/v1/media/models/'.$model->model_id)->assertOk()
            ->assertJsonPath('operations.text_to_image.billing.price_unit', 'generation')
            ->assertJsonPath('operations.text_to_image.input_schema.properties.prompt.type', 'string');
        $key->update(['allowed_models' => ['another-model']]);
        $this->getJson('/v1/media/models')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/v1/media/models/'.$model->model_id)->assertNotFound()->assertJsonStructure(['error' => ['message', 'type', 'code']]);
        $this->postJson('/v1/media/generations', $this->request($model))->assertForbidden()->assertJsonPath('error.code', 'model_not_allowed');
        $key->update(['allowed_models' => null]);
        $revision->update(['provider_bindings' => [...$revision->provider_bindings, 'adapter' => 'fal_wma_v1', 'transport' => 'realtime']]);
        $this->getJson('/v1/media/models')->assertOk()->assertJsonPath('data', []);
    }

    public function test_quote_debit_and_idempotent_replay_share_exact_second_quantity_math(): void
    {
        [$user, $model, $revision] = $this->fixture();
        $revision->update(['curation_overrides' => ['pricing' => [...$revision->curation_overrides['pricing'], 'unit' => 'second']]]);
        $request = $this->request($model, ['duration' => 5, 'numberResults' => 2]);
        $quote = app(WorkspaceMediaService::class)->quote($user, $request);
        $this->assertSame([10, 10, 1, 100], [$quote['unit_price_tokens'], $quote['quantity'], $quote['count'], $quote['total_tokens']]);
        $response = $this->withHeader('Idempotency-Key', 'stable-request')->postJson('/v1/media/generations', $request)
            ->assertAccepted()->assertJsonPath('price_tokens', 100)->assertJsonPath('object', 'media.generation');
        $this->postJson('/v1/media/generations', $request)->assertAccepted()->assertJsonPath('id', $response->json('id'));
        $this->assertSame(900, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('token_reservations', 1);
        $this->postJson('/v1/media/generations', $this->request($model, ['prompt' => 'Different']))->assertConflict();
        $this->assertSame(900, UserToken::getBalance($user->id));
    }

    public function test_short_balance_and_invalid_inputs_use_openai_errors_without_debit(): void
    {
        [$user, $model] = $this->fixture(0);
        $this->postJson('/v1/media/generations', $this->request($model))->assertStatus(402)
            ->assertJsonPath('error.type', 'insufficient_quota')->assertJsonPath('error.code', 'insufficient_tokens');
        $this->postJson('/v1/media/generations', ['model' => $model->model_id])->assertStatus(400)
            ->assertJsonStructure(['error' => ['message', 'type', 'code']]);
        $this->assertSame(0, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('workspace_media_jobs', 0);
    }

    public function test_outputs_are_owned_and_signed_links_expire_and_result_urls_are_rewritten(): void
    {
        [$user, $model] = $this->fixture();
        $id = $this->postJson('/v1/media/generations', $this->request($model))->assertAccepted()->json('id');
        $this->fakeImmediateResult();
        app(WorkspaceMediaService::class)->process(WorkspaceMediaJob::query()->sole()->id);
        $response = $this->getJson('/v1/media/generations/'.$id)->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('billing_status', 'settled');
        $image = collect($response->json('outputs'))->firstWhere('kind', 'image');
        $this->assertNotNull($image);
        $this->assertStringContainsString('/v1/media/generations/'.$id.'/outputs/', $image['url']);
        $this->assertSame($image['url'], $response->json('result.images.0.url'));
        $this->assertStringNotContainsString('/api/media/workspace', $response->getContent());
        $download = $this->get($image['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
        $this->assertSame(base64_decode(self::PNG), file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->getJson('/v1/media/generations?kind=image')->assertOk()->assertJsonPath('data.0.id', $id);
        $other = ApiKey::generate(User::factory()->create()->id);
        $this->withToken($other->plainKey)->getJson('/v1/media/generations/'.$id)->assertNotFound();
        $this->getJson($image['url'])->assertNotFound();
        $this->withoutHeader('Authorization')->get($image['signed_url'])->assertOk();
        $this->getJson($image['signed_url'].'x')->assertForbidden()->assertJsonStructure(['error' => ['message', 'type', 'code']]);
        $this->travel(61)->minutes();
        $this->getJson($image['signed_url'])->assertForbidden();
        $this->assertSame(990, UserToken::getBalance($user->id));
    }

    public function test_cancelling_queued_job_releases_tokens_once(): void
    {
        [$user, $model] = $this->fixture();
        $id = $this->postJson('/v1/media/generations', $this->request($model))->assertAccepted()->json('id');
        $this->postJson('/v1/media/generations/'.$id.'/cancel')->assertOk()->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('billing_status', 'released');
        $this->postJson('/v1/media/generations/'.$id.'/cancel')->assertConflict();
        $this->assertSame(1000, UserToken::getBalance($user->id));
    }

    public function test_uploads_use_owned_file_ids_and_reject_foreign_files_and_storage_overflow(): void
    {
        [$user, $model, $revision] = $this->fixture();
        $imageRevision = $revision->replicate();
        $schema = $revision->definition['input_schema'];
        $schema['properties']['image'] = ['type' => 'string', 'format' => 'uuid', 'x-workspace-asset' => ['kind' => 'image', 'role' => 'image_ref']];
        $schema['required'][] = 'image';
        $imageRevision->fill(['operation' => 'image_edit',
            'definition' => [...$revision->definition, 'operation' => 'image_edit', 'input_schema' => $schema],
            'provider_bindings' => [...$revision->provider_bindings, 'request_schema' => $schema]])->save();
        $upload = $this->post('/v1/files', ['file' => UploadedFile::fake()->image('reference.png', 8, 8), 'role' => 'image_ref'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('object', 'file')->assertJsonPath('kind', 'image');
        $this->postJson('/v1/media/generations', [...$this->request($model, ['image' => $upload->json('id')]), 'operation' => 'image_edit'])
            ->assertAccepted();
        $other = User::factory()->create();
        UserToken::topup($other->id, 100);
        $key = ApiKey::generate($other->id);
        $this->withToken($key->plainKey)->postJson('/v1/media/generations', [...$this->request($model, ['image' => $upload->json('id')]), 'operation' => 'image_edit'])
            ->assertForbidden();
        $this->assertSame(100, UserToken::getBalance($other->id));
        config(['storage_quota.base_bytes' => 0]);
        $this->post('/v1/files', ['file' => UploadedFile::fake()->image('full.png', 8, 8), 'role' => 'image_ref'], ['Accept' => 'application/json'])
            ->assertStatus(413)->assertJsonStructure(['error' => ['message', 'type', 'code']]);
    }

    public function test_openai_images_returns_usable_urls_and_base64_from_real_retained_outputs(): void
    {
        [$user, $model] = $this->fixture();
        $this->fakeImmediateResult();
        Sleep::fake();
        Sleep::whenFakingSleep(function (): void {
            WorkspaceMediaJob::query()->where('status', 'pending')->each(fn ($job) => app(WorkspaceMediaService::class)->process($job->id));
        });
        $request = ['model' => $model->model_id, 'prompt' => 'A cup', 'n' => 2, 'size' => '1024x1024'];
        $response = $this->postJson('/v1/images/generations', $request)->assertOk()->assertJsonCount(2, 'data');
        $this->withoutHeader('Authorization')->get($response->json('data.0.url'))->assertOk();
        $key = ApiKey::query()->where('user_id', $user->id)->first()->regenerateKey();
        $this->withToken($key->plainKey)->postJson('/v1/images/generations', [...$request, 'n' => 1, 'response_format' => 'b64_json'])
            ->assertOk()->assertJsonPath('data.0.b64_json', self::PNG);
        $this->assertSame(970, UserToken::getBalance($user->id));
        $inputs = WorkspaceMediaJob::query()->orderBy('id')->first()->normalized_inputs;
        $this->assertSame([1024, 1024, 2], [$inputs['width'], $inputs['height'], $inputs['numberResults']]);
    }

    public function test_image_schema_maps_alternate_prompt_and_closest_supported_aspect(): void
    {
        [$user, $model, $revision] = $this->fixture();
        $schema = ['type' => 'object', 'properties' => ['positivePrompt' => ['type' => 'string'],
            'aspect_ratio' => ['type' => 'string', 'enum' => ['1:1', '16:9', '9:16']]], 'required' => ['positivePrompt']];
        $revision->update(['definition' => [...$revision->definition, 'input_schema' => $schema],
            'provider_bindings' => [...$revision->provider_bindings, 'request_schema' => $schema, 'quantity_input' => null]]);
        $this->fakeImmediateResult();
        Sleep::fake();
        Sleep::whenFakingSleep(fn () => WorkspaceMediaJob::query()->where('status', 'pending')
            ->each(fn ($job) => app(WorkspaceMediaService::class)->process($job->id)));
        $this->postJson('/v1/images/generations', ['model' => $model->model_id, 'prompt' => 'A wide landscape', 'size' => '1792x1024'])
            ->assertOk();
        $this->assertSame(['positivePrompt' => 'A wide landscape', 'aspect_ratio' => '16:9'], WorkspaceMediaJob::query()->sole()->normalized_inputs);
        $this->assertSame(990, UserToken::getBalance($user->id));
    }

    public function test_native_batch_is_debited_once_and_every_image_remains_addressable(): void
    {
        [$user] = $this->fixture();
        $model = $this->nativeImageModel();
        $request = [...$this->request($model, ['size' => '1024x1024']), 'count' => 2];
        $response = $this->withHeader('Idempotency-Key', 'native-batch')->postJson('/v1/media/generations', $request)
            ->assertAccepted()->assertJsonCount(2, 'generation_ids')->assertJsonPath('total_tokens', 20);
        $this->postJson('/v1/media/generations', $request)->assertAccepted()->assertJsonPath('generation_ids', $response->json('generation_ids'));
        Http::fake(['https://images.example.test/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG]]])]);
        foreach (\App\Models\ImageJob::query()->get() as $job) {
            app(\App\Services\ImageGenerationService::class)->process($job->id);
        }
        foreach ($response->json('generation_ids') as $id) {
            $result = $this->getJson('/v1/media/generations/'.rawurlencode($id))->assertOk()->assertJsonPath('status', 'completed')
                ->assertJsonPath('generation_ids', $response->json('generation_ids'));
            $this->get($result->json('outputs.0.url'))->assertOk();
        }
        $this->assertSame(980, UserToken::getBalance($user->id));
        $this->assertDatabaseCount('token_reservations', 2);
    }

    public function test_images_failure_is_member_safe_and_releases_the_real_reservation(): void
    {
        [$user, $model] = $this->fixture();
        Http::fake(['https://fal.run/fal-ai/private-endpoint' => Http::response(['detail' => 'private provider routing secret'], 422)]);
        Sleep::fake();
        Sleep::whenFakingSleep(fn () => WorkspaceMediaJob::query()->where('status', 'pending')
            ->each(fn ($job) => app(WorkspaceMediaService::class)->process($job->id)));
        $response = $this->postJson('/v1/images/generations', ['model' => $model->model_id, 'prompt' => 'A cup'])
            ->assertStatus(502)->assertJsonPath('error.code', 'generation_failed');
        $this->assertStringNotContainsString('private provider routing secret', $response->getContent());
        $this->assertSame('released', WorkspaceMediaJob::query()->sole()->billing_status);
        $this->assertSame(1000, UserToken::getBalance($user->id));
    }

    public function test_images_timeout_links_existing_paid_job_and_unsupported_size_is_rejected(): void
    {
        [$user, $model] = $this->fixture();
        config(['media.api_sync_wait_seconds' => 1]);
        $this->postJson('/v1/images/generations', ['model' => $model->model_id, 'prompt' => 'A cup', 'size' => 'not-a-size'])
            ->assertStatus(400)->assertJsonPath('error.code', 'invalid_request');
        $response = $this->postJson('/v1/images/generations', ['model' => $model->model_id, 'prompt' => 'A cup'])->assertStatus(504);
        $id = WorkspaceMediaJob::query()->sole()->job_id;
        $this->assertStringContainsString($id, $response->json('error.message'));
        $this->assertStringContainsString('/v1/media/generations/'.$id, $response->json('error.message'));
        $this->assertSame([$id], $response->json('error.generation_ids'));
        $this->assertSame(990, UserToken::getBalance($user->id));
        $this->getJson('/v1/media/generations/'.$id)->assertOk()->assertJsonPath('status', 'pending');
    }

    public function test_native_images_timeout_lists_every_paid_generation_and_each_status_links_the_set(): void
    {
        [$user] = $this->fixture();
        $model = $this->nativeImageModel();
        config(['media.api_sync_wait_seconds' => 1]);
        // No Idempotency-Key: the 504 itself must lead to every job this request paid for.
        $response = $this->postJson('/v1/images/generations', ['model' => $model->model_id, 'prompt' => 'A cup', 'n' => 3])
            ->assertStatus(504)->assertJsonPath('error.type', 'server_error')->assertJsonPath('error.code', 'generation_timeout');
        $ids = ImageJob::query()->orderBy('id')->pluck('job_id')->map(fn (string $id): string => 'image:'.$id)->all();
        $this->assertCount(3, $ids);
        $this->assertSame(970, UserToken::getBalance($user->id));
        $this->assertSame($ids, $response->json('error.generation_ids'));
        $polls = $response->json('error.poll_urls');
        $this->assertSame(array_map(fn (string $id): string => '/v1/media/generations/'.$id, $ids),
            array_map(fn (string $url): string => parse_url($url, PHP_URL_PATH), $polls));
        $this->assertStringContainsString('Poll '.$polls[0].' with your API key', $response->json('error.message'));
        foreach ($polls as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('generation_ids', $ids);
        }
        $history = $this->getJson('/v1/media/generations?kind=image')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([$ids, $ids, $ids], array_column($history->json('data'), 'generation_ids'));
        $other = ApiKey::generate(User::factory()->create()->id);
        $this->withToken($other->plainKey)->getJson('/v1/media/generations')->assertOk()->assertJsonPath('data', []);
        foreach ($ids as $id) {
            $this->getJson('/v1/media/generations/'.$id)->assertNotFound();
        }
    }

    public function test_native_images_failure_lists_every_generation_and_keeps_the_paid_sibling_reachable(): void
    {
        [$user] = $this->fixture();
        $model = $this->nativeImageModel();
        // The first image is delivered; the provider rejects the second.
        Http::fake(['https://images.example.test/v1/images/generations' => Http::sequence()
            ->push(['data' => [['b64_json' => self::PNG]]])
            ->push(['error' => ['message' => 'private provider detail']], 400)]);
        Sleep::fake();
        Sleep::whenFakingSleep(fn () => ImageJob::query()->where('status', 'pending')
            ->each(fn (ImageJob $job) => app(ImageGenerationService::class)->process($job->id)));
        $response = $this->postJson('/v1/images/generations', ['model' => $model->model_id, 'prompt' => 'A cup', 'n' => 2])
            ->assertStatus(502)->assertJsonPath('error.type', 'server_error')->assertJsonPath('error.code', 'generation_failed');
        $this->assertStringNotContainsString('private provider detail', $response->getContent());
        $ids = ImageJob::query()->orderBy('id')->pluck('job_id')->map(fn (string $id): string => 'image:'.$id)->all();
        $this->assertCount(2, $ids);
        $this->assertSame($ids, $response->json('error.generation_ids'));
        $polls = $response->json('error.poll_urls');
        $this->assertSame(array_map(fn (string $id): string => '/v1/media/generations/'.$id, $ids),
            array_map(fn (string $url): string => parse_url($url, PHP_URL_PATH), $polls));
        // Only the rejected image is refunded; the delivered one stays charged and downloadable.
        $this->assertSame(990, UserToken::getBalance($user->id));
        $delivered = $this->getJson($polls[0])->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('billing_status', 'settled')->assertJsonPath('generation_ids', $ids);
        $this->get($delivered->json('outputs.0.url'))->assertOk();
        $this->getJson($polls[1])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('billing_status', 'released');
        $this->withToken(ApiKey::generate(User::factory()->create()->id)->plainKey);
        foreach ($polls as $url) {
            $this->getJson($url)->assertNotFound();
        }
    }

    public function test_creation_limiter_is_per_key_and_returns_openai_envelope(): void
    {
        [$user, $model] = $this->fixture();
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/v1/media/generations', $this->request($model))->assertAccepted();
        }
        $this->postJson('/v1/media/generations', $this->request($model))->assertStatus(429)->assertJsonPath('error.type', 'rate_limit_error');
        $secondKey = ApiKey::generate($user->id);
        $this->withToken($secondKey->plainKey)->postJson('/v1/media/generations', $this->request($model))->assertAccepted();
        $this->assertSame(790, UserToken::getBalance($user->id));
    }

    private function fixture(int $balance = 1000): array
    {
        $user = User::factory()->create(['expires_at' => now()->subDay(), 'permissions' => ['ai_api' => true, 'image_generator' => true, 'video_generator' => true]]);
        if ($balance > 0) {
            UserToken::topup($user->id, $balance);
        }
        $provider = AiProviderProfile::create(['slug' => 'private-provider', 'name' => 'Private provider', 'protocol' => 'fal',
            'base_url' => 'https://fal.run', 'api_key' => 'test-only-key', 'is_enabled' => true, 'status' => 'healthy', 'authenticated_at' => now()]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'public-image', 'upstream_model_id' => 'fal-ai/private-endpoint',
            'display_name' => 'Public Image', 'category' => 'image', 'is_enabled' => true, 'is_available' => true, 'token_cost' => 10]);
        $schema = ['type' => 'object', 'properties' => ['prompt' => ['type' => 'string'],
            'width' => ['type' => 'integer', 'enum' => [512, 1024], 'default' => 1024],
            'height' => ['type' => 'integer', 'enum' => [512, 1024], 'default' => 1024],
            'numberResults' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 1],
            'duration' => ['type' => 'integer', 'enum' => [5, 10], 'default' => 5]], 'required' => ['prompt']];
        $revision = MediaCapabilityRevision::create(['ai_model_profile_id' => $model->id, 'operation' => 'text_to_image', 'contract_version' => 2,
            'revision' => 1, 'status' => 'published', 'published_at' => now(), 'source_hash' => str_repeat('a', 64), 'source_schema' => ['fixture' => true],
            'definition' => ['model_public_id' => $model->model_id, 'operation' => 'text_to_image', 'output_kind' => 'image', 'contract_version' => 2,
                'inputs' => [], 'params' => [], 'input_schema' => $schema, 'output_schema' => ['type' => 'object']],
            'provider_bindings' => ['adapter' => 'fal_schema_v2', 'endpoint' => 'fal-ai/private-endpoint', 'transport' => 'direct',
                'quantity_input' => 'numberResults', 'request_schema' => $schema, 'output_schema' => ['type' => 'object']],
            'curation_overrides' => ['pricing' => ['token_cost' => 10, 'unit' => 'generation', 'reviewed_by' => $user->id,
                'reviewed_at' => now()->toISOString(), 'variable_configuration' => true]],
        ]);
        $key = ApiKey::generate($user->id);
        $this->withToken($key->plainKey);

        return [$user, $model, $revision, $key];
    }

    private function nativeImageModel(): AiModelProfile
    {
        $provider = AiProviderProfile::create(['slug' => 'native-fixture', 'name' => 'Images', 'protocol' => 'openai',
            'base_url' => 'https://images.example.test/v1', 'api_key' => 'test-only-key', 'is_enabled' => true]);

        return AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'native-fixture',
            'upstream_model_id' => 'dall-e-2', 'display_name' => 'Native image', 'category' => 'image',
            'is_enabled' => true, 'is_available' => true, 'token_cost' => 10]);
    }

    private function request(AiModelProfile $model, array $inputs = []): array
    {
        return ['model' => $model->model_id, 'operation' => 'text_to_image', 'inputs' => ['prompt' => 'A cup', ...$inputs]];
    }

    private function fakeImmediateResult(): void
    {
        Http::fake(['https://fal.run/fal-ai/private-endpoint' => function ($request) {
            return Http::response(['images' => array_fill(0, $request['numberResults'] ?? 1,
                ['url' => 'data:image/png;base64,'.self::PNG, 'content_type' => 'image/png'])]);
        }]);
    }
}
