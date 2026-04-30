<?php

namespace App\Console\Commands;

use App\Models\UsageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportUsageHistory extends Command
{
    protected $signature = 'usage:import {--fresh : Clear existing usage_logs before import}';
    protected $description = 'Import chat history into usage_logs table with original timestamps';

    public function handle()
    {
        if ($this->option('fresh')) {
            DB::table('usage_logs')->truncate();
            $this->info('Cleared existing usage_logs.');
        }

        $this->info('Importing chat history to usage_logs...');

        // Get all assistant messages with their original timestamps
        $messages = DB::table('chat_history')
            ->where('role', 'assistant')
            ->whereNotNull('created_at')
            ->orderBy('created_at')
            ->get();

        $count = 0;
        foreach ($messages as $msg) {
            $contentLen = mb_strlen($msg->content ?? '');
            if ($contentLen < 1) continue;

            $completionTokens = max(1, (int) ($contentLen / 4));
            $promptTokens = max(1, (int) ($completionTokens * 0.5));
            $totalTokens = $promptTokens + $completionTokens;

            // Use original timestamp from chat_history
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

        // Show summary
        $summary = DB::table('usage_logs')
            ->selectRaw('COUNT(*) as total, MIN(created_at) as oldest, MAX(created_at) as newest')
            ->first();
        $this->info("Total records: {$summary->total}");
        $this->info("Oldest: {$summary->oldest}");
        $this->info("Newest: {$summary->newest}");
    }
}
