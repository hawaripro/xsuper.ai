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
        $period = $request->query('period', 'daily'); // hourly, daily, weekly, monthly, all

        if ($userId) {
            return response()->json($this->userStats((int) $userId, $period));
        }

        return response()->json($this->globalStats($period));
    }

    private function globalStats(string $period): array
    {
        $totalStats = UsageLog::selectRaw('
            SUM(total_tokens) as tokens,
            SUM(credit) as credits,
            COUNT(*) as requests,
            COUNT(DISTINCT user_id) as active_users
        ')->first();

        $topUsers = UsageLog::selectRaw('user_id, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests')
            ->groupBy('user_id')
            ->orderByDesc('tokens')
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
            SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests
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

    private function getTimeline(?int $userId, string $period): array
    {
        $query = UsageLog::query();
        if ($userId) $query->where('user_id', $userId);

        switch ($period) {
            case 'hourly':
                $query->where('created_at', '>=', now()->subHours(24));
                $labelExpr = "TO_CHAR(created_at, 'HH24:00')";
                $groupExpr = "TO_CHAR(created_at, 'YYYY-MM-DD HH24')";
                break;
            case 'weekly':
                $query->where('created_at', '>=', now()->subWeeks(12));
                $labelExpr = "TO_CHAR(created_at, 'IYYY-IW')";
                $groupExpr = "TO_CHAR(created_at, 'IYYY-IW')";
                break;
            case 'monthly':
                $query->where('created_at', '>=', now()->subMonths(12));
                $labelExpr = "TO_CHAR(created_at, 'YYYY-MM')";
                $groupExpr = "TO_CHAR(created_at, 'YYYY-MM')";
                break;
            case 'all':
                $labelExpr = "TO_CHAR(created_at, 'YYYY-MM')";
                $groupExpr = "TO_CHAR(created_at, 'YYYY-MM')";
                break;
            default: // daily
                $query->where('created_at', '>=', now()->subDays(30));
                $labelExpr = "TO_CHAR(created_at, 'MM-DD')";
                $groupExpr = "DATE(created_at)";
                break;
        }

        return $query->selectRaw("$labelExpr as label, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests")
            ->groupBy(DB::raw($groupExpr), DB::raw($labelExpr))
            ->orderBy(DB::raw($groupExpr))
            ->get()
            ->toArray();
    }

    private function getModelBreakdown(?int $userId, string $period): array
    {
        $query = UsageLog::query();
        if ($userId) $query->where('user_id', $userId);

        if ($period === 'hourly') $query->where('created_at', '>=', now()->subHours(24));
        elseif ($period === 'daily') $query->where('created_at', '>=', now()->subDays(30));
        elseif ($period === 'weekly') $query->where('created_at', '>=', now()->subWeeks(12));
        elseif ($period === 'monthly') $query->where('created_at', '>=', now()->subMonths(12));

        return $query->selectRaw('model, SUM(total_tokens) as tokens, SUM(credit) as credits, COUNT(*) as requests')
            ->groupBy('model')
            ->orderByDesc('tokens')
            ->limit(15)
            ->get()
            ->toArray();
    }

    private function getModelTimeline(?int $userId, string $period): array
    {
        $query = UsageLog::query();
        if ($userId) $query->where('user_id', $userId);

        switch ($period) {
            case 'hourly':
                $query->where('created_at', '>=', now()->subHours(24));
                $dateExpr = "TO_CHAR(created_at, 'HH24:00')";
                $groupExpr = "TO_CHAR(created_at, 'YYYY-MM-DD HH24')";
                break;
            case 'weekly':
                $query->where('created_at', '>=', now()->subWeeks(12));
                $dateExpr = "TO_CHAR(created_at, 'IYYY-IW')";
                $groupExpr = "TO_CHAR(created_at, 'IYYY-IW')";
                break;
            case 'monthly':
                $query->where('created_at', '>=', now()->subMonths(12));
                $dateExpr = "TO_CHAR(created_at, 'YYYY-MM')";
                $groupExpr = "TO_CHAR(created_at, 'YYYY-MM')";
                break;
            default:
                $query->where('created_at', '>=', now()->subDays(30));
                $dateExpr = "TO_CHAR(created_at, 'MM-DD')";
                $groupExpr = "DATE(created_at)";
                break;
        }

        $raw = $query->selectRaw("$dateExpr as label, model, SUM(total_tokens) as tokens")
            ->groupBy(DB::raw($groupExpr), DB::raw($dateExpr), DB::raw('model'))
            ->orderBy(DB::raw($groupExpr))
            ->get();

        // Pivot: group by label, each model as a key
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
