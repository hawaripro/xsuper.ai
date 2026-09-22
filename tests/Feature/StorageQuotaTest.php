<?php

namespace Tests\Feature;

use App\Models\ImageJob;
use App\Models\MediaAsset;
use App\Models\MediaToolJob;
use App\Models\StorageUpgradePlan;
use App\Models\ThreeDJob;
use App\Models\User;
use App\Models\UserStorageUpgrade;
use App\Models\VideoJob;
use App\Services\GeneratedModel3dStore;
use App\Services\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function completedTool(User $user, int $bytes, ?Carbon $createdAt = null): MediaToolJob
    {
        $job = MediaToolJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'kind' => 'download', 'format' => 'mp4',
            'status' => 'completed', 'stage' => 'completed', 'size_bytes' => $bytes, 'title' => 'clip',
            'dispatched_at' => now(), 'completed_at' => now(),
        ]);
        if ($createdAt) {
            $job->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $job;
    }

    private function completedImage(User $user, string $path, Carbon $createdAt): ImageJob
    {
        Storage::disk('local')->put($path, str_repeat('x', 32));
        $job = ImageJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'model' => 'm', 'prompt' => 'p',
            'status' => 'completed', 'asset_paths' => [['path' => $path, 'mime' => 'image/png']],
        ]);
        $job->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $job;
    }

    public function test_used_bytes_and_quota_count_only_active_upgrades(): void
    {
        config(['storage_quota.base_bytes' => 1000]);
        $user = User::factory()->create();
        $service = app(StorageQuotaService::class);
        $this->completedTool($user, 600);

        $this->assertSame(600, $service->usedBytes($user));
        $this->assertSame(1000, $service->quotaBytes($user));

        UserStorageUpgrade::create(['user_id' => $user->id, 'plan_key' => 'p', 'extra_bytes' => 500, 'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(5)]);
        UserStorageUpgrade::create(['user_id' => $user->id, 'plan_key' => 'p', 'extra_bytes' => 9999, 'starts_at' => now()->subDays(10), 'expires_at' => now()->subDay()]);

        $this->assertSame(1500, $service->quotaBytes($user));
    }

    public function test_over_quota_blocks_members_but_not_admins(): void
    {
        config(['storage_quota.base_bytes' => 1000]);
        $service = app(StorageQuotaService::class);
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->completedTool($member, 1200);
        $this->completedTool($admin, 5000);

        $this->assertTrue($service->exceeded($member));
        $this->assertFalse($service->exceeded($admin));
    }

    public function test_media_endpoint_returns_413_when_storage_is_full(): void
    {
        config(['storage_quota.base_bytes' => 1000]);
        $user = User::factory()->create(['expires_at' => now()->addDays(5)]);
        $this->completedTool($user, 1500);

        $this->actingAs($user)->postJson('/api/images', [])
            ->assertStatus(413)
            ->assertJsonPath('code', 'storage_full');
    }

    public function test_weekly_purge_removes_old_non_admin_media_only(): void
    {
        Storage::fake('local');
        config(['storage_quota.retention_days' => 7]);
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $old = $this->completedImage($member, 'generated/images/old/0.png', now()->subDays(10));
        $recent = $this->completedImage($member, 'generated/images/new/0.png', now()->subDays(2));
        $adminOld = $this->completedImage($admin, 'generated/images/adm/0.png', now()->subDays(10));

        app(StorageQuotaService::class)->purge(now()->subDays(7));

        $this->assertDatabaseMissing('image_jobs', ['id' => $old->id]);
        $this->assertFalse(Storage::disk('local')->exists('generated/images/old/0.png'));
        $this->assertDatabaseHas('image_jobs', ['id' => $recent->id]);
        $this->assertTrue(Storage::disk('local')->exists('generated/images/new/0.png'));
        $this->assertDatabaseHas('image_jobs', ['id' => $adminOld->id]);
        $this->assertTrue(Storage::disk('local')->exists('generated/images/adm/0.png'));
    }

    public function test_reference_quota_counts_real_files_once_even_when_registry_and_history_share_a_path(): void
    {
        Storage::fake('local');
        $member = User::factory()->create();
        $this->completedImage($member, 'generated/images/shared/0.png', now());
        foreach (['generated/images/shared/0.png', 'media-assets/missing.png', 'media-assets/separate.png'] as $path) {
            MediaAsset::create([
                'user_id' => $member->id, 'media_type' => 'image', 'role' => 'image_ref',
                'storage_disk' => 'local', 'storage_path' => $path, 'size_bytes' => 999999,
                'mime' => 'image/png', 'signature_ok' => true, 'retention_status' => 'active',
            ]);
        }
        Storage::disk('local')->put('media-assets/separate.png', str_repeat('x', 48));

        $this->assertSame(80, app(StorageQuotaService::class)->usedBytes($member));
    }

    public function test_3d_retention_removes_all_output_files_but_preserves_a_reference_while_work_is_active(): void
    {
        Storage::fake('local');
        $member = User::factory()->create();
        $asset = MediaAsset::create([
            'user_id' => $member->id, 'media_type' => 'image', 'role' => 'image_ref',
            'storage_disk' => 'local', 'storage_path' => 'media-assets/3d-reference.png', 'size_bytes' => 100,
            'mime' => 'image/png', 'signature_ok' => true, 'retention_status' => 'active', 'expires_at' => now()->subMinute(),
        ]);
        Storage::disk('local')->put($asset->storage_path, str_repeat('i', 100));
        $id = (string) Str::uuid();
        $job = ThreeDJob::create([
            'user_id' => $member->id, 'job_id' => $id, 'model' => '3d-model', 'model_label' => '3D model',
            'operation' => 'image_to_3d', 'upstream_model_id' => 'fixture', 'routing_identity' => 'fixture',
            'connection_fingerprint' => str_repeat('a', 64), 'generation_config' => [],
            'price_tokens' => 60, 'dedup_key' => str_repeat('b', 64), 'payload_fingerprint' => str_repeat('c', 64),
            'reference_asset_ids' => [$asset->id], 'settings' => [], 'status' => 'processing', 'stage' => 'rendering',
            'billing_reference_id' => 'model3d:'.$id, 'tokens_reserved' => 60,
        ]);
        $service = app(StorageQuotaService::class);
        $video = VideoJob::create([
            'user_id' => $member->id, 'job_id' => (string) Str::uuid(), 'mode' => 'prompt',
            'model' => 'video-model', 'prompt' => 'Active reference', 'status' => 'processing',
            'has_reference' => true, 'reference_asset_ids' => [$asset->id],
        ]);
        $video->forceFill(['created_at' => now()->subDays(10)])->save();
        $service->purge(now()->subDays(7));
        Storage::disk('local')->assertExists($asset->storage_path);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);

        $this->assertDatabaseHas('video_jobs', ['id' => $video->id, 'status' => 'processing']);
        $path = GeneratedModel3dStore::path($id);
        Storage::disk('local')->put($path, str_repeat('g', 72));
        Storage::disk('local')->put($path.'.part', 'interrupted');
        $job->forceFill([
            'status' => 'completed', 'stage' => 'completed', 'model_path' => $path,
            'model_url' => '/api/3d/'.$id.'/asset', 'size_bytes' => 999999, 'created_at' => now()->subDays(10),
        ])->save();
        $video->update(['status' => 'failed']);
        $this->assertSame(172, $service->usedBytes($member));
        $counts = $service->purge(now()->subDays(7));

        $this->assertSame(1, $counts['model3d']);
        $this->assertSame(1, $counts['media_asset']);
        $this->assertDatabaseMissing('three_d_jobs', ['id' => $job->id]);
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('local')->assertMissing([$path, $path.'.part', $asset->storage_path]);
        $this->assertSame(0, $service->usedBytes($member));
    }

    public function test_granting_an_upgrade_raises_the_quota(): void
    {
        config(['storage_quota.base_bytes' => 1000]);
        $user = User::factory()->create();
        $service = app(StorageQuotaService::class);

        $service->grantUpgrade($user, 'plus', 2000, 30);

        $this->assertSame(3000, $service->quotaBytes($user));
    }

    public function test_member_can_checkout_and_place_a_storage_order(): void
    {
        $user = User::factory()->create(['expires_at' => now()->addDays(5)]);
        StorageUpgradePlan::create([
            'key' => 'plus_2gb', 'label' => '+2 GB', 'extra_bytes' => 2 * 1024 ** 3, 'days' => 30,
            'price_idr' => 25000, 'price_usd' => 1.6, 'is_active' => true, 'sort_order' => 0,
        ]);

        $reference = $this->actingAs($user)->postJson('/api/storage/checkout', ['plan' => 'plus_2gb'])
            ->assertStatus(201)->json('checkout.payment_reference');
        $this->actingAs($user)->postJson('/api/storage/order', ['plan' => 'plus_2gb', 'payment_reference' => $reference])
            ->assertStatus(201);

        $this->assertDatabaseHas('storage_upgrade_orders', ['user_id' => $user->id, 'plan_key' => 'plus_2gb', 'status' => 'pending']);
    }
}
