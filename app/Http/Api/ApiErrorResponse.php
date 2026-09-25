<?php

namespace App\Http\Api;

use App\Exceptions\InsufficientBalanceException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/** Error envelopes of the developer API: OpenAI-style routes and Anthropic-style `/v1/messages*`. */
final class ApiErrorResponse
{
    public const OPENAI = 'openai';

    public const ANTHROPIC = 'anthropic';

    public static function openAi(string $message, string $type, ?string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['message' => $message, 'type' => $type, 'code' => $code]], $status);
    }

    public static function anthropic(string $type, string $message, int $status): JsonResponse
    {
        return response()->json(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]], $status);
    }

    /** HTTP 402 for a request the member's Saldo AI or media tokens cannot cover. */
    public static function insufficientBalance(InsufficientBalanceException $exception, string $style): JsonResponse
    {
        $message = $exception->kind === InsufficientBalanceException::WALLET
            ? sprintf('Insufficient Saldo AI balance: this request requires $%s but the balance is $%s. Top up Saldo AI and try again.',
                InsufficientBalanceException::usd($exception->required), InsufficientBalanceException::usd($exception->balance))
            : sprintf('Insufficient media tokens: this request requires %d tokens but the balance is %d. Top up tokens and try again.',
                $exception->required, $exception->balance);

        return match ($style) {
            self::OPENAI => self::openAi($message, 'insufficient_quota',
                $exception->kind === InsufficientBalanceException::WALLET ? 'insufficient_balance' : 'insufficient_tokens', 402),
            self::ANTHROPIC => self::anthropic('billing_error', $message, 402),
            default => throw new InvalidArgumentException("Unknown API error style [{$style}]."),
        };
    }
}
