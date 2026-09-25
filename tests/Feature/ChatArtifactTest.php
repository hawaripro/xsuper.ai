<?php

namespace Tests\Feature;

use App\Models\ChatArtifactRevision;
use App\Models\ImageJob;
use App\Models\User;
use App\Services\ChatWorkspaceService;
use App\Services\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatArtifactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function conversation(User $user, string $key): void
    {
        app(ChatWorkspaceService::class)->resolveConversation($user, $key, true);
    }

    public function test_saved_and_downloaded_revisions_preserve_whitespace_and_original_bytes(): void
    {
        $user = User::factory()->create();
        $this->conversation($user, 'exact-bytes');
        $content = "  <script>parent.location='https://invalid.example'</script>\r\n<p>é</p>\n  ";
        $saved = $this->actingAs($user)->postJson('/api/c/h/exact-bytes/artifacts', [
            'filename' => 'document.html', 'kind' => 'html', 'content' => $content,
        ])->assertCreated()->assertJsonPath('artifact.content', $content)->assertJsonPath('artifact.version', 1);
        $id = $saved->json('artifact.id');
        $revision = $saved->json('artifact.revision_id');

        $download = $this->get('/api/c/artifacts/'.$id.'/download')->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString("sandbox", $download->headers->get('Content-Security-Policy'));
        $this->assertSame($content, $download->streamedContent());

        $this->postJson('/api/c/artifacts/'.$id.'/revisions', [
            'base_version' => 1, 'content' => '',
        ])->assertCreated()->assertJsonPath('artifact.content', '')->assertJsonPath('artifact.version', 2);
        $this->getJson('/api/c/artifacts/'.$id.'/revisions/'.$revision)
            ->assertOk()->assertJsonPath('artifact.content', $content)->assertJsonPath('artifact.version', 1)
            ->assertJsonPath('artifact.latest_version', 2);
        $this->get('/api/c/artifacts/'.$id.'/download?revision='.$revision)
            ->assertOk()->assertStreamedContent($content);
    }

    public function test_an_older_revision_downloads_under_a_versioned_name(): void
    {
        $user = User::factory()->create();
        $this->conversation($user, 'versioned-names');
        $saved = $this->actingAs($user)->postJson('/api/c/h/versioned-names/artifacts', [
            'filename' => 'closure.js', 'kind' => 'code', 'content' => 'const a = 1;',
        ])->assertCreated();
        $id = $saved->json('artifact.id');
        $first = $saved->json('artifact.revision_id');
        $this->postJson('/api/c/artifacts/'.$id.'/revisions', ['base_version' => 1, 'content' => 'const a = 2;'])->assertCreated();
        $name = fn (string $query): string => $this->get('/api/c/artifacts/'.$id.'/download'.$query)->assertOk()->headers->get('Content-Disposition');

        // Two versions of one file must not arrive as the same ambiguous name.
        $this->assertStringContainsString('filename=closure.js', $name(''));
        $this->assertStringContainsString('filename=closure-v1.js', $name('?revision='.$first));
    }

    public function test_stale_revision_cannot_overwrite_current_or_historical_content(): void
    {
        $user = User::factory()->create();
        $this->conversation($user, 'revision-race');
        $saved = $this->actingAs($user)->postJson('/api/c/h/revision-race/artifacts', [
            'filename' => 'notes.md', 'content' => 'Original',
        ])->assertCreated();
        $id = $saved->json('artifact.id');
        $this->postJson('/api/c/artifacts/'.$id.'/revisions', [
            'base_version' => 1, 'content' => 'First editor',
        ])->assertCreated()->assertJsonPath('artifact.version', 2);
        $this->postJson('/api/c/artifacts/'.$id.'/revisions', [
            'base_version' => 1, 'content' => 'Stale editor',
        ])->assertConflict()->assertJsonPath('current_version', 2);
        $this->getJson('/api/c/artifacts/'.$id)->assertOk()->assertJsonPath('artifact.content', 'First editor');
        $this->assertSame(2, ChatArtifactRevision::where('artifact_id', $id)->count());
    }

    public function test_artifact_and_revision_ownership_and_message_provenance_are_enforced(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->conversation($owner, 'owner-chat');
        $this->conversation($owner, 'different-chat');
        $this->conversation($other, 'other-chat');
        $source = DB::table('chat_history')->insertGetId([
            'user_id' => $other->id, 'conversation_id' => 'other-chat',
            'role' => 'assistant', 'content' => 'Private source', 'created_at' => now(),
        ]);
        $this->actingAs($owner)->postJson('/api/c/h/owner-chat/artifacts', [
            'filename' => 'stolen.txt', 'content' => 'Private source', 'source_message_id' => $source,
        ])->assertNotFound();
        $source = DB::table('chat_history')->insertGetId([
            'user_id' => $owner->id, 'conversation_id' => 'different-chat',
            'role' => 'assistant', 'content' => 'Wrong chat', 'created_at' => now(),
        ]);
        $this->postJson('/api/c/h/owner-chat/artifacts', [
            'filename' => 'wrong-chat.txt', 'content' => 'Wrong chat', 'source_message_id' => $source,
        ])->assertNotFound();
        $saved = $this->postJson('/api/c/h/owner-chat/artifacts', [
            'filename' => 'private.txt', 'content' => 'Private',
        ])->assertCreated();
        $id = $saved->json('artifact.id');
        $revision = $saved->json('artifact.revision_id');
        $this->actingAs($other)->getJson('/api/c/artifacts/'.$id)->assertNotFound();
        $this->get('/api/c/artifacts/'.$id.'/download')->assertNotFound();
        $this->getJson('/api/c/artifacts/'.$id.'/revisions/'.$revision)->assertNotFound();
        $this->postJson('/api/c/artifacts/'.$id.'/revisions', [
            'base_version' => 1, 'content' => 'Hijacked',
        ])->assertNotFound();
    }

    public function test_revision_id_from_a_different_artifact_does_not_expose_content(): void
    {
        $user = User::factory()->create();
        $this->conversation($user, 'revision-scope');
        $this->actingAs($user);
        $first = $this->postJson('/api/c/h/revision-scope/artifacts', [
            'filename' => 'first.txt', 'content' => 'First',
        ])->assertCreated()->json('artifact');
        $second = $this->postJson('/api/c/h/revision-scope/artifacts', [
            'filename' => 'second.txt', 'content' => 'Second',
        ])->assertCreated()->json('artifact');
        $this->getJson('/api/c/artifacts/'.$first['id'].'/revisions/'.$second['revision_id'])->assertNotFound();
        $this->get('/api/c/artifacts/'.$first['id'].'/download?revision='.$second['revision_id'])->assertNotFound();
    }

    public function test_invalid_filename_binary_content_and_ambiguous_source_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->conversation($user, 'validation');
        $this->actingAs($user)->postJson('/api/c/h/validation/artifacts', [
            'filename' => '../unsafe.html', 'content' => '<p>test</p>',
        ])->assertUnprocessable()->assertJsonValidationErrors('filename');
        $this->postJson('/api/c/h/validation/artifacts', [
            'filename' => 'binary.txt', 'content' => "a\0b",
        ])->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->postJson('/api/c/h/validation/artifacts', [
            'filename' => 'data.json', 'content' => '{broken',
        ])->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->postJson('/api/c/h/validation/artifacts', [
            'filename' => 'both.txt', 'content' => 'Text',
            'generated_output' => ['job_id' => '1970a4e8-9f93-4c82-80cf-f3c9a6083a0a', 'output_id' => '0'],
        ])->assertUnprocessable();
        $this->postJson('/api/c/h/validation/artifacts', ['generated_output' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('generated_output');
        $this->getJson('/api/c/artifacts/not-a-uuid')->assertNotFound();
    }

    public function test_each_immutable_revision_must_fit_remaining_storage_before_it_is_persisted(): void
    {
        config(['storage_quota.base_bytes' => 10]);
        $user = User::factory()->create(['role' => 'member']);
        $this->conversation($user, 'quota');
        $saved = $this->actingAs($user)->postJson('/api/c/h/quota/artifacts', [
            'filename' => 'quota.txt', 'content' => '123456',
        ])->assertCreated();
        $id = $saved->json('artifact.id');
        $this->postJson('/api/c/artifacts/'.$id.'/revisions', [
            'base_version' => 1, 'content' => '12345',
        ])->assertStatus(413);
        $this->getJson('/api/c/artifacts/'.$id)->assertOk()->assertJsonPath('artifact.version', 1)
            ->assertJsonPath('artifact.content', '123456');
        $this->assertSame(1, ChatArtifactRevision::where('artifact_id', $id)->count());
    }

    public function test_generated_media_references_are_private_bound_to_the_chat_and_noneditable_without_duplicate_bytes(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->conversation($owner, 'generated-chat');
        $this->conversation($owner, 'unrelated-chat');
        $this->conversation($other, 'other-generated-chat');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nO0AAAAASUVORK5CYII=');
        $jobId = (string) Str::uuid();
        $path = 'generated/images/'.$jobId.'/0.png';
        Storage::disk('local')->put($path, $bytes);
        $job = ImageJob::create([
            'user_id' => $owner->id, 'job_id' => $jobId, 'model' => 'fixture',
            'prompt' => 'fixture', 'status' => 'completed', 'asset_paths' => [['path' => $path, 'mime' => 'image/png']],
        ]);
        // Production binds a chat only after the owner check, never by mass assignment.
        $job->forceFill(['conversation_id' => 'generated-chat'])->save();
        $reference = ['generated_output' => ['job_id' => 'image:'.$job->job_id, 'output_id' => '0']];
        $before = app(StorageQuotaService::class)->usedBytes($owner);
        $saved = $this->actingAs($owner)->postJson('/api/c/h/generated-chat/artifacts', $reference)
            ->assertCreated()->assertJsonPath('artifact.editable', false)->assertJsonMissingPath('artifact.content');
        $id = $saved->json('artifact.id');
        $this->assertSame($before, app(StorageQuotaService::class)->usedBytes($owner));
        $this->get('/api/c/artifacts/'.$id.'/download')->assertOk()->assertStreamedContent($bytes);
        $this->get('/api/c/artifacts/'.$id.'/preview')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->postJson('/api/c/artifacts/'.$id.'/revisions', ['base_version' => 1, 'content' => 'not binary'])
            ->assertUnprocessable();
        $this->postJson('/api/c/h/unrelated-chat/artifacts', $reference)->assertNotFound();
        $this->actingAs($other)->postJson('/api/c/h/other-generated-chat/artifacts', $reference)->assertNotFound();
        $this->get('/api/c/artifacts/'.$id.'/preview')->assertNotFound();
    }

    public function test_disabling_history_access_also_denies_saved_artifacts(): void
    {
        $user = User::factory()->create();
        $this->conversation($user, 'history-permission');
        $saved = $this->actingAs($user)->postJson('/api/c/h/history-permission/artifacts', [
            'filename' => 'private.txt', 'content' => 'Private',
        ])->assertCreated();
        $user->update(['permissions' => ['chat' => true, 'chat_history' => false]]);
        $this->actingAs($user->fresh())->getJson('/api/c/h/history-permission/artifacts')->assertForbidden();
        $this->get('/api/c/artifacts/'.$saved->json('artifact.id').'/download')->assertForbidden();
    }
}
