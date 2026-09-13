<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $blocked = fn (?string $model): bool => $model !== null && ($model === 'au'.'to' || str_contains(strtolower($model), 'eno'.'wx'));

        foreach ([['chat_conversations', ''], ['chat_messages', null], ['chat_history', null], ['usage_logs', 'retired-model'], ['video_jobs', 'retired-model'], ['wallet_transactions', null]] as [$table, $replacement]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'model')) {
                continue;
            }
            DB::table($table)->select('id', 'model')->orderBy('id')->chunkById(500, function ($rows) use ($blocked, $replacement, $table): void {
                foreach ($rows as $row) {
                    if ($blocked($row->model)) {
                        DB::table($table)->where('id', $row->id)->update(['model' => $replacement]);
                    }
                }
            });
        }

        if (Schema::hasTable('usage_rates')) {
            DB::table('usage_rates')->select('id', 'model')->orderBy('id')->chunkById(500, function ($rows) use ($blocked): void {
                foreach ($rows as $row) {
                    if ($blocked($row->model)) {
                        DB::table('usage_rates')->where('id', $row->id)->update([
                            'model' => 'retired-model-'.$row->id,
                            'is_active' => false,
                        ]);
                    }
                }
            });
        }

        if (Schema::hasTable('api_keys') && Schema::hasColumn('api_keys', 'allowed_models')) {
            DB::table('api_keys')->select('id', 'allowed_models')->orderBy('id')->chunkById(500, function ($rows) use ($blocked): void {
                foreach ($rows as $row) {
                    $models = is_array($row->allowed_models) ? $row->allowed_models : json_decode($row->allowed_models ?? 'null', true);
                    if (! is_array($models)) {
                        continue;
                    }
                    $clean = array_values(array_filter($models, fn ($model): bool => is_string($model) && ! $blocked($model)));
                    if ($clean !== $models) {
                        DB::table('api_keys')->where('id', $row->id)->update(['allowed_models' => json_encode($clean)]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Removed internal identifiers cannot be reconstructed safely.
    }
};
