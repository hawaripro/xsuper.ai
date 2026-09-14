<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DurationOrder;
use App\Models\DurationPackagePrice;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PeriodController extends Controller
{
    /**
     * List all orders (admin) or user's own orders
     */
    public function index(Request $request)
    {
        $query = DurationOrder::with('user:id,name,email,role,expires_at')
            ->orderByDesc('created_at');

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        $orders = $query->limit(100)->get()->map(function ($order) {
            return [
                'id' => $order->id,
                'user_id' => $order->user_id,
                'user_name' => $order->user->name ?? '-',
                'user_email' => $order->user->email ?? '-',
                'user_role' => $order->user->role ?? 'member',
                'user_expires_at' => $order->user->expires_at,
                'package' => $order->package,
                'days' => $order->days,
                'price' => $order->price,
                'status' => $order->status,
                'note' => $order->note,
                'created_at' => $order->created_at,
                'approved_at' => $order->approved_at,
            ];
        });

        $pendingCount = DurationOrder::where('status', 'pending')->count();

        return response()->json([
            'orders' => $orders,
            'pending_count' => $pendingCount,
        ]);
    }

    /**
     * Member creates a duration order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'package' => 'required|string|in:1_day,1_week,1_month,3_months,6_months,12_months',
        ]);

        $user = Auth::user();
        $pkg = DurationPackagePrice::catalog()[$validated['package']];
        abort_unless($pkg['is_active'], 422, 'Paket tidak aktif.');

        // Check if user already has a pending order
        $existing = DurationOrder::where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Anda sudah memiliki order yang menunggu persetujuan.',
                'existing' => true,
            ], 422);
        }

        $order = DurationOrder::create([
            'user_id' => $user->id,
            'package' => $validated['package'],
            'days' => $pkg['days'],
            'price' => $pkg['price'],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Order berhasil dibuat. Menunggu persetujuan admin.',
            'order' => $order,
        ], 201);
    }

    /**
     * Admin approves an order — adds duration to user
     */
    public function approve(Request $request, DurationOrder $order, ReferralService $referrals)
    {
        [$approvedOrder, $newExpiry] = DB::transaction(function () use ($request, $order, $referrals): array {
            $approvedOrder = DurationOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($approvedOrder->status !== 'pending') {
                throw ValidationException::withMessages([
                    'order' => 'Order sudah diproses.',
                ]);
            }

            $user = User::query()->lockForUpdate()->findOrFail($approvedOrder->user_id);
            $now = now();
            $startFrom = $user->expires_at && $user->expires_at->isFuture()
                ? $user->expires_at->copy()
                : $now;
            $newExpiry = $startFrom->addDays($approvedOrder->days);

            $user->forceFill(['expires_at' => $newExpiry])->save();
            $approvedOrder->update([
                'status' => 'approved',
                'approved_at' => $now,
                'approved_by' => Auth::id(),
                'note' => $request->input('note'),
            ]);

            $referrals->rewardFirstPurchase($approvedOrder, $request->user());

            return [$approvedOrder, $user->fresh()->expires_at];
        });

        return response()->json([
            'message' => "Durasi {$approvedOrder->days} hari ditambahkan. Aktif sampai {$newExpiry->format('d M Y H:i')}.",
            'new_expires_at' => $newExpiry,
        ]);
    }

    /**
     * Admin rejects an order
     */
    public function reject(Request $request, DurationOrder $order)
    {
        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Order sudah diproses.'], 422);
        }

        $order->update([
            'status' => 'rejected',
            'note' => $request->input('note', 'Ditolak oleh admin.'),
        ]);

        return response()->json(['message' => 'Order ditolak.']);
    }

    /**
     * Admin deletes an order
     */
    public function destroy(DurationOrder $order)
    {
        $order->delete();

        return response()->json(['message' => 'Order dihapus.']);
    }

    /**
     * Admin manually adds duration to a user
     */
    public function addDuration(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'days' => 'required|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $user = User::findOrFail($validated['user_id']);
        $now = now();

        $currentExpiry = $user->expires_at;
        $startFrom = ($currentExpiry && $currentExpiry->isFuture()) ? $currentExpiry : $now;
        $newExpiry = $startFrom->copy()->addDays($validated['days']);

        $user->expires_at = $newExpiry;
        $user->save();

        // Log as approved order
        DurationOrder::create([
            'user_id' => $user->id,
            'package' => 'manual',
            'days' => $validated['days'],
            'price' => 0,
            'status' => 'approved',
            'approved_at' => $now,
            'approved_by' => Auth::id(),
            'note' => $validated['note'] ?? 'Manual oleh admin',
        ]);

        return response()->json([
            'message' => "Durasi {$validated['days']} hari ditambahkan ke {$user->name}.",
            'new_expires_at' => $newExpiry,
        ]);
    }

    /**
     * Get user's own orders
     */
    public function myOrders(Request $request)
    {
        $user = Auth::user();
        $orders = DurationOrder::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /**
     * Get packages list
     */
    public function packages()
    {
        return response()->json(['packages' => DurationPackagePrice::catalog()]);
    }
}
