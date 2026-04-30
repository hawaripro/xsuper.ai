<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsageController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->query('user_id');
        $period = $request->query('period', 'daily');

        if ($userId) {
            return response()->json($this->userStats((int) $userId, $period));
        }

        return response()->json($this->globalStats($period));
    }

    private function globalStats(string $period): array
    {
        $totalStats = UsageLog::selectRaw('
            COALESCE(SUM(total_tokens), 0) as tokens,
            COALESCE(SUM(credit), 0) as credits,
            COUNT(*) as requests,
            COUNT(DISTINCT user_id) as active_users
        ')->first();

        $topUsers = UsageLog::selectRaw('user_id, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests')
            ->groupBy('user_id')
            ->orderByDesc(DB::raw('SUM(total_tokens)'))
            ->limit(20)
            ->get()
            ->map(function ($u) {
                $user = \App\Models\User::find($u->user_id);
                $u->user = $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null;
                return $u;
            });

        return [
            'total_tokens' => (int) ($totalStats->tokens ?? 0),
            'total_credits' => round($totalStats->credits ?? 0, 4),
            'total_requests' => (int) ($totalStats->requests ?? 0),
            'active_users' => (int) ($totalStats->active_users ?? 0),
            'top_users' => $topUsers,
            'timeline' => $this->getTimeline(null, $period),
            'by_model' => $this->getModelBreakdown(null, $period),
            'model_timeline' => $this->getModelTimeline(null, $period),
        ];
    }

    private function userStats(int $userId, string $period): array
    {
        $total = UsageLog::where('user_id', $userId)->selectRaw('
            COALESCE(SUM(total_tokens), 0) as tokens, COALESCE(SUM(credit), 0) as credits, COUNT(*) as requests
        ')->first();

        return [
            'total_tokens' => (int) ($total->tokens ?? 0),
            'total_credits' => round($total->credits ?? 0, 4),
            'total_requests' => (int) ($total->requests ?? 0),
            'timeline' => $this->getTimeline($userId, $period),
            'by_model' => $this->getModelBreakdown($userId, $period),
            'model_timeline' => $this->getModelTimeline($userId, $period),
        ];
    }

    private function getTimeConfig(string $period): array
    {
        return match ($period) {
            'hourly' => [
                'where' => now()->subHours(24),
                'label' => "TO_CHAR(created_at, 'HH24:00')",
                'group' => "DATE_TRUNC('hour', created_at)",
            ],
            'weekly' => [
                'where' => now()->subWeeks(12),
                'label' => "'W' || TO_CHAR(created_at, 'IW')",
                'group' => "DATE_TRUNC('week', created_at)",
            ],
            'monthly' => [
                'where' => now()->subMonths(12),
                'label' => "TO_CHAR(created_at, 'YYYY-MM')",
                'group' => "DATE_TRUNC('month', created_at)",
            ],
            'all' => [
                'where' => null,
                'label' => "TO_CHAR(created_at, 'YYYY-MM')",
                'group' => "DATE_TRUNC('month', created_at)",
            ],
            default => [ // daily
                'where' => now()->subDays(30),
                'label' => "TO_CHAR(created_at, 'MM-DD')",
                'group' => "DATE_TRUNC('day', created_at)",
            ],
        };
    }

    private function getTimeline(?int $userId, string $period): array
    {
        $cfg = $this->getTimeConfig($period);
        $query = UsageLog::query();
        if ($userId) $query->where('user_id', $userId);
        if ($cfg['where']) $query->where('created_at', '>=', $cfg['where']);

        return $query->selectRaw("{$cfg['label']} as label, {$cfg['group']} as grp, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests")
            ->groupBy(DB::raw($cfg['group']), DB::raw($cfg['label']))
            ->orderBy(DB::raw($cfg['group']))
            ->get()
            ->map(fn($r) => ['label' => $r->label, 'tokens' => (int) $r->tokens, 'credits' => round((float) $r->credits, 4), 'requests' => (int) $r->requests])
            ->toArray();
    }

    private function getModelBreakdown(?int $userId, string $period): array
    {
        $cfg = $this->getTimeConfig($period);
        $query = UsageLog::query();
        if ($userId) $query->where('user_id', $userId);
        if ($cfg['where']) $query->where('created_at', '>=', $cfg['where']);

        return $query->selectRaw('model, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests')
            ->groupBy('model')
            ->orderByDesc(DB::raw('SUM(total_tokens)'))
            ->limit(15)
            ->get()
            ->map(fn($r) => ['model' => $r->model, 'tokens' => (int) $r->tokens, 'credits' => round((float) $r->credits, 4), 'requests' => (int) $r->requests])
            ->toArray();
    }

    private function getModelTimeline(?int $userId, string $period): array
    {
        $cfg = $this->getTimeConfig($period);
        $query = UsageLog::query();
        if ($userId) $query->where('user_id', $userId);
        if ($cfg['where']) $query->where('created_at', '>=', $cfg['where']);

        $raw = $query->selectRaw("{$cfg['label']} as label, {$cfg['group']} as grp, model, SUM(total_tokens) as tokens")
            ->groupBy(DB::raw($cfg['group']), DB::raw($cfg['label']), 'model')
            ->orderBy(DB::raw($cfg['group']))
            ->get();

        $pivoted = [];
        $models = [];
        foreach ($raw as $r) {
            $pivoted[$r->label] = $pivoted[$r->label] ?? ['label' => $r->label];
            $pivoted[$r->label][$r->model] = (int) $r->tokens;
            $models[$r->model] = true;
        }

        return [
            'data' => array_values($pivoted),
            'models' => array_keys($models),
        ];
    }
}
