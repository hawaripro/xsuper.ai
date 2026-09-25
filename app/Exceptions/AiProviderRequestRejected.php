<?php

namespace App\Exceptions;

/** An upstream HTTP rejection, unlike a timeout or an unusable successful response. */
final class AiProviderRequestRejected extends AiProxyException
{
    /** $providerCode is the provider's own sanitized error code (e.g. Runware "insufficientCredits"), for logs and routing only. */
    public function __construct(string $message, int $responseStatus, public readonly int $upstreamStatus, public readonly ?string $providerCode = null)
    {
        parent::__construct($message, $responseStatus);
    }
}
