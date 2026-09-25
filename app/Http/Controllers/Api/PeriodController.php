<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DurationOrder;
use App\Models\DurationPackagePrice;
use App\Models\User;
use App\Models\UserToken;
use App\Models\Wallet;
use App\Models\PaymentCheckout;
use App\Services\AuditService;
use App\Services\ReferralService;
use App\Services\StorageQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
                'bonus_tokens' => (int) $order->bonus_tokens,
                'bonus_wallet_microusd' => (int) $order->bonus_wallet_microusd,
                'storage_bytes' => (int) $order->storage_bytes,
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
     * Create a short-lived, user-scoped QRIS checkout before order submission.
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'package' => 'required|string|in:1_day,1_week,1_month,3_months,6_months,12_months',
        ]);
        $package = DurationPackagePrice::catalog()[$validated['package']];
        abort_unless($package['is_active'], 422, 'Paket tidak aktif.');

        $checkout = DB::transaction(function () use ($request, $validated, $package): PaymentCheckout {
            // One active checkout per user: stale attempts expire so older QR codes can no longer be ordered.
            PaymentCheckout::query()
                ->where('user_id', $request->user()->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->update(['expires_at' => now()]);

            return PaymentCheckout::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $request->user()->id,
                'package' => $validated['package'],
                'payment_method' => 'qris',
                'amount_idr' => (int) $package['price'],
                'expires_at' => now()->addHour(),
            ]);
        });

        return response()->json([
            'checkout' => [
                'payment_reference' => $checkout->reference,
                'payment_method' => $checkout->payment_method,
                'amount_idr' => $checkout->amount_idr,
                'expires_at' => $checkout->expires_at->toISOString(),
                'qr_image_url' => '/assets/payments/qris-xsuper.png',
            ],
        ], 201);
    }

    /**
     * Member creates a duration order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'package' => 'required|string|in:1_day,1_week,1_month,3_months,6_months,12_months',
            'payment_reference' => ['required', 'uuid'],
        ]);

        $user = Auth::user();
        $pkg = DurationPackagePrice::catalog()[$validated['package']];
        abort_unless($pkg['is_active'], 422, 'Paket tidak aktif.');

        $existing = DurationOrder::where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();
        if ($existing) {
            return response()->json([
                'message' => 'Anda sudah memiliki order yang menunggu persetujuan.',
                'existing' => true,
            ], 422);
        }

        $order = DB::transaction(function () use ($user, $validated, $pkg): DurationOrder {
            $checkout = PaymentCheckout::query()
                ->whereKey($validated['payment_reference'])
                ->where('user_id', $user->id)
                ->where('package', $validated['package'])
                ->lockForUpdate()
                ->first();

            if (! $checkout || $checkout->used_at !== null || $checkout->expires_at === null || $checkout->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'payment_reference' => 'Checkout QRIS tidak valid, sudah digunakan, atau kedaluwarsa.',
                ]);
            }

            // Single-use: consume only while still unconsumed (guards concurrent double-submit).
            $consumed = PaymentCheckout::query()
                ->whereKey($checkout->reference)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
            if ($consumed !== 1) {
                throw ValidationException::withMessages([
                    'payment_reference' => 'Checkout QRIS tidak valid, sudah digunakan, atau kedaluwarsa.',
                ]);
            }

            return DurationOrder::create([
                'user_id' => $user->id,
                'package' => $validated['package'],
                'days' => $pkg['days'],
                'price' => $checkout->amount_idr,
                'bonus_tokens' => $pkg['bonus_tokens'],
                'bonus_wallet_microusd' => $pkg['bonus_wallet_microusd'],
                'storage_bytes' => $pkg['storage_bytes'],
                'payment_method' => 'qris',
                'payment_reference' => $checkout->reference,
                'payment_expires_at' => $checkout->expires_at,
                'payment_confirmed_at' => now(),
                'status' => 'pending',
            ]);
        });

        return response()->json([
            'message' => 'Pembayaran dikonfirmasi. Order menunggu persetujuan admin.',
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

            [, $participants] = $referrals->lockPurchaseParticipants($approvedOrder);
            $user = $participants->findOrFail($approvedOrder->user_id);
            $now = now();
            $startFrom = $user->expires_at && $user->expires_at->isFuture()
                ? $user->expires_at->copy()
                : $now->copy();
            $newExpiry = $startFrom->addDays($approvedOrder->days);

            $user->forceFill(['expires_at' => $newExpiry])->save();
            $approvedOrder->update([
                'status' => 'approved',
                'approved_at' => $now,
                'approved_by' => Auth::id(),
                'note' => $request->input('note'),
            ]);

            $referrals->rewardFirstPurchase($approvedOrder, $request->user());
            $membershipEnd = $user->fresh()->expires_at;
            $reference = 'duration-order:'.$approvedOrder->id;
            if ($approvedOrder->bonus_tokens > 0) {
                UserToken::topup($user->id, (int) $approvedOrder->bonus_tokens, 'Bonus token langganan', $reference);
            }
            if ($approvedOrder->bonus_wallet_microusd > 0) {
                Wallet::credit($user->id, (int) $approvedOrder->bonus_wallet_microusd, 'Bonus Saldo AI langganan', $reference, 'membership');
            }
            app(StorageQuotaService::class)->grantMembershipStorage($user, $approvedOrder, $membershipEnd);
            app(AuditService::class)->record($request->user(), 'membership.approved', $approvedOrder, [
                'bonus_tokens' => (int) $approvedOrder->bonus_tokens,
                'bonus_wallet_microusd' => (int) $approvedOrder->bonus_wallet_microusd,
                'storage_bytes' => (int) $approvedOrder->storage_bytes,
                'expires_at' => $membershipEnd->toISOString(),
            ]);

            return [$approvedOrder, $user->fresh()->expires_at];
        });

        return response()->json([
            'message' => "Langganan diperpanjang {$approvedOrder->days} hari. Bonus token, Saldo AI, dan penyimpanan sesuai pesanan telah diberikan.",
            'new_expires_at' => $newExpiry,
            'benefits' => $approvedOrder->only(['bonus_tokens', 'bonus_wallet_microusd', 'storage_bytes']),
        ]);
    }

    /**
     * Member cancels their own pending subscription order before an admin decision.
     */
    public function cancel(Request $request, DurationOrder $order)
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id, 404);

        $cancelled = DB::transaction(function () use ($order): DurationOrder {
            $locked = DurationOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Order yang sudah diproses tidak dapat dibatalkan.']);
            }
            $locked->update(['status' => 'cancelled', 'note' => 'Dibatalkan oleh pemilik akun.']);

            return $locked;
        });

        return response()->json(['message' => 'Order langganan dibatalkan.', 'order' => $cancelled]);
    }

    /**
     * Admin rejects an order
     */
    public function reject(Request $request, DurationOrder $order)
    {
        // Re-read under lock: the route-bound model may predate a concurrent approval or cancellation.
        DB::transaction(function () use ($request, $order): void {
            $locked = DurationOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Order sudah diproses.']);
            }

            $locked->update([
                'status' => 'rejected',
                'note' => $request->input('note', 'Ditolak oleh admin.'),
            ]);
        });

        return response()->json(['message' => 'Order ditolak.']);
    }

    /**
     * Admin deletes an order
     */
    public function destroy(DurationOrder $order)
    {
        DB::transaction(function () use ($order): void {
            $locked = DurationOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_if($locked->status === 'approved', 409, 'Pesanan yang sudah disetujui tidak bisa dihapus.');
            $locked->delete();
        });

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

        [$user, $newExpiry] = DB::transaction(function () use ($validated, $request): array {
            $user = User::query()->lockForUpdate()->findOrFail($validated['user_id']);
            $startFrom = $user->hasActiveMembership() ? $user->expires_at->copy() : now();
            $newExpiry = $startFrom->addDays($validated['days']);
            $user->forceFill(['expires_at' => $newExpiry])->save();

            // Manual duration grants days only: benefit snapshots remain zero.
            $order = DurationOrder::create([
                'user_id' => $user->id, 'package' => 'manual', 'days' => $validated['days'],
                'price' => 0, 'status' => 'approved', 'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'note' => $validated['note'] ?? 'Manual oleh admin',
            ]);
            app(AuditService::class)->record($request->user(), 'membership.duration_added', $order, [
                'days' => (int) $validated['days'], 'expires_at' => $newExpiry->toISOString(),
            ]);

            return [$user, $newExpiry];
        });

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
