<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserToken extends Model
{
    protected $fillable = ['user_id', 'balance'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function getBalance(int $userId): int
    {
        return static::firstOrCreate(['user_id' => $userId], ['balance' => 0])->balance;
    }

    public static function deduct(int $userId, int $amount, string $description = '', ?string $refId = null): bool
    {
        $token = static::firstOrCreate(['user_id' => $userId], ['balance' => 0]);
        if ($token->balance < $amount) return false;

        $token->balance -= $amount;
        $token->save();

        TokenTransaction::create([
            'user_id' => $userId,
            'type' => 'deduct',
            'amount' => $amount,
            'balance_after' => $token->balance,
            'description' => $description,
            'reference_id' => $refId,
        ]);

        return true;
    }

    public static function topup(int $userId, int $amount, string $description = '', ?string $refId = null): void
    {
        $token = static::firstOrCreate(['user_id' => $userId], ['balance' => 0]);
        $token->balance += $amount;
        $token->save();

        TokenTransaction::create([
            'user_id' => $userId,
            'type' => 'topup',
            'amount' => $amount,
            'balance_after' => $token->balance,
            'description' => $description,
            'reference_id' => $refId,
        ]);
    }

    public static function refund(int $userId, int $amount, string $description = '', ?string $refId = null): void
    {
        $token = static::firstOrCreate(['user_id' => $userId], ['balance' => 0]);
        $token->balance += $amount;
        $token->save();

        TokenTransaction::create([
            'user_id' => $userId,
            'type' => 'refund',
            'amount' => $amount,
            'balance_after' => $token->balance,
            'description' => $description,
            'reference_id' => $refId,
        ]);
    }
}
