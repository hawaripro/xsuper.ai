<?php

namespace Tests\Feature\Media;

use App\Media\CapabilityResolver;
use App\Media\Enums\MediaOperation;
use App\Media\Exceptions\CapabilityConfigException;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use App\Models\UserToken;
use App\Services\AiProviderEndpoint;
use App\Services\ImageGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\FalCatalogFixture;
use Tests\TestCase;

class FalCatalogLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private array $catalogEntry = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['https://api.fal.ai/v1/models*' => fn () => Http::response(['models' => [$this->catalogEntry], 'has_more' => true, 'next_cursor' => 'Mg=='])]);
        $this->app->instance(AiProviderEndpoint::class, new class extends AiProviderEndpoint
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    public function test_repeating_a_page_preserves_curated_identity_price_and_unseen_availability(): void
    {
        [$admin, $provider] = $this->connection();
        $existing = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'public-image', 'upstream_model_id' => 'fal-ai/catalog-fixture',
            'display_name' => 'Curated label', 'category' => 'image', 'token_cost' => 47, 'is_enabled' => true, 'is_available' => true,
        ]);
        $unseen = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'other', 'display_name' => 'Other', 'category' => 'image', 'is_available' => true,
        ]);
        $this->fakePage(FalCatalogFixture::model());
        $url = '/api/admin/ai/providers/'.$provider->id.'/discover';
        $this->actingAs($admin)->postJson($url, ['limit' => 1])->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('next_cursor', 'Mg==');
        $this->postJson($url, ['limit' => 1])->assertOk()->assertJsonPath('imported', 0);
        $this->assertDatabaseCount('media_capabilities', 1);
        $this->assertSame('public-image', $existing->fresh()->model_id);
        $this->assertSame('Curated label', $existing->fresh()->display_name);
        $this->assertSame(47, $existing->fresh()->token_cost);
        $this->assertTrue($unseen->fresh()->is_available);
        $this->assertSame('public-image', MediaCapabilityRevision::firstOrFail()->definition['model_public_id']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['expand'] === 'openapi-3.0' && (int) $request['limit'] === 1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_schema_changes_stay_candidates_and_rollback_changes_future_resolution_only(): void
    {
        [$admin, $provider] = $this->connection();
        $this->fakePage(FalCatalogFixture::model());
        $this->actingAs($admin)->postJson('/api/admin/ai/providers/'.$provider->id.'/discover')->assertOk();
        $model = AiModelProfile::where('provider_id', $provider->id)->firstOrFail();
        $model->update(['token_cost' => 15]);
        $first = MediaCapabilityRevision::firstOrFail();
        $base = '/api/admin/ai/capabilities/';
        $this->postJson($base.$first->id.'/publish')->assertUnprocessable()->assertJsonStructure(['blockers']);
        $this->postJson($base.$first->id.'/publish', ['reviewed' => true])->assertOk()->assertJsonPath('revision.status', 'published');
        $job = ImageJob::create(['user_id' => $admin->id, 'job_id' => 'old-catalog-job', 'model' => $model->model_id, 'prompt' => 'A cup', 'size' => 'square', 'quantity' => 1, 'status' => 'completed', 'capability_revision_id' => $first->id]);
        $this->fakePage(FalCatalogFixture::model(sizes: ['wide']));
        $this->postJson('/api/admin/ai/providers/'.$provider->id.'/discover')->assertOk();
        $second = MediaCapabilityRevision::orderByDesc('id')->firstOrFail();
        $resolver = app(CapabilityResolver::class);
        $this->assertSame($first->id, $resolver->resolve($model, MediaOperation::TextToImage)->revisionId);
        $this->postJson($base.$second->id.'/publish', ['reviewed' => true])->assertOk();
        $this->assertSame('wide', $resolver->resolve($model, MediaOperation::TextToImage)->capability->param('size')->default);
        $this->postJson($base.$first->id.'/rollback')->assertOk()->assertJsonPath('revision.id', $first->id);
        $this->assertSame($first->id, $resolver->resolve($model, MediaOperation::TextToImage)->revisionId);
        $this->assertSame($first->id, $job->fresh()->capability_revision_id);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'ai_capability.rolled_back', 'subject_id' => $first->id]);
        $this->postJson($base.$first->id.'/disable')->assertOk();
        $this->expectException(CapabilityConfigException::class);
        $resolver->resolve($model, MediaOperation::TextToImage);
    }

    public function test_unknown_required_field_and_unhandled_output_prevent_reviewed_publication(): void
    {
        [$admin, $provider] = $this->connection();
        $entry = FalCatalogFixture::model();
        $entry['openapi']['components']['schemas']['Input']['required'][] = 'not_in_order';
        $entry['openapi']['components']['schemas']['Input']['properties']['not_in_order'] = ['type' => 'object'];
        $entry['openapi']['components']['schemas']['Output']['properties']['images']['items'] = ['type' => 'string'];
        $this->fakePage($entry);
        $this->actingAs($admin)->postJson('/api/admin/ai/providers/'.$provider->id.'/discover')->assertOk()->assertJsonPath('counts.needs_handling', 1);
        $revision = MediaCapabilityRevision::firstOrFail();
        $this->postJson('/api/admin/ai/capabilities/'.$revision->id.'/publish', ['reviewed' => true])->assertUnprocessable()->assertJsonStructure(['message', 'blockers']);
        $this->assertFalse($revision->model->fresh()->is_enabled);
        $this->assertNull($revision->fresh()->published_at);
    }

    public function test_public_discovery_does_not_authorize_publication_without_connection_verification(): void
    {
        [$admin, $provider] = $this->connection();
        $provider->update(['status' => 'unknown', 'authenticated_at' => null]);
        $this->fakePage(FalCatalogFixture::model());
        $this->actingAs($admin)->postJson('/api/admin/ai/providers/'.$provider->id.'/discover')->assertOk();
        $revision = MediaCapabilityRevision::firstOrFail();
        $revision->model->update(['token_cost' => 10]);
        $this->postJson('/api/admin/ai/capabilities/'.$revision->id.'/publish', ['reviewed' => true])
            ->assertUnprocessable()->assertJsonStructure(['blockers']);
        $this->assertSame('unknown', $provider->fresh()->status);
        $this->assertNull($revision->fresh()->published_at);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_new_image_model_recovers_result_storage_using_its_original_job_revision_without_resubmitting(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['media.coordinator_restricted' => false, 'media.kill_switch' => false]);
        [$admin, $provider] = $this->connection();
        UserToken::topup($admin->id, 100);
        $this->fakePage(FalCatalogFixture::model(sizes: ['square', 'auto']));
        $this->actingAs($admin)->postJson('/api/admin/ai/providers/'.$provider->id.'/discover')->assertOk();
        $model = AiModelProfile::where('provider_id', $provider->id)->firstOrFail();
        $model->update(['token_cost' => 15]);
        $first = MediaCapabilityRevision::firstOrFail();
        $this->postJson('/api/admin/ai/capabilities/'.$first->id.'/review', ['reviewed' => true, 'ui_metadata' => ['inputs' => ['prompt' => ['label' => 'Your scene']]]])->assertOk();
        $this->postJson('/api/admin/ai/capabilities/'.$first->id.'/publish')->assertOk();
        $catalog = $this->getJson('/api/images/models')->assertOk();
        $catalog->assertJsonPath('models.0.capabilities.text_to_image.ui.inputs.prompt.label', 'Your scene');
        $this->assertStringNotContainsString('provider_bindings', $catalog->getContent());
        $this->assertStringNotContainsString('fixture-secret', $catalog->getContent());
        $generation = app(ImageGenerationService::class);
        $job = $generation->generate($admin, $model->model_id, 'A small cup', 'auto', 1);
        $this->fakePage(FalCatalogFixture::model(sizes: ['wide']));
        $this->postJson('/api/admin/ai/providers/'.$provider->id.'/discover')->assertOk();
        $second = MediaCapabilityRevision::orderByDesc('id')->firstOrFail();
        $this->postJson('/api/admin/ai/capabilities/'.$second->id.'/publish', ['reviewed' => true])->assertOk();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
        $downloads = 0;
        Http::fake([
            'https://fal.run/fal-ai/catalog-fixture' => function ($request) {
                $this->assertSame('auto', $request['image_size']);
                $this->assertSame(1, $request['num_images']);
                $this->assertFalse($request['sync_mode']);

                return Http::response(['images' => [['url' => 'https://v3.fal.media/fixture.png']]]);
            },
            'https://v3.fal.media/fixture.png' => function ($request) use ($png, &$downloads) {
                $this->assertFalse($request->hasHeader('Authorization'));
                if (++$downloads === 1) {
                    return Http::response('', 503);
                }

                return Http::response($png, 200, ['Content-Type' => 'image/png']);
            },
        ]);
        $generation->process($job->id);
        $this->assertSame('processing', $job->fresh()->status);
        $this->assertSame(['https://v3.fal.media/fixture.png'], $job->fresh()->provider_result_urls);
        $this->assertArrayNotHasKey('provider_result_urls', $job->fresh()->toArray());
        $this->travel(6)->minutes();
        $generation->reconcileStaleReservations(3);
        $this->assertSame('reserved', $job->fresh()->billing_status);
        $this->travel(9)->seconds();
        $generation->poll($job->id);
        $generation->poll($job->id);
        $generation->process($job->id);
        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertSame($first->id, $job->capability_revision_id);
        $this->assertSame(['/api/images/'.$job->job_id.'/assets/0'], $job->result_urls);
        $this->assertSame(85, UserToken::getBalance($admin->id));
        Storage::disk('local')->assertExists($job->asset_paths[0]['path']);
        $this->assertNull($job->provider_result_urls);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://fal.run/fal-ai/catalog-fixture')->count());
    }

    private function connection(): array
    {
        return [User::factory()->create(['role' => 'admin']), AiProviderProfile::create([
            'slug' => 'fal-catalog', 'name' => 'Fal catalog', 'protocol' => 'fal', 'base_url' => 'https://fal.run', 'api_key' => 'fixture-secret', 'is_enabled' => true,
            'status' => 'healthy', 'authenticated_at' => now(),
        ])];
    }

    private function fakePage(array $entry): void
    {
        $this->catalogEntry = $entry;
    }
}
