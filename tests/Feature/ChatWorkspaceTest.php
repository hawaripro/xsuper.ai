<?php

namespace Tests\Feature;

use App\Media\AssetService;
use App\Media\Enums\InputRole;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatWorkspace;
use App\Models\User;
use App\Services\ChatWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChatWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_history_backfill_keeps_owner_scoped_string_keys_and_numeric_metadata_separate(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $numeric = ChatConversation::create(['user_id' => $owner->id, 'title' => 'Unrelated old numeric chat']);
        $this->message($owner, (string) $numeric->id, 'My original message');
        $this->message($other, (string) $numeric->id, 'Private other message');
        $service = app(ChatWorkspaceService::class);

        $mine = $service->history($owner)['conversations'];
        $theirs = $service->history($other)['conversations'];
        $this->assertSame(['My original message'], array_column($mine, 'title'));
        $this->assertSame(['Private other message'], array_column($theirs, 'title'));
        $this->assertSame((string) $numeric->id, $mine[0]['conversation_id']);
        $this->assertNotSame($mine[0]['workspace_id'], $theirs[0]['workspace_id']);
        $this->assertNull($numeric->fresh()->conversation_key);
        $this->assertSame('My original message', $service->conversation($owner, (string) $numeric->id)['messages'][0]['content']);
        $service->history($owner);
        $this->assertSame(1, ChatWorkspace::where('user_id', $owner->id)->where('default_key', 'personal')->count());
        $this->assertSame(1, ChatConversation::where('user_id', $owner->id)->where('conversation_key', (string) $numeric->id)->count());
    }

    public function test_an_untitled_conversation_is_listed_without_a_stored_placeholder_title(): void
    {
        $owner = User::factory()->create();
        $service = app(ChatWorkspaceService::class);

        // No custom title and no text turn yet: the member's UI names it in their own language.
        $this->assertSame('', $service->createConversation($owner, ['conversation_id' => 'fresh'])['title']);
        $this->message($owner, 'titled', 'Plan the launch');
        $titles = array_column($service->history($owner)['conversations'], 'title', 'conversation_id');
        ksort($titles);
        $this->assertSame(['fresh' => '', 'titled' => 'Plan the launch'], $titles);
    }

    public function test_chat_text_columns_keep_the_case_insensitive_collation_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Collations exist only in the PostgreSQL profile.');
        }
        foreach ([['chat_history', 'content'], ['chat_history', 'model'], ['usage_logs', 'model']] as [$table, $column]) {
            $this->assertSame('xsuper_unicode_ci', DB::scalar('SELECT collation_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                ['public', $table, $column]), "{$table}.{$column} keeps accent- and case-insensitive search");
        }
    }

    public function test_notes_compare_and_swap_rejects_a_stale_save_without_losing_saved_content(): void
    {
        $owner = User::factory()->create();
        $service = app(ChatWorkspaceService::class);
        $workspace = $service->createWorkspace($owner, 'Research');
        $saved = $service->updateWorkspace($owner, $workspace->id, ['notes' => 'Saved by first editor', 'version' => 1]);
        $this->assertSame(2, $saved->version);

        try {
            $service->updateWorkspace($owner, $workspace->id, ['notes' => 'Stale overwrite', 'version' => 1]);
            $this->fail('A stale note save overwrote a newer version.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('Saved by first editor', $workspace->fresh()->notes);
        $this->assertSame(2, $workspace->fresh()->version);
    }

    public function test_history_search_is_literal_and_paginates_matching_messages_beyond_the_first_page(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(ChatWorkspaceService::class);
        $this->message($owner, 'older', 'literal %_! marker', now()->subDay());
        $this->message($owner, 'newer', 'another literal %_! marker', now());
        $this->message($owner, 'not-a-match', 'plain text without wildcards', now()->addMinute());
        $this->message($other, 'foreign', 'literal %_! marker', now()->addMinute());
        $service->updateConversation($owner, 'older', ['title' => 'Renamed research', 'pinned' => true]);

        $first = $service->history($owner, ['q' => '%_!', 'limit' => 1]);
        $this->assertSame(['older'], array_column($first['conversations'], 'conversation_id'));
        $this->assertSame('Renamed research', $first['conversations'][0]['title']);
        $second = $service->history($owner, ['q' => '%_!', 'limit' => 1, 'cursor' => $first['next_cursor']]);
        $this->assertSame(['newer'], array_column($second['conversations'], 'conversation_id'));
        $this->assertNull($second['next_cursor']);
        $this->assertSame('literal %_! marker', $service->conversation($owner, 'older')['messages'][0]['content']);
    }

    public function test_foreign_workspace_cannot_be_used_to_create_or_move_a_conversation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(ChatWorkspaceService::class);
        $workspace = $service->createWorkspace($other, 'Private');
        $conversation = $service->resolveConversation($owner, 'owned', true);

        try {
            $service->updateConversation($owner, 'owned', ['workspace_id' => $workspace->id]);
            $this->fail('A conversation moved into another owner workspace.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertNotSame($workspace->id, $conversation->fresh()->workspace_id);
        $this->expectException(HttpException::class);
        $service->resolveConversation($owner, 'new', true, $workspace->id);
    }

    public function test_attachment_resolution_rejects_another_conversation_and_expired_bytes(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $service = app(ChatWorkspaceService::class);
        $first = $service->resolveConversation($owner, 'first', true);
        $service->resolveConversation($owner, 'second', true);
        $asset = app(AssetService::class)->store($owner, UploadedFile::fake()->image('reference.png'), InputRole::ImageRef);
        $attachment = ChatAttachment::create([
            'user_id' => $owner->id, 'workspace_id' => $first->workspace_id,
            'conversation_id' => $first->id, 'asset_id' => $asset->id, 'name' => 'reference.png',
        ]);
        $this->assertSame([$attachment->id], $service->attachments($owner, 'first', [$attachment->id])->modelKeys());

        try {
            $service->attachments($owner, 'second', [$attachment->id]);
            $this->fail('An attachment from another conversation was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attachment_ids', $exception->errors());
        }
        $asset->update(['expires_at' => now()->subSecond()]);
        $listed = $service->listAttachments($owner, 'first');
        $this->assertSame([], $listed['attachments']);
        $this->assertSame([$attachment->id], array_column($listed['unavailable_attachments'], 'id'));
        $this->assertSame('error', $listed['unavailable_attachments'][0]['status']);
        $this->expectException(ValidationException::class);
        $service->attachments($owner, 'first', [$attachment->id]);
    }

    public function test_deletion_keeps_a_tombstone_so_the_same_key_cannot_resurrect_history(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(ChatWorkspaceService::class);
        $this->message($owner, 'shared-key', 'Remove this');
        $this->message($other, 'shared-key', 'Keep this');
        $operationId = (string) Str::uuid();
        DB::table('chat_operations')->insert([
            'id' => $operationId, 'user_id' => $owner->id, 'conversation_id' => 'shared-key',
            'client_request_id' => (string) Str::uuid(), 'fingerprint' => str_repeat('0', 64),
            'model' => 'chat-fixture', 'status' => 'streaming', 'partial_content' => 'Unfinished private answer',
            'context_snapshot' => json_encode(['notes' => 'Private notes']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service->deleteConversation($owner, 'shared-key');

        $this->assertSame([], $service->history($owner)['conversations']);
        $this->assertSame('Keep this', $service->conversation($other, 'shared-key')['messages'][0]['content']);
        $this->assertDatabaseMissing('chat_history', ['user_id' => $owner->id, 'conversation_id' => 'shared-key']);
        $operation = DB::table('chat_operations')->find($operationId);
        $this->assertSame('stopped', $operation->status);
        $this->assertNotNull($operation->stop_requested_at);
        $this->assertSame('', $operation->partial_content);
        $this->assertSame([], json_decode($operation->context_snapshot, true));
        try {
            $service->resolveConversation($owner, 'shared-key', true);
            $this->fail('A deleted conversation was recreated.');
        } catch (HttpException $exception) {
            $this->assertSame(410, $exception->getStatusCode());
        }
    }

    public function test_revoked_history_permission_does_not_grant_access_to_owned_history(): void
    {
        $owner = User::factory()->create(['permissions' => ['chat' => true, 'chat_history' => false]]);
        $this->message($owner, 'history', 'Private saved content');
        $this->expectException(HttpException::class);
        app(ChatWorkspaceService::class)->history($owner);
    }

    public function test_private_attachment_download_preserves_original_bytes_and_rejects_foreign_bindings(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(ChatWorkspaceService::class);
        $service->resolveConversation($owner, 'files', true);
        $service->resolveConversation($owner, 'other-chat', true);
        $service->resolveConversation($other, 'files', true);
        $contents = "Original UTF-8 notes: résumé\nSecond line.\n";
        $upload = $this->actingAs($owner)->post('/api/c/h/files/attachments', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', $contents),
        ])->assertCreated()->assertJsonPath('attachment.status', 'ready');
        $attachment = $upload->json('attachment');

        $this->get($attachment['download_url'])->assertOk()->assertStreamedContent($contents)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($attachment['preview_url'])->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'self'");
        $this->getJson('/api/c/h/other-chat/attachments/'.$attachment['id'].'/download')->assertNotFound();
        $this->actingAs($other)->getJson($attachment['download_url'])->assertNotFound();
    }

    public function test_an_image_disguised_under_another_extension_is_rejected_at_upload(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        app(ChatWorkspaceService::class)->resolveConversation($owner, 'files', true);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');

        // The member declared a text file; accepting it as an image would only fail later, at send time.
        $this->actingAs($owner)->postJson('/api/c/h/files/attachments', ['file' => UploadedFile::fake()->createWithContent('palsu.txt', $png)])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The attached bytes do not match their declared file type.');
        $this->assertSame(0, ChatAttachment::query()->count());
        $this->postJson('/api/c/h/files/attachments', ['file' => UploadedFile::fake()->createWithContent('photo.png', $png)])->assertCreated();
        $this->postJson('/api/c/h/files/attachments', ['file' => UploadedFile::fake()->createWithContent('pasted', $png)])->assertCreated();
    }

    public function test_domain_migration_backfills_both_owners_without_rewriting_live_history(): void
    {
        $migration = require database_path('migrations/2026_09_23_120000_create_chat_workspaces_and_attachments.php');
        $migration->down();
        try {
            $owner = User::factory()->create();
            $other = User::factory()->create();
            $numericId = DB::table('chat_conversations')->insertGetId([
                'user_id' => $owner->id, 'title' => 'Separate numeric metadata', 'model' => 'legacy-model',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $mine = $this->message($owner, '0017', 'Preserve my legacy bytes', '2026-07-02 10:11:12');
            $theirs = $this->message($other, '0017', 'Preserve their legacy bytes', '2026-07-01 08:09:10');
            $before = DB::table('chat_history')->whereIn('id', [$mine, $theirs])->orderBy('id')->get()->toArray();
        } finally {
            $migration->up();
        }

        $after = DB::table('chat_history')->whereIn('id', [$mine, $theirs])->orderBy('id')->get()->toArray();
        $this->assertEquals($before, $after);
        $this->assertNull(ChatConversation::findOrFail($numericId)->conversation_key);
        $metadata = ChatConversation::where('conversation_key', '0017')->orderBy('user_id')->get();
        $this->assertSame([$owner->id, $other->id], $metadata->pluck('user_id')->all());
        $this->assertNotSame($metadata[0]->workspace_id, $metadata[1]->workspace_id);
        $service = app(ChatWorkspaceService::class);
        $this->assertSame('Preserve my legacy bytes', $service->conversation($owner, '0017')['messages'][0]['content']);
        $this->assertSame('Preserve their legacy bytes', $service->conversation($other, '0017')['messages'][0]['content']);
    }

    private function message(User $user, string $key, string $content, mixed $createdAt = null): int
    {
        return DB::table('chat_history')->insertGetId([
            'user_id' => $user->id, 'conversation_id' => $key, 'role' => 'user',
            'content' => $content, 'model' => 'chat-fixture', 'created_at' => $createdAt ?? now(),
        ]);
    }
}
