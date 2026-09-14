<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use App\Models\UsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

class UsageController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->query('user_id');
        $period = $request->query('period', 'daily');
        $tz = $request->query('tz', 'Asia/Jakarta');
        // Validate timezone before converting aggregation buckets.
        try { new \DateTimeZone($tz); } catch (\Exception) { $tz = 'Asia/Jakarta'; }

        if ($userId) {
            return response()->json($this->userStats((int) $userId, $period, $tz));
        }

        return response()->json($this->globalStats($period, $tz));
    }

    private function globalStats(string $period, string $tz): array
    {
        $totalStats = UsageLog::selectRaw('
            COALESCE(SUM(total_tokens), 0) as tokens,
            COALESCE(SUM(credit), 0) as credits,
            COUNT(*) as requests,
            COUNT(DISTINCT user_id) as active_users
        ')->first();

        $topUsers = UsageLog::query()
            ->join('users', 'users.id', '=', 'usage_logs.user_id')
            ->selectRaw('usage_logs.user_id, users.name, users.email, SUM(usage_logs.total_tokens) as tokens, SUM(usage_logs.credit) as credits, COUNT(*) as requests')
            ->groupBy('usage_logs.user_id', 'users.name', 'users.email')
            ->orderByDesc(DB::raw('SUM(usage_logs.total_tokens)'))
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'tokens' => (int) $row->tokens,
                'credits' => round((float) $row->credits, 4),
                'requests' => (int) $row->requests,
                'user' => ['id' => (int) $row->user_id, 'name' => $row->name, 'email' => $row->email],
            ]);
        return [
            'total_tokens' => (int) ($totalStats->tokens ?? 0),
            'total_credits' => round($totalStats->credits ?? 0, 4),
            'total_requests' => (int) ($totalStats->requests ?? 0),
            'active_users' => (int) ($totalStats->active_users ?? 0),
            'top_users' => $topUsers,
            'timeline' => $this->getTimeline(null, $period, $tz),
            'by_model' => $this->getModelBreakdown(null, $period),
            'model_timeline' => $this->getModelTimeline(null, $period, $tz),
        ];
    }

    private function userStats(int $userId, string $period, string $tz): array
    {
        $total = UsageLog::where('user_id', $userId)->selectRaw('
            COALESCE(SUM(total_tokens), 0) as tokens, COALESCE(SUM(credit), 0) as credits, COUNT(*) as requests
        ')->first();

        return [
            'total_tokens' => (int) ($total->tokens ?? 0),
            'total_credits' => round($total->credits ?? 0, 4),
            'total_requests' => (int) ($total->requests ?? 0),
            'timeline' => $this->getTimeline($userId, $period, $tz),
            'by_model' => $this->getModelBreakdown($userId, $period),
            'model_timeline' => $this->getModelTimeline($userId, $period, $tz),
        ];
    }

    private function periodHours(string $period): ?int
    {
        return match ($period) {
            'hourly' => 24,
            'weekly' => 12 * 7 * 24,
            'monthly' => 365 * 24,
            'all' => null,
            default => 30 * 24,
        };
    }

    private function usageQuery(?int $userId, string $period): Builder
    {
        $query = UsageLog::query();
        if ($userId) {
            $query->where('user_id', $userId);
        }
        if ($hours = $this->periodHours($period)) {
            $query->where('created_at', '>=', now()->subHours($hours));
        }

        return $query;
    }

    private function bucket(Carbon $timestamp, string $period, string $tz): array
    {
        $local = $timestamp->copy()->setTimezone($tz);

        return match ($period) {
            'hourly' => [$local->format('Y-m-d H:00'), $local->format('H:00')],
            'weekly' => [$local->copy()->startOfWeek()->format('Y-m-d'), 'W'.$local->isoWeek()],
            'monthly', 'all' => [$local->format('Y-m'), $local->format('Y-m')],
            default => [$local->format('Y-m-d'), $local->format('m-d')],
        };
    }

    private function usageRows(?int $userId, string $period): iterable
    {
        return $this->usageQuery($userId, $period)
            ->oldest('created_at')
            ->get(['model', 'total_tokens', 'credit', 'created_at']);
    }

    private function getTimeline(?int $userId, string $period, string $tz): array
    {
        $buckets = [];
        foreach ($this->usageRows($userId, $period) as $row) {
            [$key, $label] = $this->bucket($row->created_at, $period, $tz);
            $buckets[$key] ??= ['label' => $label, 'tokens' => 0, 'credits' => 0.0, 'requests' => 0];
            $buckets[$key]['tokens'] += (int) $row->total_tokens;
            $buckets[$key]['credits'] += (float) $row->credit;
            $buckets[$key]['requests']++;
        }

        return array_values(array_map(function (array $bucket): array {
            $bucket['credits'] = round($bucket['credits'], 4);
            return $bucket;
        }, $buckets));
    }

    private function getModelBreakdown(?int $userId, string $period): array
    {
        return $this->usageQuery($userId, $period)
            ->selectRaw('model, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests')
            ->groupBy('model')
            ->orderByDesc(DB::raw('SUM(total_tokens)'))
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'model' => $row->model,
                'tokens' => (int) $row->tokens,
                'credits' => round((float) $row->credits, 4),
                'requests' => (int) $row->requests,
            ])
            ->toArray();
    }

    private function getModelTimeline(?int $userId, string $period, string $tz): array
    {
        $pivoted = [];
        $models = [];
        foreach ($this->usageRows($userId, $period) as $row) {
            [$key, $label] = $this->bucket($row->created_at, $period, $tz);
            $pivoted[$key] ??= ['label' => $label];
            $pivoted[$key][$row->model] = ($pivoted[$key][$row->model] ?? 0) + (int) $row->total_tokens;
            $models[$row->model] = true;
        }

        return [
            'data' => array_values($pivoted),
            'models' => array_keys($models),
        ];
    }
}
