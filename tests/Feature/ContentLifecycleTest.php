<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ContentController;
use App\Models\AuditEvent;
use App\Models\ContentBlock;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['services.umami.id' => null]);
        Http::fake(['*/v1/models' => Http::response(['data' => []])]);
    }

    private function heroDraft(string $headline, ?array $action = null): array
    {
        return [
            'headline' => $headline,
            'description' => 'Lifecycle description.',
            'primary_action' => $action ?? ['label' => 'Start', 'url' => '/pricing'],
        ];
    }

    public function test_publish_then_draft_edit_preserves_snapshot_then_unpublish_hides_then_republish(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $id = $this->postJson('/api/admin/content', [
            'key' => 'home.hero',
            'locale' => 'id',
            'draft' => $this->heroDraft('Draft headline'),
        ])->assertCreated()->json('data.id');

        $this->get('/')->assertOk()->assertDontSee('Draft headline');

        $this->postJson("/api/admin/content/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.published.headline', 'Draft headline');

        $this->get('/')->assertOk()->assertSee('Draft headline');

        $snapshot = ContentBlock::findOrFail($id)->published;

        // Edit the draft: published snapshot and public serving must be unchanged.
        $this->putJson("/api/admin/content/{$id}", [
            'draft' => $this->heroDraft('Edited draft headline'),
        ])->assertOk()->assertJsonPath('data.draft.headline', 'Edited draft headline');

        $block = ContentBlock::findOrFail($id);
        $this->assertSame('Edited draft headline', $block->draft['headline']);
        $this->assertSame($snapshot, $block->published);
        $this->assertTrue($block->is_published);

        $this->get('/')->assertOk()->assertSee('Draft headline')->assertDontSee('Edited draft headline');

        // Unpublish: draft preserved, snapshot preserved, no longer served.
        $this->postJson("/api/admin/content/{$id}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.is_published', false);

        $block = $block->fresh();
        $this->assertFalse($block->is_published);
        $this->assertSame('Edited draft headline', $block->draft['headline']);
        $this->assertSame($snapshot, $block->published);

        $this->get('/')->assertOk()->assertDontSee('Draft headline')->assertDontSee('Edited draft headline');

        // Republish: serves the current draft again.
        $this->postJson("/api/admin/content/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.published.headline', 'Edited draft headline');

        $this->get('/')->assertOk()->assertSee('Edited draft headline');
    }

    public function test_unpublish_is_idempotent_and_records_no_duplicate_audit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $id = $this->postJson('/api/admin/content', [
            'key' => 'home.hero',
            'locale' => 'id',
            'draft' => $this->heroDraft('Headline'),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/content/{$id}/unpublish")->assertOk();
        $this->assertSame(0, AuditEvent::where('action', 'content.unpublished')->count());

        $this->postJson("/api/admin/content/{$id}/publish")->assertOk();

        $this->postJson("/api/admin/content/{$id}/unpublish")->assertOk();
        $this->assertSame(1, AuditEvent::where('action', 'content.unpublished')->count());
        $updatedByBefore = ContentBlock::findOrFail($id)->updated_by;
        $timestampBefore = ContentBlock::findOrFail($id)->updated_at;

        // Repeat: no new audit, no state change.
        $this->travel(5)->seconds();
        $this->postJson("/api/admin/content/{$id}/unpublish")->assertOk();
        $this->assertSame(1, AuditEvent::where('action', 'content.unpublished')->count());

        $block = ContentBlock::findOrFail($id);
        $this->assertFalse($block->is_published);
        $this->assertSame($updatedByBefore, $block->updated_by);
        $this->assertEquals($timestampBefore, $block->updated_at);
        $this->travelBack();
    }

    public function test_unpublish_hides_dashboard_announcement_for_every_member(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create();

        $id = $this->actingAs($admin)->postJson('/api/admin/content', [
            'key' => 'system.announcement',
            'locale' => 'id',
            'draft' => [
                'message' => 'Maintenance at midnight',
                'level' => 'warning',
                'surfaces' => ['dashboard'],
                'action' => ['label' => 'Status', 'url' => 'https://status.ultrai.id'],
            ],
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)->postJson("/api/admin/content/{$id}/publish")->assertOk();

        $this->actingAs($member)
            ->getJson('/api/content/announcement?locale=id')
            ->assertOk()
            ->assertJsonPath('announcement.message', 'Maintenance at midnight');

        $this->actingAs($admin)->postJson("/api/admin/content/{$id}/unpublish")->assertOk();

        $this->actingAs($member)
            ->getJson('/api/content/announcement?locale=id')
            ->assertOk()
            ->assertJsonPath('announcement', null);

        // Audit event visible via the admin audit feed.
        $this->actingAs($admin)
            ->getJson('/api/admin/audit?action=content.unpublished')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'content.unpublished');
    }

    public function test_unpublish_requires_admin_for_member_and_guest(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create();
        $block = ContentBlock::create([
            'key' => 'home.hero',
            'locale' => 'id',
            'draft' => $this->heroDraft('Headline'),
            'published' => $this->heroDraft('Headline'),
            'is_published' => true,
            'published_at' => now(),
            'updated_by' => $admin->id,
        ]);

        $this->postJson("/api/admin/content/{$block->id}/unpublish")->assertUnauthorized();

        $this->actingAs($member)
            ->postJson("/api/admin/content/{$block->id}/unpublish")
            ->assertForbidden();

        $this->assertTrue($block->fresh()->is_published);
    }

    public function test_invalid_draft_is_rejected_and_existing_content_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $this->postJson('/api/admin/content', [
            'key' => 'home.hero',
            'locale' => 'id',
            'draft' => $this->heroDraft('Unsafe', ['label' => 'Open', 'url' => 'javascript:alert(1)']),
        ])->assertUnprocessable()->assertJsonValidationErrors('draft.primary_action.url');

        $this->assertSame(0, ContentBlock::count());

        $id = $this->postJson('/api/admin/content', [
            'key' => 'home.faq',
            'locale' => 'id',
            'draft' => ['items' => [['question' => 'Q?', 'answer' => 'A.']]],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/admin/content/{$id}", [
            'draft' => ['items' => 'not-an-array'],
        ])->assertUnprocessable()->assertJsonValidationErrors('draft.items');

        $this->assertSame('Q?', ContentBlock::findOrFail($id)->draft['items'][0]['question']);
    }

    public function test_optional_action_fields_are_accepted_and_stored_safely(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $id = $this->postJson('/api/admin/content', [
            'key' => 'home.hero',
            'locale' => 'id',
            'draft' => [
                'headline' => 'No action headline',
                'description' => 'No action description.',
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/content/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.published.headline', 'No action headline')
            ->assertJsonPath('data.published.primary_action', null);

        $this->get('/')->assertOk()->assertSee('No action headline');

        $this->postJson('/api/admin/content', [
            'key' => 'system.announcement',
            'locale' => 'id',
            'draft' => ['message' => 'Plain notice', 'surfaces' => ['landing']],
        ])->assertCreated()
            ->assertJsonPath('data.draft.message', 'Plain notice')
            ->assertJsonPath('data.draft.action', null);
    }

    public function test_publish_refreshes_a_route_bound_block_after_concurrent_unpublish(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $id = $this->actingAs($admin)->postJson('/api/admin/content', [
            'key' => 'home.hero', 'locale' => 'en', 'draft' => $this->heroDraft('Original draft'),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/content/{$id}/publish")->assertOk();
        $boundBeforeUnpublish = ContentBlock::findOrFail($id);
        $this->postJson("/api/admin/content/{$id}/unpublish")->assertOk();
        $this->putJson("/api/admin/content/{$id}", ['draft' => $this->heroDraft('Latest locked draft')])->assertOk();
        $request = Request::create("/api/admin/content/{$id}/publish", 'POST');
        $request->setUserResolver(fn () => $admin);
        app(ContentController::class)->publish(
            $request, $boundBeforeUnpublish, app(AuditService::class),
        );
        $block = ContentBlock::findOrFail($id);
        $this->assertTrue($block->is_published);
        $this->assertSame('Latest locked draft', $block->published['headline']);
        $this->get('/en')->assertOk()->assertSee('Latest locked draft');
    }
}
