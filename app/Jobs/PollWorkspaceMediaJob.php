<?php

namespace App\Jobs;

use App\Services\WorkspaceMediaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollWorkspaceMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 450;
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $workspaceMediaJobId) {}

    public function handle(WorkspaceMediaService $media): void
    {
        $media->poll($this->workspaceMediaJobId);
    }
}
