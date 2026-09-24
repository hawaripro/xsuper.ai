<?php

namespace App\Exceptions;

/** An upstream HTTP rejection, unlike a timeout or an unusable successful response. */
final class AiProviderRequestRejected extends AiProxyException
{
    public function __construct(string $message, int $responseStatus, public readonly int $upstreamStatus)
    {
        parent::__construct($message, $responseStatus);
    }
}
