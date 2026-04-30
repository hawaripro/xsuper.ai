<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    // Admin: get usage stats for all users or specific user
    public function index(Request $request)
    {
        $userId = $request->query('user_id');

        if ($userId) {
            $stats = UsageLog::userStats((int) $userId);
            return response()->json($stats);
        }

        // Overview for all users
        $topUsers = UsageLog::topUsers(20);
        $totalStats = UsageLog::selectRaw('
            SUM(total_tokens) as tokens,
            SUM(credit) as credits,
            COUNT(*) as requests,
            COUNT(DISTINCT user_id) as active_users
        ')->first();

        $dailyTotal = UsageLog::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, SUM(total_tokens) as tokens, COUNT(*) as requests')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $byModel = UsageLog::selectRaw('model, SUM(total_tokens) as tokens, COUNT(*) as requests')
            ->groupBy('model')
            ->orderByDesc('tokens')
            ->limit(20)
            ->get();

        return response()->json([
            'total_tokens' => (int) ($totalStats->tokens ?? 0),
            'total_credits' => round($totalStats->credits ?? 0, 4),
            'total_requests' => (int) ($totalStats->requests ?? 0),
            'active_users' => (int) ($totalStats->active_users ?? 0),
            'top_users' => $topUsers,
            'daily' => $dailyTotal,
            'by_model' => $byModel,
        ]);
    }
}
