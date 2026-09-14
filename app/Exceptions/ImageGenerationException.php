<?php

namespace App\Exceptions;

use App\Models\ImageJob;
use RuntimeException;
use Throwable;

class ImageGenerationException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $responseStatus,
        private readonly ?ImageJob $job = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function responseStatus(): int
    {
        return $this->responseStatus;
    }

    public function job(): ?ImageJob
    {
        return $this->job;
    }
}
