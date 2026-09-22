<?php

namespace App\Media\Adapters;

use App\Exceptions\AiProxyException;
use App\Media\AdapterSupport;
use App\Media\AssetService;
use App\Media\Contracts\MediaProviderAdapter;
use App\Media\FalCapabilityImporter;
use App\Media\MediaCapability;
use App\Media\StatusResult;
use App\Media\SubmitResult;
use App\Models\AiProviderProfile;
use App\Models\MediaAsset;
use App\Services\AiProviderTransport;
use InvalidArgumentException;
use Throwable;

/** Data-driven image subset. Routing and bindings come only from the job's reviewed revision. */
final class FalAdapter implements MediaProviderAdapter
{
    public function __construct(private readonly AiProviderTransport $transport, private readonly AssetService $assets) {}

    public function support(): AdapterSupport
    {
        return new AdapterSupport(async: false, polling: false, webhook: false, cancel: false);
    }

    public function buildRequest(MediaCapability $capability, array $inputs, string $upstreamModel): array
    {
        $bindings = $capability->providerBindings;
        if (($bindings['adapter'] ?? null) !== 'fal_image_v1' || ($bindings['endpoint'] ?? null) !== $upstreamModel
            || ! FalCapabilityImporter::validEndpoint($upstreamModel) || $capability->outputKind->value !== 'image') {
            throw new InvalidArgumentException('The published image adapter binding is invalid.');
        }
        $payload = $bindings['constants'] ?? [];
        foreach ($bindings['inputs'] ?? [] as $key => $providerKey) {
            if (! array_key_exists($key, $inputs['inputs'] ?? [])) {
                continue;
            }
            $value = $inputs['inputs'][$key];
            $input = $capability->inputByKey($key);
            if ($input?->type === 'asset') {
                $ids = is_array($value) ? $value : [$value];
                if (count($ids) !== 1) {
                    throw new InvalidArgumentException('This image integration accepts one reference.');
                }
                $asset = MediaAsset::findOrFail($ids[0]);
                $url = $this->assets->signedUrl($asset);
                $value = ($bindings['reference_array'] ?? false) ? [$url] : $url;
            }
            $payload[$providerKey] = $value;
        }
        foreach ($bindings['params'] ?? [] as $key => $providerKey) {
            if (array_key_exists($key, $inputs['params'] ?? [])) {
                $payload[$providerKey] = $inputs['params'][$key];
            }
        }

        return ['endpoint' => $upstreamModel, 'payload' => $payload];
    }

    public function submit(AiProviderProfile $provider, array $request): SubmitResult
    {
        try {
            $result = $this->transport->runCatalogImage($provider, $request['endpoint'], $request['payload']);

            return SubmitResult::immediate(array_column($result['data'], 'url'));
        } catch (AiProxyException $exception) {
            return in_array($exception->responseStatus(), [503, 504], true)
                ? SubmitResult::uncertain() : SubmitResult::rejected($exception->getMessage());
        }
    }

    public function pollStatus(AiProviderProfile $provider, string $taskId): StatusResult
    {
        throw new InvalidArgumentException('Synchronous catalog images do not have polling tasks.');
    }

    public function normalizeError(Throwable $error): array
    {
        return [
            'public_code' => 'fal_image_error', 'public_message' => 'Image generation could not be completed.',
            'recoverable' => $error instanceof AiProxyException && in_array($error->responseStatus(), [503, 504], true),
            'internal' => $error::class,
        ];
    }
}
