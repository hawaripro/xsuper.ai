<?php

namespace App\Media\Adapters;

use App\Exceptions\AiProxyException;
use App\Media\AdapterSupport;
use App\Media\Contracts\MediaProviderAdapter;
use App\Media\MediaCapability;
use App\Media\StatusResult;
use App\Media\SubmitResult;
use App\Models\AiProviderProfile;
use App\Media\AssetService;
use App\Models\MediaAsset;
use App\Services\AiProviderTransport;
use Throwable;

/**
 * Kinovi image adapter. Wraps the already-verified kinovi branches of AiProviderTransport
 * (createTask/recordInfo) rather than re-implementing the protocol. Async + polling only;
 * Kinovi offers no member-facing cancel or trustworthy webhook, so those are not declared.
 */
final class KinoviAdapter implements MediaProviderAdapter
{
    public function __construct(private readonly AiProviderTransport $transport, private readonly AssetService $assets) {}

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
        // Reference assets are minted into fresh, short-lived, cookie-independent provider-fetch
        // grants at request time — a signed URL is never persisted on the job.
        $refs = $inputs['inputs']['reference_image'] ?? null;
        if ($refs !== null) {
            $urls = [];
            foreach (is_array($refs) ? $refs : [$refs] as $assetId) {
                $asset = MediaAsset::find($assetId);
                if ($asset !== null) {
                    $urls[] = $this->assets->signedUrl($asset);
                }
            }
            if ($urls !== []) {
                $request['uploadedUrls'] = $urls;
            }
        }

        return $request;
    }

    public function submit(AiProviderProfile $provider, array $request): SubmitResult
    {
        try {
            $result = $this->transport->submitImage($provider, $request, (string) ($request['model'] ?? ''));
        } catch (AiProxyException $e) {
            // send() maps upstream 429/5xx/connection to 503 (unavailable => uncertain) and
            // 4xx rejections to 502 (definitive). Only 503/504 leave acceptance unknown.
            return in_array($e->responseStatus(), [503, 504], true)
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
            'recoverable' => in_array($status, [503, 504], true),
            'internal' => substr($error::class.': '.$error->getMessage(), 0, 500),
        ];
    }
}
