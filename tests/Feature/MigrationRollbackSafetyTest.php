<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MigrationRollbackSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_rollback_refuses_long_sizes_before_changing_populated_lifecycle_data(): void
    {
        $user = User::factory()->create();
        $imageId = DB::table('image_jobs')->insertGetId([
            'user_id' => $user->id,
            'job_id' => 'd1d0fb79-ab52-4479-b6bc-dbc56276ae02',
            'model' => 'image-fixture-model',
            'prompt' => 'Saved image request',
            'size' => str_repeat('x', 25),
            'billing_mode' => 'tokens',
            'tokens_reserved' => 15,
            'asset_paths' => json_encode(['images/saved.png']),
        ]);
        $videoId = DB::table('video_jobs')->insertGetId([
            'user_id' => $user->id,
            'mode' => 'prompt',
            'model' => 'video-fixture-model',
            'prompt' => 'Saved video request',
            'stage' => 'processing',
            'billing_mode' => 'tokens',
            'tokens_reserved' => 200,
            'upstream_job_id' => 'saved-task',
            'poll_attempts' => 3,
        ]);
        $imageColumns = Schema::getColumnListing('image_jobs');
        $videoColumns = Schema::getColumnListing('video_jobs');
        $image = (array) DB::table('image_jobs')->find($imageId);
        $video = (array) DB::table('video_jobs')->find($videoId);
        $migration = require database_path('migrations/2026_09_16_100004_expand_media_job_lifecycle.php');

        try {
            $migration->down();
            $this->fail('A populated oversized image must prevent the entire media rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
        }

        $this->assertEqualsCanonicalizing($imageColumns, Schema::getColumnListing('image_jobs'));
        $this->assertEqualsCanonicalizing($videoColumns, Schema::getColumnListing('video_jobs'));
        $this->assertSame($image, (array) DB::table('image_jobs')->find($imageId));
        $this->assertSame($video, (array) DB::table('video_jobs')->find($videoId));
    }

    public function test_media_upgrade_classifies_legacy_jobs_without_changing_existing_values(): void
    {
        $migration = require database_path('migrations/2026_09_16_100004_expand_media_job_lifecycle.php');
        $migration->down();
        $user = User::factory()->create();
        $legacy = [];
        foreach (['pending', 'processing', 'completed', 'failed'] as $status) {
            $id = DB::table('video_jobs')->insertGetId([
                'user_id' => $user->id, 'mode' => 'prompt', 'model' => 'legacy-video',
                'prompt' => 'Preserved historical request', 'status' => $status,
                'billing_status' => in_array($status, ['pending', 'processing'], true) ? 'reserved' : 'settled',
                'billing_reserved_microusd' => 200_000,
            ]);
            $legacy[$id] = (array) DB::table('video_jobs')->find($id);
        }
        $migration->up();
        foreach ($legacy as $id => $before) {
            $after = (array) DB::table('video_jobs')->find($id);
            $this->assertSame($before, array_intersect_key($after, $before));
            $this->assertSame(in_array($before['status'], ['completed', 'failed'], true) ? $before['status'] : 'legacy', $after['stage']);
        }
    }

    public function test_conversation_rollback_refuses_unselected_models_without_changing_schema_or_data(): void
    {
        $user = User::factory()->create();
        $id = DB::table('chat_conversations')->insertGetId([
            'user_id' => $user->id,
            'title' => 'Model not selected',
            'model' => null,
        ]);
        $columns = Schema::getColumns('chat_conversations');
        $conversation = (array) DB::table('chat_conversations')->find($id);
        $migration = require database_path('migrations/2026_09_14_000003_make_chat_conversation_model_nullable.php');

        try {
            $migration->down();
            $this->fail('A conversation without a selected model must prevent the rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
        }

        $this->assertSame($columns, Schema::getColumns('chat_conversations'));
        $this->assertSame($conversation, (array) DB::table('chat_conversations')->find($id));
    }

    public function test_conversation_model_rollback_and_reapply_preserve_selection_without_fabricating_defaults(): void
    {
        $user = User::factory()->create();
        $id = DB::table('chat_conversations')->insertGetId([
            'user_id' => $user->id,
            'title' => 'Selected model',
            'model' => 'chat-fixture-model',
        ]);
        $migration = require database_path('migrations/2026_09_14_000003_make_chat_conversation_model_nullable.php');

        try {
            $migration->down();
            $this->assertDatabaseHas('chat_conversations', ['id' => $id, 'model' => 'chat-fixture-model']);

            try {
                DB::transaction(fn () => DB::table('chat_conversations')->insert([
                    'user_id' => $user->id,
                    'title' => 'Missing model',
                ]));
                $this->fail('Rollback must require a selected model rather than invent a default identity.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->errorInfo[0], ['23000', '23502']);
            }
        } finally {
            $migration->up();
        }

        $this->assertDatabaseHas('chat_conversations', ['id' => $id, 'model' => 'chat-fixture-model']);
        $unselectedId = DB::table('chat_conversations')->insertGetId([
            'user_id' => $user->id,
            'title' => 'Model selected later',
        ]);
        $this->assertDatabaseHas('chat_conversations', ['id' => $unselectedId, 'model' => null]);
    }
}
