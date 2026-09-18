<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UserToken extends Model
{
    protected $fillable = ['user_id', 'balance'];

    private const MAX_BALANCE = 2_147_483_647;

    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function getBalance(int $userId): int
    {
        return (int) (static::query()->where('user_id', $userId)->value('balance') ?? 0);
    }

    public static function deduct(int $userId, int $amount, string $description = '', ?string $refId = null): bool
    {
        return static::record($userId, 'deduct', $amount, $description, $refId);
    }

    public static function topup(int $userId, int $amount, string $description = '', ?string $refId = null): void
    {
        static::record($userId, 'topup', $amount, $description, $refId);
    }

    public static function refund(int $userId, int $amount, string $description = '', ?string $refId = null): void
    {
        static::record($userId, 'refund', $amount, $description, $refId);
    }

    private static function record(int $userId, string $type, int $amount, string $description, ?string $refId): bool
    {
        if ($amount <= 0 || $amount > self::MAX_BALANCE) {
            throw new InvalidArgumentException('Token amount must be a positive supported integer.');
        }
        if ($refId !== null && (trim($refId) === '' || mb_strlen($refId) > 255)) {
            throw new InvalidArgumentException('Token reference must contain at most 255 characters.');
        }

        return DB::transaction(function () use ($userId, $type, $amount, $description, $refId): bool {
            // The parent lock also serializes the first balance creation for this owner.
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            static::query()->firstOrCreate(['user_id' => $userId], ['balance' => 0]);
            $token = static::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if ($refId !== null) {
                $existing = TokenTransaction::query()
                    ->where('user_id', $userId)
                    ->where('type', $type)
                    ->where('reference_id', $refId)
                    ->first();
                if ($existing !== null) {
                    if ($existing->amount !== $amount) {
                        throw new InvalidArgumentException('Token reference has already been used for a different amount.');
                    }

                    return true;
                }
            }

            if ($type === 'deduct' && $token->balance < $amount) {
                return false;
            }
            if ($type !== 'deduct' && $token->balance > self::MAX_BALANCE - $amount) {
                throw new InvalidArgumentException('Token balance exceeds the supported limit.');
            }

            $newBalance = $token->balance + ($type === 'deduct' ? -$amount : $amount);
            try {
                TokenTransaction::create([
                    'user_id' => $userId,
                    'type' => $type,
                    'amount' => $amount,
                    'balance_after' => $newBalance,
                    'description' => mb_substr($description, 0, 255),
                    'reference_id' => $refId,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($refId === null || ! TokenTransaction::query()
                    ->where('user_id', $userId)
                    ->where('type', $type)
                    ->where('reference_id', $refId)
                    ->where('amount', $amount)
                    ->exists()) {
                    throw $exception;
                }

                return true;
            }

            $token->balance = $newBalance;
            $token->save();

            return true;
        });
    }
}
