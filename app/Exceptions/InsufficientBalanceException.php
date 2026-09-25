<?php

namespace App\Exceptions;

use InvalidArgumentException;
use RuntimeException;

/**
 * A request needs more Saldo AI (the micro-USD wallet) or media tokens than the member holds.
 * Session JSON requests render it as HTTP 402 (bootstrap/app.php); API controllers map it to
 * their own error envelope (App\Http\Api\ApiErrorResponse).
 */
final class InsufficientBalanceException extends RuntimeException
{
    public const WALLET = 'wallet';

    public const TOKENS = 'tokens';

    /**
     * @param  string  $kind  self::WALLET (amounts in micro-USD) or self::TOKENS (amounts in tokens)
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $required,
        public readonly int $balance,
        ?string $message = null,
    ) {
        if (! in_array($kind, [self::WALLET, self::TOKENS], true)) {
            throw new InvalidArgumentException("Unknown balance kind [{$kind}].");
        }
        parent::__construct($message ?? ($kind === self::WALLET ? 'Saldo AI tidak cukup.' : 'Token tidak cukup.'));
    }

    public static function wallet(int $requiredMicrousd, int $balanceMicrousd): self
    {
        return new self(self::WALLET, $requiredMicrousd, $balanceMicrousd);
    }

    public static function tokens(int $requiredTokens, int $balanceTokens): self
    {
        return new self(self::TOKENS, $requiredTokens, $balanceTokens);
    }

    /** Body of the HTTP 402 session JSON response. */
    public function payload(): array
    {
        $payload = ['message' => $this->getMessage(), 'code' => 'insufficient_balance', 'kind' => $this->kind];

        return $this->kind === self::WALLET
            ? [...$payload, 'required_usd' => self::usd($this->required), 'balance_usd' => self::usd($this->balance)]
            : [...$payload, 'required_tokens' => $this->required, 'balance_tokens' => $this->balance];
    }

    /** Integer micro-USD as an exact six-decimal USD string, without float rounding. */
    public static function usd(int $microusd): string
    {
        $digits = str_pad(ltrim((string) $microusd, '-'), 7, '0', STR_PAD_LEFT);

        return ($microusd < 0 ? '-' : '').substr($digits, 0, -6).'.'.substr($digits, -6);
    }
}
