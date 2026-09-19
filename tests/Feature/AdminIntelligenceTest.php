<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\ContentController;
use App\Models\AnalyticsEvent;
use App\Models\AuditEvent;
use App\Models\ContentBlock;
use App\Models\DurationOrder;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Routing\RouteCollection;
use Tests\TestCase;

class AdminIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $router = app('router');
        $applicationRoutes = $router->getRoutes()->getRoutes();
        $router->setRoutes(new RouteCollection);
        config(['services.umami.id' => null]);

        Route::middleware(['web', 'auth'])->prefix('api')->group(function (): void {
            Route::post('/analytics/events', [AnalyticsController::class, 'store']);

            Route::middleware('admin')->prefix('admin')->group(function (): void {
                Route::get('/content', [ContentController::class, 'index']);
                Route::post('/content', [ContentController::class, 'store']);
                Route::put('/content/{contentBlock}', [ContentController::class, 'update']);
                Route::post('/content/{contentBlock}/publish', [ContentController::class, 'publish']);
                Route::get('/analytics/funnel', [AnalyticsController::class, 'funnel']);
                Route::get('/audit', [AuditController::class, 'index']);
            });
        });

        foreach ($applicationRoutes as $route) {
            $router->getRoutes()->add($route);
        }
    }

    public function test_drafts_are_private_and_publishing_a_locale_snapshot_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $draft = [
            'headline' => 'Ruang baru untuk idemu',
            'description' => 'Deskripsi terbit khusus Bahasa Indonesia.',
            'primary_action' => ['label' => 'Mulai', 'url' => '/pricing'],
        ];

        $created = $this->actingAs($admin)->postJson('/api/admin/content', [
            'key' => 'home.hero',
            'locale' => 'id',
            'draft' => $draft,
        ])->assertCreated()
            ->assertJsonPath('data.key', 'home.hero')
            ->assertJsonPath('data.locale', 'id')
            ->assertJsonPath('data.is_published', false);

        $blockId = $created->json('data.id');

        $this->get('/')->assertOk()->assertDontSee($draft['headline']);

        $this->postJson("/api/admin/content/{$blockId}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.published.headline', $draft['headline']);

        $this->get('/')
            ->assertOk()
            ->assertSee($draft['headline'])
            ->assertSee($draft['description']);
        $this->get('/en')->assertOk()->assertDontSee($draft['headline']);

        $audit = AuditEvent::where('action', 'content.published')->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(ContentBlock::class, $audit->subject_type);
        $this->assertSame($blockId, $audit->subject_id);
        $this->assertSame(['key' => 'home.hero', 'locale' => 'id'], $audit->metadata);
    }

    public function test_each_locale_uses_its_published_value_and_invalid_or_missing_content_falls_back_to_config(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([
            'id' => ['headline' => 'Judul Indonesia', 'description' => 'Deskripsi Indonesia'],
            'en' => ['headline' => 'English headline', 'description' => 'English description'],
        ] as $locale => $draft) {
            $blockId = $this->actingAs($admin)->postJson('/api/admin/content', [
                'key' => 'home.hero',
                'locale' => $locale,
                'draft' => $draft,
            ])->assertCreated()->json('data.id');

            $this->postJson("/api/admin/content/{$blockId}/publish")->assertOk();
        }

        ContentBlock::create([
            'key' => 'home.faq',
            'locale' => 'id',
            'draft' => ['items' => []],
            'published' => ['items' => [['question' => ['not a string'], 'answer' => 'Broken']]],
            'is_published' => true,
            'published_at' => now(),
            'updated_by' => $admin->id,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Judul Indonesia')
            ->assertDontSee('English headline')
            ->assertSee(config('marketing.faqs.0.question'))
            ->assertDontSee('Broken');

        $this->get('/en')
            ->assertOk()
            ->assertSee('English headline')
            ->assertDontSee('Judul Indonesia');
    }


    public function test_internal_hero_actions_keep_the_public_locale_while_external_actions_are_preserved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $block = ContentBlock::create([
            'key' => 'home.hero',
            'locale' => 'en',
            'draft' => [
                'headline' => 'English headline',
                'description' => 'English description',
                'primary_action' => ['label' => 'View plans', 'url' => '/pricing'],
            ],
            'published' => [
                'headline' => 'English headline',
                'description' => 'English description',
                'primary_action' => ['label' => 'View plans', 'url' => '/pricing'],
            ],
            'is_published' => true,
            'published_at' => now(),
            'updated_by' => $admin->id,
        ]);

        $this->get('/en')->assertOk()->assertSee('href="/en/pricing"', false);

        $external = $block->published;
        $external['primary_action']['url'] = 'https://status.xsuper.dev';
        $block->update(['draft' => $external, 'published' => $external]);

        $this->get('/en')->assertOk()->assertSee('href="https://status.xsuper.dev"', false);
    }
    public function test_content_keys_and_each_payload_schema_are_strictly_validated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $valid = [
            'home.hero' => [
                'headline' => 'Build more',
                'description' => 'One place for every idea.',
                'primary_action' => ['label' => 'View plans', 'url' => '/pricing'],
            ],
            'home.faq' => [
                'items' => [['question' => 'What is XSuper.ai?', 'answer' => 'A private AI workspace.']],
            ],
            'system.announcement' => [
                'message' => 'Scheduled maintenance tonight.',
                'level' => 'warning',
                'action' => ['label' => 'Read status', 'url' => 'https://status.xsuper.dev'],
            ],
            'help.articles' => [
                'items' => [[
                    'slug' => 'getting-started',
                    'title' => 'Getting started',
                    'summary' => 'Open your workspace.',
                    'body' => 'Choose an available model and begin a conversation.',
                ]],
            ],
        ];

        foreach ($valid as $key => $draft) {
            $this->postJson('/api/admin/content', compact('key', 'draft') + ['locale' => 'en'])
                ->assertCreated();
        }

        $this->assertDatabaseCount('content_blocks', 4);

        $this->postJson('/api/admin/content', [
            'key' => 'home.unknown',
            'locale' => 'id',
            'draft' => ['headline' => 'No'],
        ])->assertUnprocessable()->assertJsonValidationErrors('key');

        $this->postJson('/api/admin/content', [
            'key' => 'home.hero',
            'locale' => 'id',
            'draft' => ['headline' => 'Missing description', 'tracking_pixel' => 'https://example.test/pixel'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['draft', 'draft.description']);

        $this->postJson('/api/admin/content', [
            'key' => 'home.faq',
            'locale' => 'id',
            'draft' => ['items' => [['question' => 'Incomplete']]],
        ])->assertUnprocessable()->assertJsonValidationErrors('draft.items.0.answer');

        $this->postJson('/api/admin/content', [
            'key' => 'system.announcement',
            'locale' => 'id',
            'draft' => ['message' => 'Unsafe link', 'action' => ['label' => 'Open', 'url' => 'javascript:alert(1)']],
        ])->assertUnprocessable()->assertJsonValidationErrors('draft.action.url');
    }

    public function test_content_updates_replace_only_the_draft_until_the_next_publish(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $block = ContentBlock::create([
            'key' => 'home.hero',
            'locale' => 'id',
            'draft' => ['headline' => 'Published title', 'description' => 'Published copy'],
            'published' => ['headline' => 'Published title', 'description' => 'Published copy'],
            'is_published' => true,
            'published_at' => now(),
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)->putJson("/api/admin/content/{$block->id}", [
            'draft' => ['headline' => 'Next draft', 'description' => 'Not public yet'],
        ])->assertOk()
            ->assertJsonPath('data.draft.headline', 'Next draft')
            ->assertJsonPath('data.published.headline', 'Published title');

        $this->get('/')
            ->assertOk()
            ->assertSee('Published title')
            ->assertDontSee('Next draft');
    }

    public function test_analytics_ingest_rejects_unknown_events_and_minimizes_member_data(): void
    {
        $member = User::factory()->create(['role' => 'member']);

        $this->actingAs($member)->postJson('/api/analytics/events', [
            'name' => 'account.password_captured',
            'properties' => ['email' => 'member@example.test'],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->postJson('/api/analytics/events', [
            'name' => 'checkout.started',
            'session_id' => 'session-123',
            'path' => '/pricing?token=secret#checkout',
            'user_id' => 999999,
            'properties' => [
                'package' => '1_month',
                'source' => 'pricing_page',
                'email' => 'private@example.test',
                'prompt' => 'private prompt',
                'api_key' => 'secret-key',
                'nested' => ['password' => 'secret'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'checkout.started')
            ->assertJsonPath('data.properties.package', '1_month')
            ->assertJsonMissingPath('data.properties.email');

        $event = AnalyticsEvent::sole();
        $this->assertSame($member->id, $event->user_id);
        $this->assertSame('/pricing', $event->path);
        $this->assertSame([
            'package' => '1_month',
            'source' => 'pricing_page',
        ], $event->properties);

        auth()->logout();
        $this->postJson('/api/analytics/events', ['name' => 'app.opened'])->assertUnauthorized();
    }

    public function test_admin_funnel_uses_real_scoped_event_user_and_order_counts(): void
    {
        $this->travelTo(now()->startOfDay());
        $admin = User::factory()->create(['role' => 'admin']);
        $firstMember = User::factory()->create(['role' => 'member']);
        $secondMember = User::factory()->create(['role' => 'member']);

        AnalyticsEvent::create(['user_id' => $firstMember->id, 'name' => 'app.opened']);
        AnalyticsEvent::create(['user_id' => $firstMember->id, 'name' => 'checkout.started']);
        AnalyticsEvent::create(['user_id' => $secondMember->id, 'name' => 'app.opened']);
        AnalyticsEvent::create(['user_id' => $secondMember->id, 'name' => 'app.opened'])
            ->forceFill(['created_at' => now()->subDays(45), 'updated_at' => now()->subDays(45)])
            ->save();

        DurationOrder::create([
            'user_id' => $firstMember->id,
            'package' => '1_month',
            'days' => 30,
            'price' => 55000,
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        DurationOrder::create([
            'user_id' => $secondMember->id,
            'package' => '1_week',
            'days' => 7,
            'price' => 20000,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->getJson('/api/admin/analytics/funnel?days=30')
            ->assertOk()
            ->assertJsonPath('counts.events', 3)
            ->assertJsonPath('counts.unique_event_users', 2)
            ->assertJsonPath('counts.total_users', 2)
            ->assertJsonPath('counts.new_users', 2)
            ->assertJsonPath('counts.orders', 2)
            ->assertJsonPath('counts.approved_orders', 1)
            ->assertJsonPath('counts.revenue_idr', 55000)
            ->assertJsonPath('event_counts', ['app.opened' => 2, 'checkout.started' => 1])
            ->assertJsonPath('funnel.registered_users', 2)
            ->assertJsonPath('funnel.engaged_users', 2)
            ->assertJsonPath('funnel.orders_created', 2)
            ->assertJsonPath('funnel.orders_approved', 1);

        $this->actingAs($firstMember)->getJson('/api/admin/analytics/funnel')->assertForbidden();
    }

    public function test_audit_feed_is_admin_only_paginated_and_filterable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['role' => 'member']);
        $block = ContentBlock::create([
            'key' => 'home.hero',
            'locale' => 'id',
            'draft' => ['headline' => 'Draft'],
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin);
        app(AuditService::class)->record($admin, 'content.published', $block, ['key' => 'home.hero']);
        app(AuditService::class)->record($admin, 'content.published', $block, ['key' => 'home.hero']);
        app(AuditService::class)->record($otherAdmin, 'content.updated', $block, ['key' => 'home.hero']);

        $response = $this->getJson('/api/admin/audit?'.http_build_query([
            'action' => 'content.published',
            'actor_id' => $admin->id,
            'subject_type' => ContentBlock::class,
            'subject_id' => $block->id,
            'from' => now()->subMinute()->toISOString(),
            'to' => now()->addMinute()->toISOString(),
            'per_page' => 1,
        ]))->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'content.published')
            ->assertJsonPath('data.0.actor.id', $admin->id)
            ->assertJsonPath('data.0.subject_type', ContentBlock::class);

        $this->assertArrayHasKey('metadata', $response->json('data.0'));

        $this->actingAs($member)->getJson('/api/admin/audit')->assertForbidden();
        auth()->logout();
        $this->getJson('/api/admin/audit')->assertUnauthorized();
    }
}
