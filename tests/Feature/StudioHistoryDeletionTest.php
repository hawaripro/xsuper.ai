<?php

namespace Tests\Feature;

use App\Models\ImageJob;
use App\Models\User;
use App\Models\VideoJob;
use App\Services\GeneratedVideoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudioHistoryDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        return User::factory()->create(['permissions' => ['video_generator' => true], 'expires_at' => now()->addDays(7)]);
    }

    private function imageJob(User $user, string $status = 'completed', ?string $assetPath = null): ImageJob
    {
        return ImageJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'model' => 'gemini-image', 'prompt' => 'a cat',
            'size' => '1024x1024', 'quantity' => 1, 'status' => $status, 'stage' => $status,
            'asset_paths' => $assetPath ? ['0' => ['path' => $assetPath, 'mime' => 'image/png', 'bytes' => 3]] : null,
        ]);
    }

    private function videoJob(User $user, string $status = 'completed'): VideoJob
    {
        return VideoJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'mode' => 'prompt', 'model' => 'seedance-2.5', 'prompt' => 'a wave',
            'status' => $status, 'stage' => $status, 'tokens_reserved' => 0, 'billing_mode' => 'admin', 'billing_status' => 'settled',
        ]);
    }

    public function test_owner_deletes_a_finished_image_job_with_its_assets(): void
    {
        Storage::fake('local');
        $user = $this->member();
        Storage::disk('local')->put('generated-images/test-asset.png', 'png');
        $job = $this->imageJob($user, 'completed', 'generated-images/test-asset.png');
        $kept = $this->imageJob($user, 'completed');

        $this->actingAs($user)->deleteJson('/api/images/'.$job->job_id)->assertOk()->assertJsonPath('deleted_count', 1);

        $this->assertDatabaseMissing('image_jobs', ['id' => $job->id]);
        $this->assertDatabaseHas('image_jobs', ['id' => $kept->id]);
        Storage::disk('local')->assertMissing('generated-images/test-asset.png');
    }

    public function test_running_jobs_are_protected_and_other_accounts_get_404(): void
    {
        $user = $this->member();
        $other = $this->member();
        $running = $this->imageJob($user, 'processing');
        $video = $this->videoJob($user, 'processing');

        $this->actingAs($user)->deleteJson('/api/images/'.$running->job_id)->assertUnprocessable();
        $this->actingAs($user)->deleteJson('/api/v/'.$video->job_id)->assertUnprocessable();
        $this->actingAs($other)->deleteJson('/api/images/'.$running->job_id)->assertNotFound();
        $this->assertDatabaseHas('image_jobs', ['id' => $running->id]);
        $this->assertDatabaseHas('video_jobs', ['id' => $video->id]);
    }

    public function test_clear_all_removes_only_finished_jobs_and_video_assets(): void
    {
        Storage::fake('local');
        $user = $this->member();
        $done = $this->videoJob($user, 'completed');
        $failed = $this->videoJob($user, 'failed');
        $running = $this->videoJob($user, 'processing');
        Storage::disk('local')->put(GeneratedVideoStore::path($done->job_id), 'mp4');

        $this->actingAs($user)->deleteJson('/api/v/history')->assertOk()->assertJsonPath('deleted_count', 2);

        $this->assertDatabaseMissing('video_jobs', ['id' => $done->id]);
        $this->assertDatabaseMissing('video_jobs', ['id' => $failed->id]);
        $this->assertDatabaseHas('video_jobs', ['id' => $running->id]);
        Storage::disk('local')->assertMissing(GeneratedVideoStore::path($done->job_id));

        $this->actingAs($user)->deleteJson('/api/images')->assertOk()->assertJsonPath('deleted_count', 0);
    }
}
