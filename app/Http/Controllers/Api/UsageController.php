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
        $tz = $request->query('tz', 'Asia/Jakarta');

        // Validate timezone
        try { new \DateTimeZone($tz); } catch (\Exception $e) { $tz = 'Asia/Jakarta'; }

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
            'timeline' => $this->getTimeline(null, $period, $tz),
            'by_model' => $this->getModelBreakdown(null, $period, $tz),
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
            'by_model' => $this->getModelBreakdown($userId, $period, $tz),
            'model_timeline' => $this->getModelTimeline($userId, $period, $tz),
        ];
    }

    private function getTimeConfig(string $period, string $tz): array
    {
        // Convert created_at to user's timezone for grouping
        $tzCol = "created_at AT TIME ZONE 'UTC' AT TIME ZONE '{$tz}'";

        return match ($period) {
            'hourly' => [
                'hours' => 24,
                'label' => "TO_CHAR({$tzCol}, 'HH24:00')",
                'group' => "DATE_TRUNC('hour', {$tzCol})",
            ],
            'weekly' => [
                'hours' => 12 * 7 * 24,
                'label' => "'W' || TO_CHAR({$tzCol}, 'IW')",
                'group' => "DATE_TRUNC('week', {$tzCol})",
            ],
            'monthly' => [
                'hours' => 365 * 24,
                'label' => "TO_CHAR({$tzCol}, 'YYYY-MM')",
                'group' => "DATE_TRUNC('month', {$tzCol})",
            ],
            'all' => [
                'hours' => null,
                'label' => "TO_CHAR({$tzCol}, 'YYYY-MM')",
                'group' => "DATE_TRUNC('month', {$tzCol})",
            ],
            default => [ // daily
                'hours' => 30 * 24,
                'label' => "TO_CHAR({$tzCol}, 'MM-DD')",
                'group' => "DATE_TRUNC('day', {$tzCol})",
            ],
        };
    }

    private function getTimeline(?int $userId, string $period, string $tz): array
    {
        $cfg = $this->getTimeConfig($period, $tz);
        $query = UsageLog::query();
        if ($userId) $query->where('user_id', $userId);
        if ($cfg['hours']) $query->where('created_at', '>=', now()->subHours($cfg['hours']));

        return $query->selectRaw("{$cfg['label']} as label, {$cfg['group']} as grp, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests")
            ->groupBy(DB::raw($cfg['group']), DB::raw($cfg['label']))
            ->orderBy(DB::raw($cfg['group']))
            ->get()
            ->map(fn($r) => ['label' => $r->label, 'tokens' => (int) $r->tokens, 'credits' => round((float) $r->credits, 4), 'requests' => (int) $r->requests])
            ->toArray();
    }

    private function getModelBreakdown(?int $userId, string $period, string $tz): array
    {
        $cfg = $this->getTimeConfig($period, $tz);
        $query = UsageLog::query();
        if ($userId) $query->where('user_id', $userId);
        if ($cfg['hours']) $query->where('created_at', '>=', now()->subHours($cfg['hours']));

        return $query->selectRaw('model, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests')
            ->groupBy('model')
            ->orderByDesc(DB::raw('SUM(total_tokens)'))
            ->limit(15)
            ->get()
            ->map(fn($r) => ['model' => $r->model, 'tokens' => (int) $r->tokens, 'credits' => round((float) $r->credits, 4), 'requests' => (int) $r->requests])
            ->toArray();
    }

    private function getModelTimeline(?int $userId, string $period, string $tz): array
    {
        $cfg = $this->getTimeConfig($period, $tz);
        $query = UsageLog::query();
        if ($userId) $query->where('user_id', $userId);
        if ($cfg['hours']) $query->where('created_at', '>=', now()->subHours($cfg['hours']));

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
