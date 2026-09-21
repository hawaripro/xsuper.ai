<?php

namespace App\Media\Contracts;

use App\Media\AdapterSupport;
use App\Media\MediaCapability;
use App\Media\StatusResult;
use App\Media\SubmitResult;
use App\Models\AiProviderProfile;
use Throwable;

/**
 * The integration boundary for one provider. Implementations wrap existing protocol
 * code (FalProtocol/KinoviProtocol/…) after its behavior is pinned by tests. The shared
 * flow owns authorization, resolver, validation, job/asset coordination, and billing —
 * an adapter NEVER mutates a user's balance. `support()` declares real capabilities;
 * never expose a fake poll/cancel a provider does not offer.
 */
interface MediaProviderAdapter
{
    public function support(): AdapterSupport;

    /**
     * Map validated, normalized inputs + the resolved capability into an internal
     * provider request. `$upstreamModel` is the provider-side model id (kept server-only,
     * never leaked to members). Never forwards raw member payload.
     *
     * @param  array{inputs: array<string, mixed>, params: array<string, mixed>}  $inputs
     * @return array<string, mixed>
     */
    public function buildRequest(MediaCapability $capability, array $inputs, string $upstreamModel): array;

    /** @param  array<string, mixed>  $request */
    public function submit(AiProviderProfile $provider, array $request): SubmitResult;

    public function pollStatus(AiProviderProfile $provider, string $taskId): StatusResult;

    /**
     * Turn a provider/transport error into a safe public shape plus a sanitized
     * internal note (secrets/credentials/signed URLs removed).
     *
     * @return array{public_code: string, public_message: string, recoverable: bool, internal: ?string}
     */
    public function normalizeError(Throwable $error): array;
}
