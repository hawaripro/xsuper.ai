<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')
            ->where('kind', 'support')
            ->where('action_url', 'like', '/support/tickets/%')
            ->select(['id', 'action_url'])
            ->chunkById(100, function ($notifications): void {
                foreach ($notifications as $notification) {
                    if (preg_match('#^/support/tickets/([0-9]+)$#', $notification->action_url, $match)) {
                        DB::table('notifications')->where('id', $notification->id)
                            ->update(['action_url' => '/bantuan?ticket='.$match[1]]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Notification destinations are data repairs, not reversible schema changes.
    }
};
