<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DurationOrder;
use App\Models\TokenReservation;
use App\Models\UsageLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminStatsController extends Controller
{
    /**
     * Revenue overview stats
     */
    public function revenue(Request $request)
    {
        $validated = $request->validate(['month' => ['sometimes', 'date_format:Y-m']]);
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $usageMonth = isset($validated['month'])
            ? Carbon::createFromFormat('!Y-m', $validated['month'])
            : $startOfMonth->copy();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

        // Revenue this month (approved orders)
        $revenueThisMonth = DurationOrder::where('status', 'approved')
            ->where('approved_at', '>=', $startOfMonth)
            ->sum('price');

        // Revenue last month
        $revenueLastMonth = DurationOrder::where('status', 'approved')
            ->whereBetween('approved_at', [$startOfLastMonth, $endOfLastMonth])
            ->sum('price');

        // Total revenue all time
        $totalRevenue = DurationOrder::where('status', 'approved')->sum('price');

        // Orders stats
        $ordersThisMonth = DurationOrder::where('created_at', '>=', $startOfMonth)->count();
        $pendingOrders = DurationOrder::where('status', 'pending')->count();
        $approvedThisMonth = DurationOrder::where('status', 'approved')
            ->where('approved_at', '>=', $startOfMonth)->count();

        // User stats
        $totalUsers = User::where('role', 'member')->count();
        $activeUsers = User::where('role', 'member')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->count();
        $newUsersThisMonth = User::where('role', 'member')
            ->where('created_at', '>=', $startOfMonth)->count();

        // Revenue by day (last 30 days)
        $dailyRevenue = DurationOrder::where('status', 'approved')
            ->where('approved_at', '>=', $now->copy()->subDays(30))
            ->select(DB::raw('DATE(approved_at) as date'), DB::raw('SUM(price) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Revenue by package
        $revenueByPackage = DurationOrder::where('status', 'approved')
            ->where('approved_at', '>=', $startOfMonth)
            ->select('package', DB::raw('COUNT(*) as count'), DB::raw('SUM(price) as total'))
            ->groupBy('package')
            ->get();

        // Recent approved orders
        $recentOrders = DurationOrder::with('user:id,name,email')
            ->where('status', 'approved')
            ->orderByDesc('approved_at')
            ->limit(10)
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'user_name' => $o->user->name ?? '-',
                'user_email' => $o->user->email ?? '-',
                'package' => $o->package,
                'days' => $o->days,
                'price' => $o->price,
                'approved_at' => $o->approved_at,
            ]);

        return response()->json([
            'revenue_this_month' => $revenueThisMonth,
            'revenue_last_month' => $revenueLastMonth,
            'total_revenue' => $totalRevenue,
            'orders_this_month' => $ordersThisMonth,
            'pending_orders' => $pendingOrders,
            'approved_this_month' => $approvedThisMonth,
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'new_users_this_month' => $newUsersThisMonth,
            'daily_revenue' => $dailyRevenue,
            'revenue_by_package' => $revenueByPackage,
            'recent_orders' => $recentOrders,
            'usage_earnings' => $this->usageEarnings($usageMonth, $now),
        ]);
    }

    private function usageEarnings(Carbon $month, Carbon $now): array
    {
        $nextMonth = $month->copy()->addMonth();

        // Web chat and API costs are recorded only after wallet settlement succeeds.
        $payg = UsageLog::query()
            ->whereIn('source', ['api', 'web'])
            ->where('cost_microusd', '>', 0)
            ->where('created_at', '<=', $now);
        $paygByModel = (clone $payg)
            ->where('created_at', '>=', $month)
            ->where('created_at', '<', $nextMonth)
            ->select('model', 'source')
            ->selectRaw('SUM(cost_microusd) as cost_microusd, COUNT(*) as requests')
            ->groupBy('model', 'source')
            ->orderByDesc('cost_microusd')
            ->orderBy('model')
            ->get()
            ->map(fn (UsageLog $row): array => [
                'service' => $row->source === 'web' ? 'chat' : 'api',
                'model' => $row->model,
                'cost_microusd' => (int) $row->cost_microusd,
                'requests' => (int) $row->getAttribute('requests'),
            ]);

        $generators = TokenReservation::query()
            ->where('billing_mode', 'tokens')
            ->where('status', TokenReservation::STATUS_SETTLED)
            ->whereIn('service', ['image', 'video', 'audio', 'model3d'])
            ->where('amount_tokens', '>', 0)
            ->where('settled_at', '<=', $now);
        $generatorsByModel = (clone $generators)
            ->where('settled_at', '>=', $month)
            ->where('settled_at', '<', $nextMonth)
            ->select('service', 'model')
            ->selectRaw('SUM(amount_tokens) as tokens, SUM(quantity) as generations')
            ->groupBy('service', 'model')
            ->orderByDesc('tokens')
            ->orderBy('service')
            ->orderBy('model')
            ->get()
            ->map(fn (TokenReservation $row): array => [
                'service' => $row->service,
                'model' => $row->model,
                'tokens' => (int) $row->getAttribute('tokens'),
                'generations' => (int) $row->getAttribute('generations'),
            ]);

        return [
            'month' => $month->format('Y-m'),
            'payg' => [
                'currency' => 'USD',
                'month_cost_microusd' => (int) $paygByModel->sum('cost_microusd'),
                'total_cost_microusd' => (int) $payg->sum('cost_microusd'),
                'by_model' => $paygByModel,
            ],
            'generators' => [
                'unit' => 'tokens',
                'month_tokens' => (int) $generatorsByModel->sum('tokens'),
                'total_tokens' => (int) $generators->sum('amount_tokens'),
                'by_model' => $generatorsByModel,
            ],
        ];
    }

    /**
     * Expiring users list
     */
    public function expiringUsers(Request $request)
    {
        $days = max(1, min(365, $request->integer('days', 7)));

        $users = User::where('role', 'member')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($days))
            ->orderBy('expires_at')
            ->get(['id', 'name', 'email', 'expires_at', 'created_at'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'expires_at' => $u->expires_at,
                'days_remaining' => (int) now()->diffInDays($u->expires_at, false),
            ]);

        $expiredUsers = User::where('role', 'member')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderByDesc('expires_at')
            ->limit(50)
            ->get(['id', 'name', 'email', 'expires_at'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'expires_at' => $u->expires_at,
                'days_expired' => (int) $u->expires_at->diffInDays(now()),
            ]);

        return response()->json([
            'expiring' => $users,
            'expired' => $expiredUsers,
            'expiring_count' => $users->count(),
            'expired_count' => $expiredUsers->count(),
        ]);
    }

    /**
     * Orders list with filters
     */
    public function orders(Request $request)
    {
        $query = DurationOrder::with('user:id,name,email')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $needle = '%'.mb_strtolower($search).'%';
                $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);
            });
        }

        $orders = $query->paginate(20)->through(fn ($o) => [
            'id' => $o->id,
            'user_id' => $o->user_id,
            'user_name' => $o->user->name ?? '-',
            'user_email' => $o->user->email ?? '-',
            'package' => $o->package,
            'days' => $o->days,
            'price' => $o->price,
            'status' => $o->status,
            'note' => $o->note,
            'created_at' => $o->created_at,
            'approved_at' => $o->approved_at,
        ]);

        return response()->json($orders);
    }
}
