<?php

namespace App\Media\Adapters;

use App\Exceptions\AiProviderRequestRejected;
use App\Exceptions\AiProxyException;
use App\Media\AdapterSupport;
use App\Media\Contracts\MediaProviderAdapter;
use App\Media\MediaCapability;
use App\Media\StatusResult;
use App\Media\SubmitResult;
use App\Models\AiProviderProfile;
use App\Services\MediaModelConfig;
use App\Services\AiProviderTransport;
use InvalidArgumentException;
use Throwable;

/** Native image generations only: wraps the existing real OpenAI-compatible transport. */
final class OpenAiAdapter implements MediaProviderAdapter
{
    public function __construct(private readonly AiProviderTransport $transport) {}

    public function support(): AdapterSupport
    {
        return new AdapterSupport(async: false, polling: false, webhook: false, cancel: false);
    }

    public function buildRequest(MediaCapability $capability, array $inputs, string $upstreamModel): array
    {
        if ($capability->contractVersion !== 1 || $capability->providerBindings !== []
            || $capability->outputKind->value !== 'image' || $capability->operation->value !== 'text_to_image') {
            throw new InvalidArgumentException('This native image operation is not supported.');
        }
        $request = [
            'model' => $upstreamModel, 'prompt' => $inputs['inputs']['prompt'] ?? '',
            '_path' => MediaModelConfig::path($inputs['generation_config']['image_path'] ?? 'images/generations'),
        ];
        if (isset($inputs['params']['size'])) {
            $request['size'] = $inputs['params']['size'];
        }

        return $request;
    }

    public function submit(AiProviderProfile $provider, array $request): SubmitResult
    {
        try {
            $path = $request['_path'] ?? 'images/generations';
            unset($request['_path']);
            $result = $this->transport->imageGeneration($provider, $request, $path);
            if ($result['data'] === []) {
                return SubmitResult::uncertain();
            }
            // Native GPT image responses use b64_json, not a download URL. Keep the actual items.
            return SubmitResult::immediate(array_values(array_filter(array_column($result['data'], 'url'), 'is_string')), $result);
        } catch (AiProxyException $error) {
            return $error instanceof AiProviderRequestRejected || $error->responseStatus() === 422
                ? SubmitResult::rejected($error->getMessage()) : SubmitResult::uncertain();
        }
    }

    public function pollStatus(AiProviderProfile $provider, string $taskId, array $context = []): StatusResult
    {
        throw new InvalidArgumentException('Native synchronous images do not expose polling tasks.');
    }

    public function cancel(AiProviderProfile $provider, string $taskId, array $context = []): array
    {
        return ['requested' => false, 'confirmed' => false];
    }

    public function normalizeError(Throwable $error): array
    {
        return ['public_code' => 'native_image_error', 'public_message' => 'Image generation could not be completed.',
            'recoverable' => false, 'internal' => $error::class];
    }
}
