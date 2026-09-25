<?php

namespace Tests\Feature\Media;

use App\Media\CapabilityResolver;
use App\Media\Enums\MediaOperation;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use App\Models\UserToken;
use App\Models\WorkspaceMediaJob;
use App\Services\AiProviderEndpoint;
use App\Services\MediaCatalogService;
use App\Services\MediaModelConfig;
use App\Services\RunwareCatalogSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RunwareCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../../Fixtures/runware';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
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

    public function test_offline_import_creates_unpublished_candidates_and_reimport_keeps_curation(): void
    {
        [$admin, $provider] = $this->connection();

        $summary = $this->import($provider);

        $this->assertSame([9, 6, 3, 0, 0], [$summary['discovered'], $summary['imported'], $summary['skipped'], $summary['published'], $summary['network_requests']]);
        $flux = AiModelProfile::query()->where('model_id', 'runware/bfl-flux-1-dev')->firstOrFail();
        $this->assertSame(['runware:101@1', 'FLUX.1 [dev]', 'image', false, true, null], [$flux->upstream_model_id, $flux->display_name,
            $flux->category, $flux->is_enabled, $flux->is_available, $flux->token_cost]);
        $this->assertSame('Open-weight 12B text to image model for rich visuals', $flux->description_en);
        $this->assertSame('https://assets.runware.ai/07bfd96d-d2df-4d52-aba5-636fb27874be.png', $flux->logo_url);
        $this->assertSame(6, AiModelProfile::query()->where('provider_id', $provider->id)->count());
        $this->assertSame(['image_edit' => 'imported', 'text_to_image' => 'imported'],
            $flux->capabilityRevisions()->orderBy('operation')->pluck('status', 'operation')->all());
        $kling = AiModelProfile::query()->where('upstream_model_id', 'klingai:kling-video@2.6-pro')->firstOrFail();
        $this->assertSame('needs_handling', $kling->capabilityRevisions()->where('operation', 'video_to_video')->value('status'));

        $revision = $flux->capabilityRevisions()->where('operation', 'text_to_image')->firstOrFail();
        $this->assertSame(['runware:101@1', 'imageInference'], [$revision->source_metadata['air'], $revision->source_metadata['task_type']]);
        $this->assertSame(['currency', 'overview', 'basis', 'rates', 'measured', 'examples', 'catalog_unit', 'note'], array_keys($revision->source_metadata['pricing']));
        $this->assertSame('runware:bfl-flux-1-dev', $revision->source_schema_ref);
        Http::assertNothingSent();

        $flux->update(['display_name' => 'Curated FLUX', 'category' => 'image', 'description_en' => 'Curated copy',
            'logo_url' => 'https://cdn.example.test/flux.png', 'token_cost' => 7, 'is_enabled' => true]);
        $revisions = MediaCapabilityRevision::query()->count();
        $repeat = $this->import($provider);

        $this->assertSame([0, 0], [$repeat['imported'], $repeat['revisions']]);
        $this->assertSame($revisions, MediaCapabilityRevision::query()->count());
        $this->assertSame(['Curated FLUX', 'Curated copy', 'https://cdn.example.test/flux.png', 7, true],
            [$flux->fresh()->display_name, $flux->fresh()->description_en, $flux->fresh()->logo_url, $flux->fresh()->token_cost, $flux->fresh()->is_enabled]);
        $offline = app(MediaCatalogService::class)->renormalize($flux, $admin);
        $this->assertSame([false, ['text_to_image', 'image_edit']], [$offline['created'], $offline['operations']]);
    }

    public function test_public_discovery_pages_the_catalog_without_credentials_and_skips_unsupported_models(): void
    {
        [$admin, $provider] = $this->connection();
        $this->fakeCatalog();
        $url = '/api/admin/ai/providers/'.$provider->id.'/discover';

        $this->actingAs($admin)->postJson($url, ['limit' => 11])->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->postJson($url, ['limit' => 5, 'cursor' => 'Mg=='])->assertUnprocessable();
        Http::assertNothingSent();

        $this->postJson($url, ['limit' => 5])->assertOk()->assertJson(['discovered' => 5, 'imported' => 5, 'skipped' => 0, 'total' => 9, 'next_cursor' => '5']);
        $this->postJson($url, ['limit' => 5, 'cursor' => '5'])->assertOk()->assertJson(['discovered' => 4, 'imported' => 1, 'skipped' => 3, 'total' => 9, 'next_cursor' => null]);

        $this->assertSame(6, AiModelProfile::query()->where('provider_id', $provider->id)->count());
        Http::assertNotSent(static fn (Request $request): bool => $request->hasHeader('Authorization') || str_contains($request->url(), 'api.runware.ai'));
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'bytedance-seedance-1-5-pro') || str_contains($request->url(), 'anthropic'));
        $this->assertSame('healthy', $provider->fresh()->status, 'public catalog discovery never changes the verified connection');
    }

    public function test_publication_needs_the_catalog_unit_a_verified_provider_and_a_compatible_candidate(): void
    {
        [$admin, $provider] = $this->connection(['status' => 'unknown', 'authenticated_at' => null]);
        $this->import($provider);
        $flux = AiModelProfile::query()->where('model_id', 'runware/bfl-flux-1-dev')->firstOrFail();
        $kling = AiModelProfile::query()->where('model_id', 'runware/klingai-video-2-6-pro')->firstOrFail();
        $text = $flux->capabilityRevisions()->where('operation', 'text_to_image')->firstOrFail();
        $url = '/api/admin/ai/providers/'.$provider->id.'/catalog-bulk';
        $body = fn (AiModelProfile $model, MediaCapabilityRevision $revision, int $price, string $unit): array => [
            'items' => [['model_id' => $model->id, 'revision_id' => $revision->id, 'token_cost' => $price, 'price_unit' => $unit]],
            'expected_count' => 1, 'action' => 'publish', 'reviewed' => true, 'confirm' => true];

        $listed = collect($this->actingAs($admin)->getJson('/api/admin/ai/providers/'.$provider->id.'/models?per_page=100')->assertOk()->json('models'))->keyBy('model_id');
        $this->assertSame(['generation', 'second'], [$listed['runware/bfl-flux-1-dev']['catalog_price_unit'], $listed['runware/klingai-video-2-6-pro']['catalog_price_unit']]);
        $this->assertTrue($listed['runware/bfl-flux-1-dev']['generation_config_readonly']);

        $this->postJson($url, $body($flux, $text, 10, 'request'))->assertUnprocessable()->assertJsonValidationErrors('items.0.price_unit');
        $this->postJson($url, $body($flux, $text, 10, 'generation'))->assertUnprocessable()->assertJsonValidationErrors('items.0.model_id');
        $this->assertNull($flux->fresh()->token_cost);

        $provider->update(['status' => 'healthy', 'authenticated_at' => now()]);
        $this->postJson($url, $body($flux, $text, 10, 'generation'))->assertOk()
            ->assertJsonPath('items.0.status', 'published')->assertJsonPath('items.0.price_unit', 'generation');
        $config = MediaModelConfig::forOperation($flux->fresh('provider'), 'text_to_image');
        $this->assertSame(['generation', 'numberResults'], [$config['price_unit'], $config['quantity_input']]);
        $this->assertTrue($flux->fresh()->is_enabled);

        $video = $kling->capabilityRevisions()->where('operation', 'text_to_video')->firstOrFail();
        $this->postJson($url, $body($kling, $video, 3, 'generation'))->assertUnprocessable()->assertJsonValidationErrors('items.0.price_unit');
        $this->postJson($url, $body($kling, $video, 3, 'second'))->assertOk()->assertJsonPath('items.0.price_unit', 'second');
        $this->assertSame('second', MediaModelConfig::forOperation($kling->fresh('provider'), 'text_to_video')['price_unit']);

        // An operation whose output length follows its input cannot be metered per second.
        $blocked = $kling->capabilityRevisions()->where('operation', 'video_to_video')->firstOrFail();
        $response = $this->postJson('/api/admin/ai/capabilities/'.$blocked->id.'/publish', [
            'reviewed' => true, 'price_review' => ['token_cost' => 3, 'unit' => 'second', 'variable_configuration' => true],
        ])->assertUnprocessable();
        $this->assertStringContainsString('cannot be priced per second', implode(' ', $response->json('blockers')));
        $this->assertNotSame('published', $blocked->fresh()->status);
    }

    public function test_member_quote_equals_the_reservation_and_never_exposes_provider_data(): void
    {
        [$admin, $provider] = $this->connection();
        $this->import($provider);
        $flux = AiModelProfile::query()->where('model_id', 'runware/bfl-flux-1-dev')->firstOrFail();
        $text = $flux->capabilityRevisions()->where('operation', 'text_to_image')->firstOrFail();
        $this->actingAs($admin)->postJson('/api/admin/ai/providers/'.$provider->id.'/catalog-bulk', [
            'items' => [['model_id' => $flux->id, 'revision_id' => $text->id, 'token_cost' => 10, 'price_unit' => 'generation']],
            'expected_count' => 1, 'action' => 'publish', 'reviewed' => true, 'confirm' => true,
        ])->assertOk();
        $member = User::factory()->create(['permissions' => ['image_generator' => true]]);
        UserToken::topup($member->id, 100);

        $response = $this->actingAs($member)->getJson('/api/media/workspace/capabilities?model='.urlencode($flux->model_id))->assertOk();
        $capability = $response->json('capabilities.text_to_image');
        foreach (['runware:101@1', 'provider_bindings', 'request_schema', 'source_metadata', 'x-pricing', 'catalog_unit', '0.0038', 'deliveryMethod', 'runware-private'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame(['queue', 'numberResults', 4], [$capability['execution']['transport'], $capability['billing']['quantity_input'], $capability['billing']['max_quantity']]);
        $this->assertSame('Coastal Radar Station Rental Interior', $capability['examples'][0]['title']);
        $this->assertArrayNotHasKey('cost', $capability['output_schema']['properties']);

        $inputs = ['positivePrompt' => 'A red cup on a table', 'width' => 1024, 'height' => 1024, 'numberResults' => 3];
        $quote = $capability['price_tokens'] * $inputs['numberResults'];
        $job = $this->postJson('/api/media/workspace/jobs', [
            'model' => $flux->model_id, 'operation' => 'text_to_image', 'inputs' => $inputs, 'idempotency_key' => (string) Str::uuid(),
            'expected_capability_hash' => app(CapabilityResolver::class)->resolve($flux, MediaOperation::TextToImage, schemaContracts: true)->sourceHash,
            'expected_price_tokens' => $quote,
        ])->assertStatus(202)->json('job');

        $this->assertSame([30, 30], [$job['price_tokens'], WorkspaceMediaJob::query()->where('job_id', $job['id'])->value('tokens_reserved')]);
        $this->assertSame(70, UserToken::getBalance($member->id));
    }

    public function test_air_identifiers_are_accepted_without_loosening_other_ids_and_sync_is_refused(): void
    {
        [$admin, $provider] = $this->connection();
        $store = fn (string $id, string $upstream) => $this->actingAs($admin)->postJson('/api/admin/ai/models', [
            'model_id' => $id, 'provider_slug' => $provider->slug, 'upstream_model_id' => $upstream, 'display_name' => 'Manual', 'category' => 'chat',
        ]);

        $store('manual@model', 'klingai:kling-video@2.6-pro')->assertUnprocessable()->assertJsonValidationErrors('model_id');
        $store('runware/manual', 'klingai@kling-video')->assertUnprocessable()->assertJsonValidationErrors('upstream_model_id');
        $store('runware/manual', 'klingai:kling-video@2.6-pro')->assertCreated();

        $this->postJson('/api/admin/ai/providers/'.$provider->id.'/sync')->assertUnprocessable();
        $this->assertSame(['healthy', true], [$provider->fresh()->status, $provider->fresh()->authenticated_at !== null]);
    }

    public function test_a_failed_schema_fetch_never_shadows_the_captured_source(): void
    {
        [$admin, $provider] = $this->connection();
        $this->import($provider);
        $kling = AiModelProfile::query()->where('model_id', 'runware/klingai-video-2-6-pro')->firstOrFail();
        $revisions = MediaCapabilityRevision::query()->count();
        $page = app(RunwareCatalogSource::class)->directoryPage(self::FIXTURES, 0, 10);
        $bundle = collect($page['models'])->firstWhere('model_id', 'klingai-video-2-6-pro');

        $summary = app(MediaCatalogService::class)->importRunware($provider, $admin,
            [[...$bundle, 'openapi' => null, 'fetch_error' => 'The public Runware schema for this model is unavailable. Discover it again.']]);

        $this->assertSame([0, 1], [$summary['revisions'], $summary['skipped']]);
        $this->assertSame($revisions, MediaCapabilityRevision::query()->count());
        $this->assertSame('second', MediaModelConfig::catalogPriceUnit($kling->fresh()), 'a transient failure keeps per-second billing');
        $offline = app(MediaCatalogService::class)->renormalize($kling->fresh(), $admin);
        $this->assertSame([false, ['text_to_video', 'image_to_video', 'video_to_video']], [$offline['created'], $offline['operations']]);
        $this->assertTrue($kling->fresh()->is_available);
    }

    private function import(AiProviderProfile $provider): array
    {
        $this->assertSame(0, Artisan::call('media:import-runware-catalog', ['provider' => $provider->id, '--from' => self::FIXTURES]));
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

        return json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
    }

    private function fakeCatalog(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $file = match (true) {
                $url === 'https://runware.ai/docs/models/index.json' => 'index.json',
                $url === 'https://content.runware.ai/models' => 'content-models.json',
                $url === 'https://content.runware.ai/creators' => 'creators.json',
                preg_match('~^https://runware\.ai/docs/models/([a-z0-9.-]+)/(schema|examples)\.json$~', $url, $match) === 1
                    => ($match[2] === 'schema' ? 'schemas/' : 'examples/').$match[1].'.json',
                default => null,
            };

            return $file !== null && is_file(self::FIXTURES.'/'.$file)
                ? Http::response(file_get_contents(self::FIXTURES.'/'.$file), 200, ['Content-Type' => 'application/json'])
                : Http::response(['message' => 'Not found'], 404);
        });
    }

    private function connection(array $overrides = []): array
    {
        return [User::factory()->create(['role' => 'admin']), AiProviderProfile::create([
            'slug' => 'runware', 'name' => 'Runware', 'protocol' => 'runware', 'base_url' => 'https://api.runware.ai/v1',
            'api_key' => 'runware-private-key', 'is_enabled' => true, 'status' => 'healthy', 'authenticated_at' => now(), ...$overrides,
        ])];
    }
}
