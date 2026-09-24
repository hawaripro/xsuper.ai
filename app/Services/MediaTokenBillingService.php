<?php

namespace App\Services;

use App\Models\TokenReservation;
use App\Models\User;
use App\Models\UserToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MediaTokenBillingService
{
    private const MAX_AMOUNT = 2_147_483_647;

    private const LEDGER_REFERENCE_PREFIX = 'media-token:';

    public function reserve(
        User $user,
        string $service,
        string $model,
        int $quantity,
        string $reference,
        int $unitCost,
    ): array {
        $this->validateInput($service, $model, $quantity, $reference);

        return DB::transaction(function () use ($user, $service, $model, $quantity, $reference, $unitCost): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = TokenReservation::query()
                ->where('user_id', $user->id)
                ->where('reference_id', $reference)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->service !== $service || $existing->model !== $model || $existing->quantity !== $quantity) {
                    throw ValidationException::withMessages([
                        'reference' => ['The token reservation reference is already assigned to another request.'],
                    ]);
                }

                return $existing->payload();
            }

            $amount = $this->checkedAmount($quantity, $unitCost);

            if (! UserToken::deduct(
                (int) $user->id,
                $amount,
                "Reserve {$quantity}x {$service} ({$model})",
                $this->transactionReference($reference, 'reserve'),
            )) {
                throw ValidationException::withMessages([
                    'tokens' => ["Token tidak cukup. Butuh {$amount} token."],
                ]);
            }

            return TokenReservation::create([
                'user_id' => $user->id,
                'reference_id' => $reference,
                'service' => $service,
                'model' => $model,
                'quantity' => $quantity,
                'unit_tokens' => $unitCost,
                'amount_tokens' => $amount,
                'billing_mode' => 'tokens',
                'status' => TokenReservation::STATUS_RESERVED,
            ])->payload();
        });
    }

    public function settle(int $userId, array $reservation, array $details = []): void
    {
        $reference = $this->referenceFrom($reservation);

        DB::transaction(function () use ($userId, $reference, $details): void {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $record = TokenReservation::query()
                ->where('user_id', $userId)
                ->where('reference_id', $reference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->status !== TokenReservation::STATUS_RESERVED) {
                return;
            }

            $record->update([
                'status' => TokenReservation::STATUS_SETTLED,
                'settlement_details' => $this->safeDetails($details),
                'settled_at' => now(),
            ]);
        });
    }

    public function release(int $userId, array $reservation, string $description): void
    {
        $reference = $this->referenceFrom($reservation);

        DB::transaction(function () use ($userId, $reference, $description): void {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $record = TokenReservation::query()
                ->where('user_id', $userId)
                ->where('reference_id', $reference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->status !== TokenReservation::STATUS_RESERVED) {
                return;
            }

            if ($record->billing_mode === 'tokens' && $record->amount_tokens > 0) {
                UserToken::refund(
                    $userId,
                    $record->amount_tokens,
                    mb_substr($description, 0, 255),
                    $this->transactionReference($record->reference_id, 'release'),
                );
            }

            $record->update([
                'status' => TokenReservation::STATUS_RELEASED,
                'released_at' => now(),
                'release_description' => mb_substr($description, 0, 255),
            ]);
        });
    }

    private function validateInput(string $service, string $model, int $quantity, string $reference): void
    {
        if (! in_array($service, ['image', 'video', 'audio', 'model3d', 'media'], true)) {
            throw new InvalidArgumentException('This media billing service is not supported.');
        }
        if (trim($model) === '' || mb_strlen($model) > 160) {
            throw new InvalidArgumentException('Media model must contain at most 160 characters.');
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Media quantity must be positive.');
        }
        if (in_array($service, ['audio', 'model3d', 'media'], true) && $quantity !== 1) {
            throw new InvalidArgumentException('Audio, 3D and schema media reservations must contain exactly one invocation.');
        }
        if (trim($reference) === '' || mb_strlen($reference) > 160) {
            throw new InvalidArgumentException('Reservation reference must contain at most 160 characters.');
        }
    }

    private function checkedAmount(int $quantity, int $unitCost): int
    {
        if ($unitCost <= 0) {
            throw new InvalidArgumentException('Media unit cost must be positive.');
        }

        if ($quantity > intdiv(self::MAX_AMOUNT, $unitCost)) {
            throw new InvalidArgumentException('Token reservation exceeds the supported limit.');
        }

        return $quantity * $unitCost;
    }

    private function referenceFrom(array $reservation): string
    {
        $reference = $reservation['reference_id'] ?? null;
        if (! is_string($reference) || trim($reference) === '' || mb_strlen($reference) > 160) {
            throw new InvalidArgumentException('Reservation reference is required.');
        }

        return $reference;
    }

    private function transactionReference(string $reference, string $operation): string
    {
        $prefix = self::LEDGER_REFERENCE_PREFIX.$operation.':';
        $digest = hash('sha256', $reference);

        return $prefix.mb_substr($reference, 0, 255 - strlen($prefix) - strlen($digest) - 1).':'.$digest;
    }

    private function safeDetails(array $details): array
    {
        $encoded = json_encode($details, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 16_384) {
            throw new InvalidArgumentException('Settlement details exceed the supported limit.');
        }

        return $details;
    }
}
