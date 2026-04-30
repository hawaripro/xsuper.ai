<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixTimezones extends Command
{
    protected $signature = 'fix:timezone';
    protected $description = 'Fix PostgreSQL timezone to UTC and convert existing data';

    public function handle()
    {
        $this->info('Setting PostgreSQL timezone to UTC...');
        DB::statement("ALTER DATABASE ultrai_db SET timezone TO 'UTC'");
        DB::statement("SET timezone TO 'UTC'");

        $this->info('Converting usage_logs timestamps from WIB to UTC...');
        DB::statement("UPDATE usage_logs SET created_at = created_at - INTERVAL '7 hours', updated_at = updated_at - INTERVAL '7 hours' WHERE created_at > NOW() - INTERVAL '1 year'");

        $this->info('Converting chat_history timestamps from WIB to UTC...');
        DB::statement("UPDATE chat_history SET created_at = created_at - INTERVAL '7 hours' WHERE created_at > NOW() - INTERVAL '1 year'");

        $this->info('Done! All timestamps converted to UTC.');
        $this->info('PHP timezone: ' . config('app.timezone'));
        $this->info('DB timezone: ' . DB::selectOne("SHOW timezone")->TimeZone);
    }
}
