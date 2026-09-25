<?php

namespace App\Http\Api;

use App\Exceptions\ImageGenerationException;
use App\Exceptions\InsufficientBalanceException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class MediaApiResponse
{
    public static function error(Throwable $error, Request $request): ?JsonResponse
    {
        if (! $request->is('v1/media', 'v1/media/*', 'v1/images/*', 'v1/files')) {
            return null;
        }
        if ($error instanceof InsufficientBalanceException) {
            return ApiErrorResponse::insufficientBalance($error, 'openai');
        }
        if ($error instanceof ValidationException) {
            if (array_key_exists('tokens', $error->errors())) {
                return ApiErrorResponse::openAi('Token tidak cukup.', 'insufficient_quota', 'insufficient_tokens', 402);
            }

            return ApiErrorResponse::openAi((string) (collect($error->errors())->flatten()->first() ?? 'The request is invalid.'),
                'invalid_request_error', 'invalid_request', 400);
        }
        if ($error instanceof InvalidArgumentException) {
            return ApiErrorResponse::openAi('The uploaded file or media input is invalid.', 'invalid_request_error', 'invalid_request', 400);
        }
        $status = match (true) {
            $error instanceof ImageGenerationException => $error->responseStatus(),
            $error instanceof AuthorizationException => 403,
            $error instanceof ModelNotFoundException => 404,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 500,
        };
        $message = match ($status) {
            401 => 'A valid API key is required.',
            403 => 'This resource is not available to this key, or the signed link has expired.',
            404 => 'This media resource is unavailable.',
            413 => 'The file exceeds your upload or storage limit.',
            429 => 'Too many requests. Try again later.',
            default => $error instanceof ImageGenerationException && $status < 500
                ? $error->getMessage() : ($status >= 500 ? 'The media request could not be completed.' : 'The media request is invalid.'),
        };
        $type = match (true) {
            $status === 429 => 'rate_limit_error',
            $status === 403 => 'permission_error',
            $status >= 500 => 'server_error',
            default => 'invalid_request_error',
        };

        return ApiErrorResponse::openAi($message, $type, match ($status) {
            403 => 'permission_denied', 404 => 'not_found', 409 => 'conflict', 429 => 'rate_limit_exceeded',
            default => $status >= 500 ? 'media_error' : 'invalid_request',
        }, $status === 422 ? 400 : $status);
    }
}
