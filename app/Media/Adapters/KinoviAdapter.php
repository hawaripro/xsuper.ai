<?php

namespace App\Media\Adapters;

use App\Exceptions\AiProxyException;
use App\Media\AdapterSupport;
use App\Media\Contracts\MediaProviderAdapter;
use App\Media\MediaCapability;
use App\Media\StatusResult;
use App\Media\SubmitResult;
use App\Models\AiProviderProfile;
use App\Services\AiProviderTransport;
use Throwable;

/**
 * Kinovi image adapter. Wraps the already-verified kinovi branches of AiProviderTransport
 * (createTask/recordInfo) rather than re-implementing the protocol. Async + polling only;
 * Kinovi offers no member-facing cancel or trustworthy webhook, so those are not declared.
 */
final class KinoviAdapter implements MediaProviderAdapter
{
    public function __construct(private readonly AiProviderTransport $transport) {}

    public function support(): AdapterSupport
    {
        return new AdapterSupport(async: true, polling: true, webhook: false, cancel: false);
    }

    public function buildRequest(MediaCapability $capability, array $inputs, string $upstreamModel): array
    {
        return [
            'model' => $upstreamModel,
            'prompt' => $inputs['inputs']['prompt'] ?? '',
            'size' => $inputs['params']['size'] ?? '1024x1024',
        ];
    }

    public function submit(AiProviderProfile $provider, array $request): SubmitResult
    {
        try {
            $result = $this->transport->submitImage($provider, $request, (string) ($request['model'] ?? ''));
        } catch (AiProxyException $e) {
            // Transport/provider transient failures leave acceptance unknown -> reconcile,
            // never treat as a definitive rejection that would refund/resubmit.
            return in_array($e->responseStatus(), [502, 503, 504], true)
                ? SubmitResult::uncertain()
                : SubmitResult::rejected($e->getMessage());
        }

        $taskId = $result['id'] ?? null;

        return is_string($taskId) && $taskId !== '' ? SubmitResult::accepted($taskId) : SubmitResult::uncertain();
    }

    public function pollStatus(AiProviderProfile $provider, string $taskId): StatusResult
    {
        $result = $this->transport->imageStatus($provider, $taskId, '');

        return match ($result['status'] ?? null) {
            'completed' => StatusResult::completed(is_array($result['result_urls'] ?? null) ? $result['result_urls'] : []),
            'failed' => StatusResult::failed(),
            default => StatusResult::processing(),
        };
    }

    public function normalizeError(Throwable $error): array
    {
        $status = $error instanceof AiProxyException ? $error->responseStatus() : 500;

        return [
            'public_code' => 'kinovi_error',
            'public_message' => $error instanceof AiProxyException ? $error->getMessage() : 'Media generation failed.',
            'recoverable' => in_array($status, [502, 503, 504], true),
            'internal' => substr($error::class.': '.$error->getMessage(), 0, 500),
        ];
    }
}
