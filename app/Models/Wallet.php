<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Wallet extends Model
{
    protected $fillable = ['user_id', 'balance_microusd'];

    protected function casts(): array
    {
        return ['balance_microusd' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function balance(int $userId): int
    {
        // A read never creates the wallet row: that insert would take the wallet before the owner row.
        return (int) (static::query()->where('user_id', $userId)->value('balance_microusd') ?? 0);
    }

    public static function debit(
        int $userId,
        int $amountMicrousd,
        array $details,
    ): bool {
        if ($amountMicrousd <= 0) {
            return true;
        }

        return DB::transaction(function () use ($userId, $amountMicrousd, $details): bool {
            $wallet = static::lockAccount($userId);

            if ($wallet->balance_microusd < $amountMicrousd) {
                return false;
            }

            $wallet->balance_microusd -= $amountMicrousd;
            $wallet->save();
            $transaction = [
                'user_id' => $userId,
                'type' => $details['type'] ?? 'debit',
                'amount_microusd' => -$amountMicrousd,
                'balance_after_microusd' => $wallet->balance_microusd,
                ...$details,
            ];
            WalletTransaction::create($transaction);

            return true;
        });
    }

    public static function reserve(int $userId, int $amountMicrousd, string $referenceId, array $details): ?array
    {
        if ($amountMicrousd <= 0) {
            return ['reference_id' => $referenceId, 'amount_microusd' => 0];
        }

        $reserved = static::debit($userId, $amountMicrousd, [
            ...$details,
            'type' => 'reserve',
            'reference_id' => $referenceId,
        ]);

        return $reserved ? ['reference_id' => $referenceId, 'amount_microusd' => $amountMicrousd] : null;
    }

    public static function settle(int $userId, array $reservation, int $actualMicrousd, array $details): bool
    {
        return DB::transaction(function () use ($userId, $reservation, $actualMicrousd, $details): bool {
            static::lockAccount($userId);
            $referenceId = $reservation['reference_id'];
            if (WalletTransaction::where('reference_id', $referenceId)->where('type', 'settlement')->exists()) {
                return true;
            }

            $reserved = (int) $reservation['amount_microusd'];
            $difference = $reserved - $actualMicrousd;
            if ($difference > 0) {
                static::credit($userId, $difference, 'Usage reservation refund', $referenceId, 'settlement_refund');
            } elseif ($difference < 0 && ! static::debit($userId, abs($difference), [
                ...$details,
                'type' => 'settlement_adjustment',
                'reference_id' => $referenceId,
            ])) {
                return false;
            }

            WalletTransaction::create([
                'user_id' => $userId,
                'type' => 'settlement',
                'amount_microusd' => 0,
                'balance_after_microusd' => static::balance($userId),
                'reference_id' => $referenceId,
                ...$details,
            ]);

            return true;
        });
    }

    public static function release(int $userId, ?array $reservation, string $description): void
    {
        if (! $reservation || $reservation['amount_microusd'] <= 0) {
            return;
        }

        DB::transaction(function () use ($userId, $reservation, $description): void {
            static::lockAccount($userId);
            $referenceId = $reservation['reference_id'];
            if (WalletTransaction::where('reference_id', $referenceId)->whereIn('type', ['release', 'settlement'])->exists()) {
                return;
            }

            static::credit($userId, $reservation['amount_microusd'], $description, $referenceId, 'release');
        });
    }

    public static function credit(int $userId, int $amountMicrousd, string $description, ?string $referenceId = null, string $type = 'credit'): int
    {
        return DB::transaction(function () use ($userId, $amountMicrousd, $description, $referenceId, $type): int {
            $wallet = static::lockAccount($userId);
            if ($referenceId !== null) {
                $existing = WalletTransaction::query()->where('user_id', $userId)
                    ->where('reference_id', $referenceId)->where('type', $type)->first();
                if ($existing !== null) {
                    if ((int) $existing->amount_microusd !== $amountMicrousd) {
                        throw new \InvalidArgumentException('Wallet reference has already been used for a different amount.');
                    }

                    return (int) $wallet->balance_microusd;
                }
            }
            $wallet->balance_microusd += $amountMicrousd;
            $wallet->save();
            WalletTransaction::create([
                'user_id' => $userId,
                'type' => $type,
                'amount_microusd' => $amountMicrousd,
                'balance_after_microusd' => $wallet->balance_microusd,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);

            return $wallet->balance_microusd;
        });
    }

    /**
     * Every account write locks the owner user row, then the wallet row (then user_tokens when the same
     * transaction also moves tokens). Membership approval and chat admission lock the owner first, so a
     * wallet-first entry point deadlocks against them. The owner lock also serializes first-wallet creation.
     */
    private static function lockAccount(int $userId): static
    {
        User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

        return static::query()->lockForUpdate()->firstOrCreate(['user_id' => $userId], ['balance_microusd' => 0]);
    }
}
