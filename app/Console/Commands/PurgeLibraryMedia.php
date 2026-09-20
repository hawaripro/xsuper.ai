<?php

namespace App\Console\Commands;

use App\Services\StorageQuotaService;
use Illuminate\Console\Command;

class PurgeLibraryMedia extends Command
{
    protected $signature = 'library:purge {--days= : Override the retention window in days}';

    protected $description = 'Delete non-admin Library media older than the retention window (weekly).';

    public function handle(StorageQuotaService $storage): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : (int) config('storage_quota.retention_days', 7);
        $counts = $storage->purge(now()->subDays($days));
        $this->info("Purged Library media older than {$days} days: ".json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
