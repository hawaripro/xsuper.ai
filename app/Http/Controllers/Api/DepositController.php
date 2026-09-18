<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DepositOrder;
use App\Models\DurationPackagePrice;
use App\Models\TokenPackage;
use App\Models\User;
use App\Models\UserToken;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepositController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function catalog(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $durationPackages = array_filter(
            DurationPackagePrice::catalog(),
            fn (array $package): bool => $package['is_active'],
        );
        $walletBalance = Wallet::balance((int) $user->id);

        return response()->json([
            'token_packages' => TokenPackage::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (TokenPackage $package): array => $package->toCatalogArray())
                ->values(),
            'wallet' => [
                'balance_microusd' => $walletBalance,
                'balance_usd' => $walletBalance / 1_000_000,
            ],
            'token_balance' => UserToken::getBalance((int) $user->id),
            'conversion' => ['idr_per_usd' => $this->conversionRate()],
            'limits' => [
                'min_idr' => $this->minimumIdr(),
                'max_idr' => $this->maximumIdr(),
            ],
            'duration_packages' => $durationPackages,
        ]);
    }

    public function adminCatalog(Request $request): JsonResponse
    {
        $this->adminUser($request);

        return response()->json([
            'token_packages' => TokenPackage::query()->orderBy('sort_order')->orderBy('id')->get()->map(
                fn (TokenPackage $package): array => [
                    'id' => $package->id,
                    ...$package->toCatalogArray(),
                    'is_active' => $package->is_active,
                ],
            )->values(),
        ]);
    }

    public function updateTokenPackage(Request $request, TokenPackage $tokenPackage): JsonResponse
    {
        $admin = $this->adminUser($request);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'base_tokens' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'bonus_tokens' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
            'price_idr' => ['sometimes', 'required', 'integer', 'min:1', 'max:4294967295'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
        if ($validated === []) {
            throw ValidationException::withMessages(['package' => ['At least one package field is required.']]);
        }

        $package = DB::transaction(function () use ($admin, $tokenPackage, $validated): TokenPackage {
            $packages = TokenPackage::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $package = $packages->get($tokenPackage->id);
            abort_if($package === null, 404);
            $nextBase = (int) ($validated['base_tokens'] ?? $package->base_tokens);
            $nextBonus = (int) ($validated['bonus_tokens'] ?? $package->bonus_tokens);
            if ($nextBase > 2_147_483_647 - $nextBonus) {
                throw ValidationException::withMessages(['bonus_tokens' => ['Total package tokens exceed the supported limit.']]);
            }
            if (($validated['is_active'] ?? $package->is_active) === false
                && ! $packages->except($package->id)->contains(fn (TokenPackage $other): bool => $other->is_active)) {
                throw ValidationException::withMessages(['is_active' => ['At least one token package must remain active.']]);
            }

            $before = $package->only(['name', 'base_tokens', 'bonus_tokens', 'price_idr', 'is_active', 'sort_order']);
            $package->fill($validated)->save();
            $this->audit->record($admin, 'token_package.updated', $package, [
                'before' => $before,
                'after' => $package->only(['name', 'base_tokens', 'bonus_tokens', 'price_idr', 'is_active', 'sort_order']),
            ]);

            return $package;
        });

        return response()->json([
            'token_package' => [
                'id' => $package->id,
                ...$package->toCatalogArray(),
                'is_active' => $package->is_active,
            ],
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $validated = $request->validate([
            'kind' => ['required', Rule::in(DepositOrder::KINDS)],
            'package_code' => [
                Rule::requiredIf(fn (): bool => $request->input('kind') === DepositOrder::KIND_TOKENS),
                Rule::prohibitedIf(fn (): bool => $request->input('kind') !== DepositOrder::KIND_TOKENS),
                'string',
                'max:32',
            ],
            'amount_idr' => [
                Rule::requiredIf(fn (): bool => $request->input('kind') === DepositOrder::KIND_WALLET),
                Rule::prohibitedIf(fn (): bool => $request->input('kind') !== DepositOrder::KIND_WALLET),
                'integer',
                'min:'.$this->minimumIdr(),
                'max:'.$this->maximumIdr(),
            ],
        ]);
        $conversion = $this->conversionRate();
        $snapshot = $validated['kind'] === DepositOrder::KIND_TOKENS
            ? $this->tokenSnapshot((string) $validated['package_code'], $conversion)
            : $this->walletSnapshot((int) $validated['amount_idr'], $conversion);

        $order = DB::transaction(function () use ($user, $validated, $snapshot): DepositOrder {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $activeCheckouts = DepositOrder::query()
                ->where('user_id', $user->id)
                ->where('status', DepositOrder::STATUS_CHECKOUT)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->get();
            $sameSnapshot = $activeCheckouts->first(fn (DepositOrder $order): bool => $order->kind === $validated['kind']
                && $order->package_code === $snapshot['package_code']
                && $order->amount_idr === $snapshot['amount_idr']
                && $order->base_tokens === $snapshot['base_tokens']
                && $order->bonus_tokens === $snapshot['bonus_tokens']
                && $order->total_tokens === $snapshot['total_tokens']
                && $order->credit_microusd === $snapshot['credit_microusd']
                && $order->idr_per_usd === $snapshot['idr_per_usd']
            );
            if ($sameSnapshot !== null) {
                return $sameSnapshot;
            }

            if ($activeCheckouts->isNotEmpty()) {
                DepositOrder::query()
                    ->whereKey($activeCheckouts->pluck('id'))
                    ->update(['expires_at' => now()]);
            }

            return DepositOrder::create([
                'payment_reference' => (string) Str::uuid(),
                'user_id' => $user->id,
                'kind' => $validated['kind'],
                ...$snapshot,
                'payment_method' => 'qris',
                'status' => DepositOrder::STATUS_CHECKOUT,
                'expires_at' => now()->addMinutes($this->checkoutMinutes()),
            ]);
        });

        return response()->json(['checkout' => $order->toApiArray()], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $validated = $request->validate([
            'payment_reference' => ['required', 'uuid'],
        ]);

        $order = DB::transaction(function () use ($user, $validated): DepositOrder {
            $order = DepositOrder::query()
                ->where('payment_reference', $validated['payment_reference'])
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($order === null
                || $order->status !== DepositOrder::STATUS_CHECKOUT
                || $order->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'payment_reference' => ['Checkout QRIS tidak valid, sudah digunakan, atau kedaluwarsa.'],
                ]);
            }

            $order->update([
                'status' => DepositOrder::STATUS_PENDING,
                'confirmed_at' => now(),
            ]);

            return $order;
        });

        return response()->json([
            'message' => 'Pembayaran dikonfirmasi dan menunggu persetujuan admin.',
            'order' => $order->toApiArray(),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([...DepositOrder::STATUSES, 'expired'])],
            'kind' => ['sometimes', Rule::in(DepositOrder::KINDS)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = DepositOrder::query()->where('user_id', $user->id);

        if (isset($validated['kind'])) {
            $query->where('kind', $validated['kind']);
        }
        if (($validated['status'] ?? null) === 'expired') {
            $query->where('status', DepositOrder::STATUS_CHECKOUT)->where('expires_at', '<=', now());
        } elseif (($validated['status'] ?? null) === DepositOrder::STATUS_CHECKOUT) {
            $query->where('status', DepositOrder::STATUS_CHECKOUT)->where('expires_at', '>', now());
        } elseif (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json($this->paginatedOrders(
            $query->latest('id')->paginate($validated['per_page'] ?? 20)->withQueryString(),
        ));
    }

    public function show(Request $request, DepositOrder $depositOrder): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        abort_unless((int) $depositOrder->user_id === (int) $user->id, 404);
        $walletBalance = Wallet::balance((int) $user->id);

        return response()->json([
            'order' => $depositOrder->toApiArray(),
            'token_balance' => UserToken::getBalance((int) $user->id),
            'wallet' => [
                'balance_microusd' => $walletBalance,
                'balance_usd' => $walletBalance / 1_000_000,
            ],
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->adminUser($request);
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([
                DepositOrder::STATUS_PENDING,
                DepositOrder::STATUS_APPROVED,
                DepositOrder::STATUS_REJECTED,
            ])],
            'kind' => ['sometimes', Rule::in(DepositOrder::KINDS)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = DepositOrder::query()
            ->with('user:id,name,email')
            ->whereIn('status', [
                DepositOrder::STATUS_PENDING,
                DepositOrder::STATUS_APPROVED,
                DepositOrder::STATUS_REJECTED,
            ]);

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['kind'])) {
            $query->where('kind', $validated['kind']);
        }

        $pendingCount = DepositOrder::query()->where('status', DepositOrder::STATUS_PENDING)->count();
        $orders = $query->latest('id')->paginate($validated['per_page'] ?? 20)->withQueryString();

        return response()->json([
            ...$this->paginatedOrders($orders, true),
            'pending_count' => $pendingCount,
        ]);
    }

    public function approve(Request $request, DepositOrder $depositOrder): JsonResponse
    {
        $admin = $this->adminUser($request);
        $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        [$order, $idempotent] = DB::transaction(function () use ($admin, $depositOrder, $validated): array {
            $order = DepositOrder::query()->lockForUpdate()->findOrFail($depositOrder->id);
            if ($order->status === DepositOrder::STATUS_APPROVED) {
                $this->audit->record($admin, 'deposit.approval_replayed', $order, ['idempotent' => true]);

                return [$order, true];
            }
            if ($order->status !== DepositOrder::STATUS_PENDING) {
                throw ValidationException::withMessages(['order' => ['Deposit tidak dapat disetujui dari status ini.']]);
            }

            User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $reference = "deposit-order:{$order->id}";
            if ($order->kind === DepositOrder::KIND_TOKENS) {
                UserToken::topup(
                    (int) $order->user_id,
                    $order->total_tokens,
                    'Approved QRIS token deposit',
                    $reference,
                );
            } else {
                $alreadyCredited = WalletTransaction::query()
                    ->where('user_id', $order->user_id)
                    ->where('type', 'deposit')
                    ->where('reference_id', $reference)
                    ->exists();
                if (! $alreadyCredited) {
                    Wallet::credit(
                        (int) $order->user_id,
                        $order->credit_microusd,
                        'Approved QRIS wallet deposit',
                        $reference,
                        'deposit',
                    );
                }
            }

            $order->update([
                'status' => DepositOrder::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $admin->id,
                'note' => $validated['note'] ?? null,
            ]);
            $this->audit->record($admin, 'deposit.approved', $order, [
                'idempotent' => false,
                'kind' => $order->kind,
            ]);

            return [$order, false];
        });

        return response()->json([
            'message' => $idempotent ? 'Deposit sudah disetujui.' : 'Deposit disetujui dan saldo telah ditambahkan.',
            'order' => $order->toApiArray(),
        ]);
    }

    public function reject(Request $request, DepositOrder $depositOrder): JsonResponse
    {
        $admin = $this->adminUser($request);
        $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        [$order, $idempotent] = DB::transaction(function () use ($admin, $depositOrder, $validated): array {
            $order = DepositOrder::query()->lockForUpdate()->findOrFail($depositOrder->id);
            if ($order->status === DepositOrder::STATUS_REJECTED) {
                $this->audit->record($admin, 'deposit.rejection_replayed', $order, ['idempotent' => true]);

                return [$order, true];
            }
            if ($order->status !== DepositOrder::STATUS_PENDING) {
                throw ValidationException::withMessages(['order' => ['Deposit tidak dapat ditolak dari status ini.']]);
            }

            $order->update([
                'status' => DepositOrder::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $admin->id,
                'note' => $validated['note'] ?? 'Ditolak oleh admin.',
            ]);
            $this->audit->record($admin, 'deposit.rejected', $order, [
                'idempotent' => false,
                'kind' => $order->kind,
            ]);

            return [$order, false];
        });

        return response()->json([
            'message' => $idempotent ? 'Deposit sudah ditolak.' : 'Deposit ditolak tanpa perubahan saldo.',
            'order' => $order->toApiArray(),
        ]);
    }

    private function tokenSnapshot(string $code, int $conversion): array
    {
        $package = TokenPackage::query()->where('code', $code)->where('is_active', true)->first();
        if ($package === null) {
            throw ValidationException::withMessages(['package_code' => ['Paket token tidak tersedia.']]);
        }

        return [
            'package_code' => $package->code,
            'package_name' => $package->name,
            'base_tokens' => $package->base_tokens,
            'bonus_tokens' => $package->bonus_tokens,
            'total_tokens' => $package->total_tokens,
            'amount_idr' => $package->price_idr,
            'credit_microusd' => 0,
            'idr_per_usd' => $conversion,
        ];
    }

    private function walletSnapshot(int $amountIdr, int $conversion): array
    {
        return [
            'package_code' => null,
            'package_name' => null,
            'base_tokens' => 0,
            'bonus_tokens' => 0,
            'total_tokens' => 0,
            'amount_idr' => $amountIdr,
            'credit_microusd' => intdiv($amountIdr * 1_000_000, $conversion),
            'idr_per_usd' => $conversion,
        ];
    }

    private function paginatedOrders($orders, bool $includeUser = false): array
    {
        return [
            'orders' => $orders->getCollection()
                ->map(fn (DepositOrder $order): array => $order->toApiArray($includeUser))
                ->values(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ];
    }

    private function conversionRate(): int
    {
        return $this->positiveConfig('deposits.idr_per_usd');
    }

    private function minimumIdr(): int
    {
        return $this->positiveConfig('deposits.minimum_idr');
    }

    private function maximumIdr(): int
    {
        $maximum = $this->positiveConfig('deposits.maximum_idr');
        if ($maximum < $this->minimumIdr()) {
            throw new \RuntimeException('Deposit maximum must not be less than its minimum.');
        }

        return $maximum;
    }

    private function checkoutMinutes(): int
    {
        return $this->positiveConfig('deposits.checkout_minutes');
    }

    private function positiveConfig(string $key): int
    {
        $value = config($key);
        if (! is_int($value) || $value <= 0) {
            throw new \RuntimeException("{$key} must be a positive integer.");
        }

        return $value;
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        return $user;
    }

    private function adminUser(Request $request): User
    {
        $user = $this->authenticatedUser($request);
        abort_unless($user->isAdmin(), 403);

        return $user;
    }
}
