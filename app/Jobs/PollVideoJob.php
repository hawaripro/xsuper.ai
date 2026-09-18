<?php

namespace App\Jobs;

use App\Services\VideoGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $videoJobId) {}

    public function handle(VideoGenerationService $videos): void
    {
        $videos->poll($this->videoJobId);
    }
}
