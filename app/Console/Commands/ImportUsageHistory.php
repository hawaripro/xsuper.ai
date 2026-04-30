<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportUsageHistory extends Command
{
    protected $signature = 'usage:import {--fresh : Clear existing usage_logs before import}';
    protected $description = 'Import chat history into usage_logs table';

    public function handle()
    {
        if ($this->option('fresh')) {
            DB::table('usage_logs')->truncate();
            $this->info('Cleared existing usage_logs.');
        }

        $this->info('Importing chat history to usage_logs...');

        // Get raw timestamps from PostgreSQL (these are in DB timezone)
        $messages = DB::select("
            SELECT user_id, model, content, created_at
            FROM chat_history
            WHERE role = 'assistant' AND content IS NOT NULL AND LENGTH(content) > 0
            ORDER BY created_at ASC
        ");

        $count = 0;
        foreach ($messages as $msg) {
            $contentLen = mb_strlen($msg->content);
            $completionTokens = max(1, (int) ($contentLen / 4));
            $promptTokens = max(1, (int) ($completionTokens * 0.5));
            $totalTokens = $promptTokens + $completionTokens;

            // Insert with raw SQL to preserve exact timestamp from chat_history
            DB::table('usage_logs')->insert([
                'user_id' => $msg->user_id,
                'model' => $msg->model ?? 'unknown',
                'source' => 'web',
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'credit' => round($totalTokens / 1000 * 0.01, 4),
                'created_at' => $msg->created_at,
                'updated_at' => $msg->created_at,
            ]);
            $count++;
        }

        $this->info("Imported {$count} usage records.");

        $summary = DB::selectOne("SELECT COUNT(*) as total, MIN(created_at) as oldest, MAX(created_at) as newest FROM usage_logs");
        $this->info("Total: {$summary->total} | Oldest: {$summary->oldest} | Newest: {$summary->newest}");

        // Show per-hour distribution for today
        $today = DB::select("
            SELECT TO_CHAR(created_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Jakarta', 'HH24:00') as hour,
                   COUNT(*) as cnt,
                   SUM(total_tokens) as tokens
            FROM usage_logs
            WHERE created_at >= NOW() - INTERVAL '24 hours'
            GROUP BY 1 ORDER BY 1
        ");
        $this->info("\nToday's hourly distribution (WIB):");
        foreach ($today as $h) {
            $this->info("  {$h->hour} → {$h->cnt} requests, {$h->tokens} tokens");
        }
    }
}
