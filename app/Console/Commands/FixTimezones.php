<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixTimezones extends Command
{
    protected $signature = 'fix:timezone';
    protected $description = 'Ensure PostgreSQL timezone is UTC';

    public function handle()
    {
        $currentTz = DB::selectOne("SHOW timezone")->TimeZone;
        $this->info("Current DB timezone: {$currentTz}");

        if ($currentTz !== 'UTC') {
            $this->info('Setting PostgreSQL timezone to UTC...');
            DB::statement("SET timezone TO 'UTC'");
            $this->info('Done. Note: Run ALTER DATABASE ultrai_db SET timezone TO \'UTC\'; as superuser for permanent change.');
        } else {
            $this->info('DB timezone already UTC.');
        }

        $this->info('PHP timezone: ' . config('app.timezone'));
        $this->info('DB NOW(): ' . DB::selectOne("SELECT NOW() as n")->n);
        $this->info('PHP now(): ' . now());
    }
}
