<?php

namespace App\Console\Commands;

use App\Models\UsageLog;
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

        $messages = DB::table('chat_history')
            ->where('role', 'assistant')
            ->orderBy('created_at')
            ->get();

        $count = 0;
        foreach ($messages as $msg) {
            $contentLen = mb_strlen($msg->content ?? '');
            $completionTokens = (int) ($contentLen / 4);
            $promptTokens = (int) ($completionTokens * 0.5); // estimate

            UsageLog::create([
                'user_id' => $msg->user_id,
                'model' => $msg->model ?? 'auto',
                'source' => 'web',
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
                'credit' => round(($promptTokens + $completionTokens) / 1000 * 0.01, 4),
                'created_at' => $msg->created_at,
                'updated_at' => $msg->created_at,
            ]);
            $count++;
        }

        $this->info("Imported {$count} usage records from chat history.");
    }
}
