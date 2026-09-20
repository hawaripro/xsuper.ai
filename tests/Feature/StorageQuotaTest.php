<?php

namespace Tests\Feature;

use App\Models\ImageJob;
use App\Models\MediaToolJob;
use App\Models\StorageUpgradePlan;
use App\Models\User;
use App\Models\UserStorageUpgrade;
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
