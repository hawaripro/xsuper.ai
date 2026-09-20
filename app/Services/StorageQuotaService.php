<?php

namespace App\Services;

use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\MediaToolJob;
use App\Models\User;
use App\Models\UserStorageUpgrade;
use App\Models\VideoJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Per-user Library storage accounting: how many bytes a member's stored media
 * occupies, the quota (base 500 MB plus any active purchased upgrade), and the
 * weekly age-based purge. Admins are exempt from both the quota and the purge.
 */
class StorageQuotaService
{
    public function __construct(
        private readonly VideoReferenceStore $references,
        private readonly MediaToolService $mediaTools,
    ) {}

    public function baseBytes(): int
    {
        return (int) config('storage_quota.base_bytes', 500 * 1024 * 1024);
    }

    public function isExempt(User $user): bool
    {
        return $user->isAdmin();
    }

    public function activeUpgradeBytes(User $user): int
    {
        return (int) UserStorageUpgrade::query()->where('user_id', $user->id)->active()->sum('extra_bytes');
    }

    public function activeUpgradeExpiry(User $user): ?Carbon
    {
        $value = UserStorageUpgrade::query()->where('user_id', $user->id)->active()->max('expires_at');

        return $value ? Carbon::parse($value) : null;
    }

    public function quotaBytes(User $user): int
    {
        return $this->baseBytes() + $this->activeUpgradeBytes($user);
    }

    public function usedBytes(User $user): int
    {
        $disk = Storage::disk('local');
        $total = 0;

        ImageJob::query()->where('user_id', $user->id)->where('status', 'completed')
            ->get(['asset_paths'])->each(function (ImageJob $job) use (&$total, $disk): void {
                foreach ((array) $job->asset_paths as $asset) {
                    if (is_array($asset) && is_string($asset['path'] ?? null) && $disk->exists($asset['path'])) {
                        $total += $disk->size($asset['path']);
                    }
                }
            });

        VideoJob::query()->where('user_id', $user->id)
            ->where(fn ($query) => $query->where('status', 'completed')->orWhere('has_reference', true))
            ->get()->each(function (VideoJob $job) use (&$total, $disk): void {
                $path = GeneratedVideoStore::path($job->job_id);
                if ($job->status === 'completed' && $job->video_url === '/api/v/'.$job->job_id.'/asset' && $disk->exists($path)) {
                    $total += $disk->size($path);
                }
                if ($job->has_reference && ($reference = $this->references->existingPath($job)) !== null) {
                    $total += $disk->size($reference);
                }
            });

        AudioJob::query()->where('user_id', $user->id)->where('status', 'completed')
            ->get()->each(function (AudioJob $job) use (&$total, $disk): void {
                $path = GeneratedAudioStore::path($job->job_id);
                if ($job->audio_path === $path && $disk->exists($path)) {
                    $total += $job->size_bytes ?: $disk->size($path);
                }
            });

        $total += (int) MediaToolJob::query()->where('user_id', $user->id)->where('status', 'completed')->sum('size_bytes');

        return $total;
    }

    public function exceeded(User $user): bool
    {
        return ! $this->isExempt($user) && $this->usedBytes($user) >= $this->quotaBytes($user);
    }

    public function summary(User $user): array
    {
        $base = $this->baseBytes();
        $upgrade = $this->activeUpgradeBytes($user);
        $used = $this->usedBytes($user);
        $quota = $base + $upgrade;

        return [
            'used_bytes' => $used,
            'base_bytes' => $base,
            'upgrade_bytes' => $upgrade,
            'quota_bytes' => $quota,
            'remaining_bytes' => max(0, $quota - $used),
            'upgrade_expires_at' => $this->activeUpgradeExpiry($user)?->toISOString(),
            'unlimited' => $this->isExempt($user),
            'exceeded' => ! $this->isExempt($user) && $used >= $quota,
            'retention_days' => (int) config('storage_quota.retention_days', 7),
        ];
    }

    public function grantUpgrade(User $user, string $planKey, int $extraBytes, int $days, ?int $orderId = null): UserStorageUpgrade
    {
        $now = now();

        return UserStorageUpgrade::create([
            'user_id' => $user->id,
            'plan_key' => $planKey,
            'extra_bytes' => $extraBytes,
            'starts_at' => $now,
            'expires_at' => $now->copy()->addDays($days),
            'order_id' => $orderId,
        ]);
    }

    /**
     * Weekly retention: delete Library media older than the cutoff for every
     * non-admin account. Returns the number of source rows removed per kind.
     */
    public function purge(?CarbonInterface $cutoff = null): array
    {
        $cutoff ??= now()->subDays((int) config('storage_quota.retention_days', 7));
        $adminIds = User::query()->where('role', 'admin')->pluck('id')->all();
        $disk = Storage::disk('local');
        $counts = ['image' => 0, 'video' => 0, 'reference' => 0, 'audio' => 0, 'media_tool' => 0];

        ImageJob::query()->whereNotIn('user_id', $adminIds)->where('status', 'completed')
            ->where('created_at', '<', $cutoff)->orderBy('id')->chunkById(100, function ($jobs) use (&$counts, $disk): void {
                foreach ($jobs as $job) {
                    foreach ((array) $job->asset_paths as $asset) {
                        if (is_array($asset) && is_string($asset['path'] ?? null)) {
                            $disk->delete($asset['path']);
                        }
                    }
                    $job->delete();
                    $counts['image']++;
                }
            });

        VideoJob::query()->whereNotIn('user_id', $adminIds)
            ->where(fn ($query) => $query->where('status', 'completed')->orWhere('has_reference', true))
            ->where('created_at', '<', $cutoff)->orderBy('id')->chunkById(100, function ($jobs) use (&$counts, $disk): void {
                foreach ($jobs as $job) {
                    $path = GeneratedVideoStore::path($job->job_id);
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                        $counts['video']++;
                    }
                    if ($job->has_reference && ($reference = $this->references->existingPath($job)) !== null) {
                        $this->references->delete($reference);
                        $counts['reference']++;
                    }
                    $job->delete();
                }
            });

        AudioJob::query()->whereNotIn('user_id', $adminIds)->where('status', 'completed')
            ->where('created_at', '<', $cutoff)->orderBy('id')->chunkById(100, function ($jobs) use (&$counts, $disk): void {
                foreach ($jobs as $job) {
                    $path = GeneratedAudioStore::path($job->job_id);
                    if ($disk->exists($path)) {
                        $disk->deleteDirectory(dirname($path));
                    }
                    $job->delete();
                    $counts['audio']++;
                }
            });

        MediaToolJob::query()->whereNotIn('user_id', $adminIds)->where('status', 'completed')
            ->where('created_at', '<', $cutoff)->orderBy('id')->chunkById(100, function ($jobs) use (&$counts): void {
                foreach ($jobs as $job) {
                    if (($user = $job->user) !== null) {
                        $this->mediaTools->destroy($user, $job->job_id);
                        $counts['media_tool']++;
                    }
                }
            });

        return $counts;
    }
}
