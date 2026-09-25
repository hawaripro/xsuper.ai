<?php

namespace App\Services;

use App\Models\AudioJob;
use App\Models\ChatArtifact;
use App\Models\ChatArtifactRevision;
use App\Models\ChatAttachment;
use App\Models\ChatOperation;
use App\Models\DurationOrder;
use App\Models\ImageJob;
use App\Models\MediaAsset;
use App\Models\MediaToolJob;
use App\Models\RealtimeMediaSession;
use App\Models\ThreeDJob;
use App\Models\User;
use App\Models\UserStorageUpgrade;
use App\Models\VideoJob;
use App\Models\WorkspaceMediaJob;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** One physical path counts once, irrespective of registry, chat or job references. */
class StorageQuotaService
{
    private const WORKSPACE_ACTIVE = ['pending', 'processing', 'uncertain', 'save_failed'];

    /** Settled realtime rows; any other status may be live or still hold reserved tokens. */
    private const REALTIME_TERMINAL = ['closed', 'exhausted', 'expired', 'failed'];

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
        $upgrades = UserStorageUpgrade::query()->where('user_id', $user->id)->active();

        return (int) (clone $upgrades)->whereNull('duration_order_id')->sum('extra_bytes')
            + (int) (clone $upgrades)->whereNotNull('duration_order_id')->max('extra_bytes');
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
        $total = 0;
        $seen = [];
        foreach ($this->ownedPaths($user) as [$diskName, $path]) {
            $key = $diskName."\0".$path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $disk = Storage::disk($diskName);
            if ($disk->exists($path)) {
                $total += $disk->size($path);
            }
        }
        // Media-tool workspaces use their existing private runtime store, not a Laravel disk.
        $total += (int) MediaToolJob::query()->where('user_id', $user->id)->where('status', 'completed')->sum('size_bytes');

