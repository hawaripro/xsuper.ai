<?php

namespace App\Console\Commands;

use App\Services\RealtimeMediaService;
use Illuminate\Console\Command;

class ReconcileRealtimeMediaSessions extends Command
{
    protected $signature = 'media:reconcile-realtime';

    protected $description = 'End expired realtime leases and resolve interrupted admissions without renewing, retrying or refunding accepted or uncertain sessions';

    public function handle(RealtimeMediaService $realtime): int
    {
        $this->info(json_encode($realtime->reconcile(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
