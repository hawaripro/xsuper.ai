<?php

namespace App\Http\Api;

use App\Exceptions\InsufficientBalanceException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class DeveloperApiErrors
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('v1/*')) { return null; }
        $anthropic = $request->is('v1/messages*');
        if ($exception instanceof InsufficientBalanceException) {
            return ApiErrorResponse::insufficientBalance($exception, $anthropic ? ApiErrorResponse::ANTHROPIC : ApiErrorResponse::OPENAI);
        }
        if ($exception instanceof ValidationException) {
            $status = 400;
            $type = 'invalid_request_error';
            $message = 'Invalid request: '.collect($exception->errors())->flatten()->first();
        } else {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof AuthorizationException ? 403 : 500);
            [$type, $message] = match ($status) {
                400, 422 => ['invalid_request_error', 'Invalid request.'],
                401 => ['authentication_error', 'Invalid API key.'],
                403 => ['permission_error', 'Access denied.'],
                404 => ['not_found_error', 'Resource not found.'],
                405 => ['invalid_request_error', 'Method not allowed.'],
                413 => ['request_too_large', 'Request too large.'],
                429 => ['rate_limit_error', 'Rate limit exceeded.'],
                default => ['api_error', 'The request could not be completed. Please try again later.'],
            };
        }
        return $anthropic ? ApiErrorResponse::anthropic($type, $message, $status) : ApiErrorResponse::openAi($message, $type, $type, $status);
    }
}
