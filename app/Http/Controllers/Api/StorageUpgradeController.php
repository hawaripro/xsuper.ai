<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentCheckout;
use App\Models\StorageUpgradeOrder;
use App\Models\StorageUpgradePlan;
use App\Models\User;
use App\Services\AuditService;
use App\Services\StorageQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Member purchase + admin approval for time-limited storage upgrades, mirroring
 * the duration-order flow: QRIS checkout -> pending order -> admin approve grants
 * a user_storage_upgrades row that raises the Library quota until it expires.
 */
class StorageUpgradeController extends Controller
{
    public function plans(): JsonResponse
    {
        return response()->json(['plans' => StorageUpgradePlan::catalog()]);
    }

    public function usage(Request $request, StorageQuotaService $storage): JsonResponse
    {
        return response()->json(['storage' => $storage->summary($request->user())]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate(['plan' => ['required', 'string', 'max:64']]);
        $plan = StorageUpgradePlan::query()->where('key', $validated['plan'])->where('is_active', true)->first();
        abort_unless($plan !== null, 422, 'Paket penyimpanan tidak tersedia.');

        $checkout = DB::transaction(function () use ($request, $plan): PaymentCheckout {
            PaymentCheckout::query()->where('user_id', $request->user()->id)->whereNull('used_at')
                ->where('expires_at', '>', now())->lockForUpdate()->update(['expires_at' => now()]);

            return PaymentCheckout::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $request->user()->id,
                'package' => $plan->key,
                'payment_method' => 'qris',
                'amount_idr' => (int) $plan->price_idr,
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'max:64'],
            'payment_reference' => ['required', 'uuid'],
        ]);
        $user = Auth::user();
        $plan = StorageUpgradePlan::query()->where('key', $validated['plan'])->where('is_active', true)->first();
        abort_unless($plan !== null, 422, 'Paket penyimpanan tidak tersedia.');

        if (StorageUpgradeOrder::query()->where('user_id', $user->id)->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'Anda sudah memiliki order penyimpanan yang menunggu persetujuan.', 'existing' => true], 422);
        }

        $order = DB::transaction(function () use ($user, $validated, $plan): StorageUpgradeOrder {
            $checkout = PaymentCheckout::query()->whereKey($validated['payment_reference'])
                ->where('user_id', $user->id)->where('package', $plan->key)->lockForUpdate()->first();
            if (! $checkout || $checkout->used_at !== null || $checkout->expires_at === null || $checkout->expires_at->isPast()) {
                throw ValidationException::withMessages(['payment_reference' => 'Checkout QRIS tidak valid, sudah digunakan, atau kedaluwarsa.']);
            }
            $consumed = PaymentCheckout::query()->whereKey($checkout->reference)->whereNull('used_at')->update(['used_at' => now()]);
            if ($consumed !== 1) {
                throw ValidationException::withMessages(['payment_reference' => 'Checkout QRIS tidak valid, sudah digunakan, atau kedaluwarsa.']);
            }

            return StorageUpgradeOrder::create([
                'user_id' => $user->id,
                'plan_key' => $plan->key,
                'label' => $plan->label,
                'extra_bytes' => (int) $plan->extra_bytes,
                'days' => (int) $plan->days,
                'price' => $checkout->amount_idr,
                'payment_method' => 'qris',
                'payment_reference' => $checkout->reference,
                'payment_expires_at' => $checkout->expires_at,
                'payment_confirmed_at' => now(),
                'status' => 'pending',
            ]);
        });

        return response()->json(['message' => 'Pembayaran dikonfirmasi. Order menunggu persetujuan admin.', 'order' => $order], 201);
    }

    public function myOrders(Request $request): JsonResponse
    {
        return response()->json([
            'orders' => StorageUpgradeOrder::query()->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')->limit(50)->get(),
        ]);
    }

    public function cancel(Request $request, StorageUpgradeOrder $order): JsonResponse
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id, 404);
        $cancelled = DB::transaction(function () use ($order): StorageUpgradeOrder {
            $locked = StorageUpgradeOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Order yang sudah diproses tidak dapat dibatalkan.']);
            }
            $locked->update(['status' => 'cancelled', 'note' => 'Dibatalkan oleh pemilik akun.']);

            return $locked;
        });

        return response()->json(['message' => 'Order penyimpanan dibatalkan.', 'order' => $cancelled]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = StorageUpgradeOrder::with('user:id,name,email,role')->orderByDesc('created_at');
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        $orders = $query->limit(100)->get()->map(fn (StorageUpgradeOrder $order): array => [
            'id' => $order->id,
            'user_id' => $order->user_id,
            'user_name' => $order->user->name ?? '-',
            'user_email' => $order->user->email ?? '-',
            'plan_key' => $order->plan_key,
            'label' => $order->label,
            'extra_bytes' => $order->extra_bytes,
            'days' => $order->days,
            'price' => $order->price,
            'status' => $order->status,
            'note' => $order->note,
            'created_at' => $order->created_at,
            'approved_at' => $order->approved_at,
        ]);

        return response()->json([
            'orders' => $orders,
            'pending_count' => StorageUpgradeOrder::where('status', 'pending')->count(),
        ]);
    }

    public function approve(Request $request, StorageUpgradeOrder $order, StorageQuotaService $storage, AuditService $audit): JsonResponse
    {
        [$approved, $grant] = DB::transaction(function () use ($request, $order, $storage): array {
            $locked = StorageUpgradeOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Order sudah diproses.']);
            }
            $user = User::query()->lockForUpdate()->findOrFail($locked->user_id);
            $grant = $storage->grantUpgrade($user, $locked->plan_key, (int) $locked->extra_bytes, (int) $locked->days, $locked->id);
            $locked->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
                'note' => $request->input('note'),
            ]);

            return [$locked, $grant];
        });
        $audit->record($request->user(), 'storage_upgrade.approved', $approved);

        return response()->json([
            'message' => "Penyimpanan +{$this->gb($approved->extra_bytes)} GB aktif sampai {$grant->expires_at->format('d M Y H:i')}.",
            'expires_at' => $grant->expires_at,
        ]);
    }

    public function reject(Request $request, StorageUpgradeOrder $order): JsonResponse
    {
        // Re-read under lock: the route-bound model may predate a concurrent approval or cancellation.
        DB::transaction(function () use ($request, $order): void {
            $locked = StorageUpgradeOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Order sudah diproses.']);
            }
            $locked->update(['status' => 'rejected', 'note' => $request->input('note', 'Ditolak oleh admin.')]);
        });

        return response()->json(['message' => 'Order ditolak.']);
    }

    private function gb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / (1024 ** 3), 2, '.', ''), '0'), '.');
    }
}
