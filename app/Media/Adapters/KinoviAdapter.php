<?php

namespace App\Media\Adapters;

use App\Exceptions\AiProviderRequestRejected;
use App\Exceptions\AiProxyException;
use App\Media\AdapterSupport;
use App\Media\Contracts\MediaProviderAdapter;
use App\Media\Contracts\StagesProviderReferences;
use App\Media\MediaCapability;
use App\Media\MediaReferenceStager;
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
final class KinoviAdapter implements MediaProviderAdapter, StagesProviderReferences
{
    public function __construct(private readonly AiProviderTransport $transport, private readonly MediaReferenceStager $references) {}

    public function support(): AdapterSupport
    {
        return new AdapterSupport(async: true, polling: true, webhook: false, cancel: false);
    }

    public function buildRequest(MediaCapability $capability, array $inputs, string $upstreamModel): array
    {
        $request = [
            'model' => $upstreamModel,
            'prompt' => $inputs['inputs']['prompt'] ?? '',
            'size' => $inputs['params']['size'] ?? '1024x1024',
        ];
        // Keep only owned IDs until submission can stream them through Kinovi's upload lifecycle.
        $refs = $inputs['inputs']['reference_image'] ?? null;
        if ($refs !== null) {
            $request['_reference_assets'] = is_array($refs) ? array_values($refs) : [$refs];
            $request['_owner_id'] = isset($inputs['owner_id']) ? (int) $inputs['owner_id'] : null;
        }

        return $request;
    }

    /** Streams each owned reference through Kinovi's upload lifecycle; the request then carries only stored URLs. */
    public function stageReferences(AiProviderProfile $provider, array $request): array
    {
        $urls = [];
        foreach ($request['_reference_assets'] ?? [] as $id) {
            $urls[] = $this->references->stage($provider, $id, $request['_owner_id'] ?? null);
        }
        if ($urls !== []) {
            $request['uploadedUrls'] = $urls;
        }
        unset($request['_reference_assets'], $request['_owner_id']);

        return $request;
    }

    public function submit(AiProviderProfile $provider, array $request): SubmitResult
    {
        try {
            $request = $this->stageReferences($provider, $request);
        } catch (Throwable) {
            return SubmitResult::rejected('The reference file could not be staged. No generation was submitted.');
        }
        try {
            $result = $this->transport->submitImage($provider, $request, (string) ($request['model'] ?? ''));
        } catch (AiProxyException $e) {
            return $e instanceof AiProviderRequestRejected || $e->responseStatus() === 422
                ? SubmitResult::rejected($e->getMessage())
                : SubmitResult::uncertain();
        }

        $taskId = $result['id'] ?? null;

        return is_string($taskId) && $taskId !== '' ? SubmitResult::accepted($taskId) : SubmitResult::uncertain();
    }

    public function pollStatus(AiProviderProfile $provider, string $taskId, array $context = []): StatusResult
    {
        $result = $this->transport->imageStatus($provider, $taskId, '');

        return match ($result['status'] ?? null) {
            'completed' => StatusResult::completed(is_array($result['result_urls'] ?? null) ? $result['result_urls'] : []),
            'failed' => StatusResult::failed(),
            default => StatusResult::processing(),
        };
    }

    public function cancel(AiProviderProfile $provider, string $taskId, array $context = []): array
    {
        return ['requested' => false, 'confirmed' => false];
    }

    public function normalizeError(Throwable $error): array
    {
        $status = $error instanceof AiProxyException ? $error->responseStatus() : 500;

        return [
            'public_code' => 'kinovi_error',
            'public_message' => $error instanceof AiProxyException ? $error->getMessage() : 'Media generation failed.',
            'recoverable' => in_array($status, [503, 504], true),
            'internal' => substr($error::class.': '.$error->getMessage(), 0, 500),
        ];
    }
}
