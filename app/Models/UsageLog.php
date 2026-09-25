<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageLog extends Model
{
    protected $fillable = ['user_id', 'api_key_id', 'model', 'source', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'credit', 'cost_microusd', 'usage_rate_id', 'device_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(int $userId, string $model, array $usage, string $source = 'web', ?string $deviceId = null): void
    {
        static::create([
            'user_id' => $userId,
            'api_key_id' => $usage['api_key_id'] ?? null,
            'model' => $model,
            'source' => $source,
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
            'credit' => $usage['credit'] ?? 0,
            'cost_microusd' => $usage['cost_microusd'] ?? 0,
            'usage_rate_id' => $usage['usage_rate_id'] ?? null,
            'device_id' => $deviceId,
        ]);
    }

    public static function userStats(int $userId): array
    {
        $total = static::where('user_id', $userId)->selectRaw('
            SUM(total_tokens) as tokens,
            SUM(credit) as credits,
            COUNT(*) as requests
        ')->first();

        $byModel = static::where('user_id', $userId)
            ->selectRaw('model, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests')
            ->groupBy('model')
            ->orderByDesc('tokens')
            ->get();

        $daily = static::where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, SUM(total_tokens) as tokens, COUNT(*) as requests')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'total_tokens' => (int) ($total->tokens ?? 0),
            'total_credits' => round($total->credits ?? 0, 4),
            'total_requests' => (int) ($total->requests ?? 0),
            'by_model' => $byModel,
            'daily' => $daily,
        ];
    }

    public static function topUsers(int $limit = 20): array
    {
        return static::selectRaw('user_id, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests')
            ->groupBy('user_id')
            ->orderByDesc('tokens')
            ->limit($limit)
            ->with('user:id,name,email,role')
            ->get()
            ->toArray();
    }
}
