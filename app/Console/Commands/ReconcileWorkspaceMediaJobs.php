<?php

namespace App\Console\Commands;

use App\Services\WorkspaceMediaService;
use Illuminate\Console\Command;

class ReconcileWorkspaceMediaJobs extends Command
{
    protected $signature = 'media:reconcile-workspace';
    protected $description = 'Recover queued workspace media and interrupted polling/storage without repeating a paid submission';

    public function handle(WorkspaceMediaService $media): int
    {
        $this->info(json_encode($media->recover(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