        return $total;
    }

    /** Call inside the same owner-row transaction as the byte promotion and metadata write. */
    public function assertCanStore(User $user, int $incomingBytes): void
    {
        if ($incomingBytes < 0) {
            throw new \InvalidArgumentException('Incoming storage bytes cannot be negative.');
        }
        if (! $this->isExempt($user) && $incomingBytes > max(0, $this->quotaBytes($user) - $this->usedBytes($user))) {
            throw new HttpException(413, 'Library storage is full. Download and delete items or upgrade storage before saving.');
        }
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
            'used_bytes' => $used, 'base_bytes' => $base, 'upgrade_bytes' => $upgrade,
            'quota_bytes' => $quota, 'remaining_bytes' => max(0, $quota - $used),
            'upgrade_expires_at' => $this->activeUpgradeExpiry($user)?->toISOString(),
            'unlimited' => $this->isExempt($user), 'exceeded' => ! $this->isExempt($user) && $used >= $quota,
            'retention_days' => (int) config('storage_quota.retention_days', 7),
        ];
    }

    public function grantUpgrade(User $user, string $planKey, int $extraBytes, int $days, ?int $orderId = null): UserStorageUpgrade
    {
        $now = now();

        return UserStorageUpgrade::create([
            'user_id' => $user->id, 'plan_key' => $planKey, 'extra_bytes' => $extraBytes,
            'starts_at' => $now, 'expires_at' => $now->copy()->addDays($days), 'order_id' => $orderId,
        ]);
    }

    /** Membership storage is an order snapshot, not a stacking monthly allowance. */
    public function grantMembershipStorage(User $user, DurationOrder $order, CarbonInterface $until): ?UserStorageUpgrade
    {
        if ((int) $order->storage_bytes === 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $order, $until): UserStorageUpgrade {
            // Serialize both direct calls and approval retries using the same order lock.
            DurationOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            return UserStorageUpgrade::firstOrCreate(['duration_order_id' => $order->id], [
                'user_id' => $user->id, 'plan_key' => 'membership:'.$order->package,
                'extra_bytes' => (int) $order->storage_bytes, 'starts_at' => now(),
                'expires_at' => $until, 'order_id' => null,
            ]);
        });
    }

    /** Batch read for Library, rather than one active-job query per uploaded file. */
    public function referencedAssetIds(User $user, bool $includeBindings = true): array
    {
        $ids = [];
        foreach ([ImageJob::class, VideoJob::class, AudioJob::class, ThreeDJob::class, WorkspaceMediaJob::class] as $class) {
            $statuses = $class === WorkspaceMediaJob::class ? self::WORKSPACE_ACTIVE : ['pending', 'processing'];
            foreach ($class::query()->where('user_id', $user->id)->whereIn('status', $statuses)->cursor() as $job) {
                foreach ($job->reference_asset_ids ?? [] as $id) {
                    $ids[$id] = true;
                }
            }
        }
        foreach (ChatOperation::query()->where('user_id', $user->id)->whereIn('status', ['queued', 'streaming'])->cursor() as $operation) {
            foreach ($operation->context_snapshot['asset_ids'] ?? [] as $id) {
                $ids[$id] = true;
            }
        }
        foreach (RealtimeMediaSession::query()->where('user_id', $user->id)->whereIn('status', RealtimeMediaSession::ACTIVE_STATUSES)->cursor() as $session) {
            foreach ([...($session->asset_ids ?? []), ...($session->recording_asset_ids ?? [])] as $id) {
                $ids[$id] = true;
            }
        }
        if ($includeBindings) {
            foreach (ChatAttachment::query()->where('user_id', $user->id)->whereNull('detached_at')->pluck('asset_id') as $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    public function assetIsReferenced(MediaAsset $asset, bool $includeBindings = true): bool
    {
        foreach ([ImageJob::class, VideoJob::class, AudioJob::class, ThreeDJob::class, WorkspaceMediaJob::class] as $class) {
            $statuses = $class === WorkspaceMediaJob::class ? self::WORKSPACE_ACTIVE : ['pending', 'processing'];
            if ($class::query()->where('user_id', $asset->user_id)->whereIn('status', $statuses)
                ->whereJsonContains('reference_asset_ids', $asset->id)->exists()) {
                return true;
            }
        }
        if (ChatOperation::query()->where('user_id', $asset->user_id)->whereIn('status', ['queued', 'streaming'])
            ->whereJsonContains('context_snapshot->asset_ids', $asset->id)->exists()) {
            return true;
        }
        if (RealtimeMediaSession::query()->where('user_id', $asset->user_id)->whereIn('status', RealtimeMediaSession::ACTIVE_STATUSES)
            ->where(fn ($query) => $query->whereJsonContains('asset_ids', $asset->id)->orWhereJsonContains('recording_asset_ids', $asset->id))->exists()) {
            return true;
        }

        return $includeBindings && ChatAttachment::query()->where('user_id', $asset->user_id)
            ->where('asset_id', $asset->id)->whereNull('detached_at')->exists();
    }

    public function assertAssetUnreferenced(MediaAsset $asset): void
    {
        if ($this->assetIsReferenced($asset)) {
            throw ValidationException::withMessages(['asset' => 'This file is attached to a conversation or active job. Remove the attachment or wait for the job before deleting it.']);
        }
    }

    /** Generated artifacts depend on their source job as well as its original bytes. */
    public function jobIsReferenced(User $user, string $id): bool
    {
        if (ChatArtifactRevision::query()->where('user_id', $user->id)->where('generated_job_id', $id)->exists()) {
            return true;
        }
        $job = $this->findJob($user, $id);
        if ($job === null) {
            return false;
        }
        $paths = $this->jobPaths($job);

        return $paths !== [] && MediaAsset::query()->where('user_id', $user->id)->where('storage_disk', 'local')
            ->where('retention_status', 'active')->whereIn('storage_path', $paths)->exists();
    }

    public function assertJobUnreferenced(User $user, string $id): void
    {
        if ($this->jobIsReferenced($user, $id)) {
            throw ValidationException::withMessages(['job' => 'This output is retained by a saved artifact or reusable reference. Remove that reference before deleting its original.']);
        }
    }

    public function pathIsShared(User $user, string $disk, string $path, ?string $exceptAssetId = null): bool
    {
        foreach ($this->ownedPaths($user, $exceptAssetId) as [$candidateDisk, $candidatePath]) {
            if ($disk === $candidateDisk && $path === $candidatePath) {
                return true;
            }
        }

        return false;
    }

    /** Account deletion cannot race paid work, an in-flight upload or a revision save. */
    public function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            foreach ([ImageJob::class, VideoJob::class, AudioJob::class, ThreeDJob::class, WorkspaceMediaJob::class] as $class) {
                $statuses = $class === WorkspaceMediaJob::class ? self::WORKSPACE_ACTIVE : ['pending', 'processing'];
                if ($class::query()->where('user_id', $user->id)
                    ->where(fn ($query) => $query->whereIn('status', $statuses)->orWhere('billing_status', 'reserved'))->exists()) {
                    throw ValidationException::withMessages(['user' => 'This account has active or unsettled media work. Stop or finish that work before deleting the account.']);
                }
            }
            if (ChatOperation::query()->where('user_id', $user->id)->whereIn('status', ['queued', 'streaming'])->exists()
                || MediaToolJob::query()->where('user_id', $user->id)->whereIn('status', ['pending', 'processing'])->exists()) {
                throw ValidationException::withMessages(['user' => 'This account has active chat or media tasks. Stop them before deleting the account.']);
            }
            if (RealtimeMediaSession::query()->where('user_id', $user->id)
                ->where(fn ($query) => $query->whereNotIn('status', self::REALTIME_TERMINAL)->orWhere('billing_status', 'reserved'))->exists()) {
                throw ValidationException::withMessages(['user' => 'This account has a live or unsettled realtime session. Wait until it closes and its charge settles before deleting the account.']);
            }
            $seen = [];
            foreach ($this->ownedPaths($user) as [$diskName, $path]) {
                $key = $diskName."\0".$path;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $this->deleteFile($diskName, $path);
            }
            foreach (MediaToolJob::query()->where('user_id', $user->id)->cursor() as $job) {
                $this->mediaTools->destroy($user, $job->job_id);
            }
            $user->delete();
        });
    }

    /** Retain the existing age window and admin exemption; active work is never a purge target. */
    public function purge(?CarbonInterface $cutoff = null): array
    {
        $cutoff ??= now()->subDays((int) config('storage_quota.retention_days', 7));
        $adminIds = User::query()->where('role', 'admin')->pluck('id')->all();
        $counts = ['image' => 0, 'video' => 0, 'reference' => 0, 'audio' => 0, 'model3d' => 0,
            'media_asset' => 0, 'media_tool' => 0, 'workspace_media' => 0, 'artifact_revision' => 0, 'realtime_session' => 0];

        ChatArtifact::query()->whereNotIn('user_id', $adminIds)->whereHas('revisions', fn ($query) => $query->where('created_at', '<', $cutoff))
            ->chunkById(100, function ($artifacts) use ($cutoff, &$counts): void {
                foreach ($artifacts as $candidate) {
                    DB::transaction(function () use ($candidate, $cutoff, &$counts): void {
                        if (! User::query()->whereKey($candidate->user_id)->lockForUpdate()->first()) {
                            return;
                        }
                        $artifact = ChatArtifact::query()->lockForUpdate()->find($candidate->id);
                        if ($artifact === null || ($artifact->source_operation_id !== null && ChatOperation::query()
                            ->where('user_id', $artifact->user_id)->whereKey($artifact->source_operation_id)->whereIn('status', ['queued', 'streaming'])->exists())) {
                            return;
                        }
                        $revisions = $artifact->revisions()->where('created_at', '<', $cutoff)->get();
                        foreach ($revisions as $revision) {
                            if ($revision->storage_path !== null) {
                                $this->deleteFile($revision->storage_disk, $revision->storage_path);
                            }
                            $revision->delete();
                            $counts['artifact_revision']++;
                        }
                        if ($revisions->contains('id', $artifact->current_revision_id)) {
                            $artifact->delete();
                        }
                    });
                }
            });

        MediaAsset::query()->whereNotIn('user_id', $adminIds)->where('retention_status', '!=', 'expired')
            ->where(fn ($query) => $query->where('created_at', '<', $cutoff)->orWhere('expires_at', '<=', now()))
            ->chunkById(100, function ($assets) use (&$counts): void {
                foreach ($assets as $candidate) {
                    DB::transaction(function () use ($candidate, &$counts): void {
                        $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->first();
                        $asset = MediaAsset::query()->lockForUpdate()->find($candidate->id);
                        if ($user === null || $asset === null || $this->assetIsReferenced($asset, false)) {
                            return;
                        }
                        if (! $this->pathIsShared($user, $asset->storage_disk, $asset->storage_path, $asset->id)) {
                            $this->deleteFile($asset->storage_disk, $asset->storage_path);
                        }
                        if (ChatAttachment::query()->where('user_id', $user->id)->where('asset_id', $asset->id)->exists()) {
                            $asset->update(['retention_status' => 'expired']);
                        } else {
                            $asset->delete();
                        }
                        $counts['media_asset']++;
                    });
                }
            });

        // Settled session rows keep only provider/capability provenance; inputs and recordings age as ordinary assets above.
        RealtimeMediaSession::query()->whereNotIn('user_id', $adminIds)->whereIn('status', self::REALTIME_TERMINAL)
            ->where('billing_status', '!=', 'reserved')->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($sessions) use (&$counts): void {
                foreach ($sessions as $candidate) {
                    DB::transaction(function () use ($candidate, &$counts): void {
                        $session = RealtimeMediaSession::query()->lockForUpdate()->find($candidate->id);
                        if ($session === null || ! in_array($session->status, self::REALTIME_TERMINAL, true) || $session->billing_status === 'reserved') {
                            return;
                        }
                        $session->delete();
                        $counts['realtime_session']++;
                    });
                }
            });

        foreach ([ImageJob::class => 'image', VideoJob::class => 'video', AudioJob::class => 'audio', ThreeDJob::class => 'model3d', WorkspaceMediaJob::class => 'workspace_media'] as $class => $kind) {
            $active = $class === WorkspaceMediaJob::class ? self::WORKSPACE_ACTIVE : ['pending', 'processing'];
            $class::query()->whereNotIn('user_id', $adminIds)->whereNotIn('status', $active)->where('created_at', '<', $cutoff)
                ->chunkById(100, function ($jobs) use ($kind, &$counts): void {
                    foreach ($jobs as $candidate) {
                        DB::transaction(function () use ($candidate, $kind, &$counts): void {
                            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->first();
                            $job = $candidate->newQuery()->lockForUpdate()->find($candidate->id);
                            if ($user === null || $job === null || $job->billing_status === 'reserved') {
                                return;
                            }
                            $id = $job instanceof WorkspaceMediaJob ? $job->job_id : $kind.':'.$job->job_id;
                            if ($this->jobIsReferenced($user, $id)) {
                                return;
                            }
                            foreach ($this->jobPaths($job) as $path) {
                                $this->deleteFile('local', $path);
                            }
                            if ($job instanceof AudioJob || $job instanceof ThreeDJob) {
                                $directory = $job instanceof AudioJob ? GeneratedAudioStore::directory($job->job_id) : dirname(GeneratedModel3dStore::path($job->job_id));
                                $disk = Storage::disk('local');
                                if ($disk->exists($directory) && ! $disk->deleteDirectory($directory)) {
                                    throw new HttpException(503, 'Stored media could not be removed. Try again.');
                                }
                            }
                            if ($job instanceof VideoJob && $job->has_reference) {
                                $reference = $this->references->existingPath($job);
                                if ($reference !== null && empty($job->reference_asset_ids)) {
                                    $this->deleteFile('local', $reference);
                                    $counts['reference']++;
                                }
                            }
                            $job->delete();
                            $counts[$kind]++;
                        });
                    }
                });
        }

        MediaToolJob::query()->whereNotIn('user_id', $adminIds)->where('status', 'completed')->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($jobs) use (&$counts): void {
                foreach ($jobs as $job) {
                    if (($user = $job->user) !== null) {
                        $this->mediaTools->destroy($user, $job->job_id);
                        $counts['media_tool']++;
                    }
                }
            });

        return $counts;
    }

    /** @return \Generator<array{string,string}> */
    private function ownedPaths(User $user, ?string $exceptAssetId = null): \Generator
    {
        foreach ([ImageJob::class, VideoJob::class, AudioJob::class, ThreeDJob::class, WorkspaceMediaJob::class] as $class) {
            foreach ($class::query()->where('user_id', $user->id)->cursor() as $job) {
                foreach ($this->jobPaths($job) as $path) {
                    yield ['local', $path];
                }
                if ($job instanceof VideoJob && $job->has_reference && empty($job->reference_asset_ids)
                    && ($path = $this->references->existingPath($job)) !== null) {
                    yield ['local', $path];
                }
            }
        }
        foreach (MediaAsset::query()->where('user_id', $user->id)->when($exceptAssetId !== null, fn ($query) => $query->whereKeyNot($exceptAssetId))
            ->where('retention_status', 'active')->cursor() as $asset) {
            yield [$asset->storage_disk, $asset->storage_path];
        }
        foreach (ChatArtifactRevision::query()->where('user_id', $user->id)->whereNotNull('storage_path')->cursor() as $revision) {
            yield [$revision->storage_disk, $revision->storage_path];
        }
    }

    private function jobPaths(Model $job): array
    {
        if ($job instanceof ImageJob) {
            return array_column(GeneratedImageStore::outputs($job), 'path');
        }
        if ($job instanceof WorkspaceMediaJob) {
            return array_values(array_filter(array_map(static fn ($asset): ?string => is_array($asset) && is_string($asset['path'] ?? null) ? $asset['path'] : null, $job->asset_paths ?? [])));
        }
        if ($job instanceof AudioJob) {
            return array_values(array_filter(array_map(fn ($index): ?string => GeneratedAudioStore::outputPath($job, $index), array_keys($job->outputs ?? []))));
        }
        if ($job instanceof ThreeDJob) {
            $path = GeneratedModel3dStore::path($job->job_id);

            return $job->model_path === $path ? [$path] : [];
        }
        if ($job instanceof VideoJob && $job->video_url === '/api/v/'.$job->job_id.'/asset') {
            return [GeneratedVideoStore::path($job->job_id)];
        }

        return [];
    }

    private function findJob(User $user, string $id): ?Model
    {
        if (! str_contains($id, ':')) {
            return WorkspaceMediaJob::query()->where('user_id', $user->id)->where('job_id', $id)->first();
        }
        [$kind, $key] = explode(':', $id, 2);
        $class = match ($kind) {
            'image' => ImageJob::class, 'video', 'avatar' => VideoJob::class, 'audio' => AudioJob::class, 'model3d' => ThreeDJob::class, default => null,
        };

        return $class === null ? null : $class::query()->where('user_id', $user->id)->where('job_id', $key)->first();
    }

    private function deleteFile(string $diskName, string $path): void
    {
        $disk = Storage::disk($diskName);
        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new HttpException(503, 'Stored media could not be removed. Try again.');
        }
    }
}
