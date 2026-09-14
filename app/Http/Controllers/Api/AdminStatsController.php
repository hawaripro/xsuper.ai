<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DurationOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminStatsController extends Controller
{
    /**
     * Revenue overview stats
     */
    public function revenue(Request $request)
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
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
            ->map(fn($o) => [
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
        ]);
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
            ->map(fn($u) => [
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
            ->map(fn($u) => [
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

        $orders = $query->paginate(20)->through(fn($o) => [
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
