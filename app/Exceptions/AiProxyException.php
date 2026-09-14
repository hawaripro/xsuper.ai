<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class AiProxyException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $responseStatus,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function responseStatus(): int
    {
        return $this->responseStatus;
    }
}
