<?php

namespace App\Media\Exceptions;

use RuntimeException;

/** Raised when member-supplied media input violates the resolved capability. */
class CapabilityValidationException extends RuntimeException
{
    /** @param  array<string, string>  $errors  field key => human message */
    public function __construct(
        private readonly array $errors,
        string $message = 'The media input is invalid.',
    ) {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
