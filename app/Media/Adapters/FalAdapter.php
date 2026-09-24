<?php

namespace App\Media\Adapters;

use App\Exceptions\AiProviderRequestRejected;
use App\Exceptions\AiProxyException;
use App\Media\AdapterSupport;
use App\Media\Contracts\MediaProviderAdapter;
use App\Media\Contracts\StagesProviderReferences;
use App\Media\FalCapabilityImporter;
use App\Media\MediaCapability;
use App\Media\MediaJsonSchema;
use App\Media\MediaReferenceStager;
use App\Media\StatusResult;
use App\Media\SubmitResult;
use App\Models\AiProviderProfile;
use App\Services\AiProviderTransport;
use App\Services\FalProtocol;
use InvalidArgumentException;
use Throwable;

/** Executes immutable source bindings, with native/v1 image execution kept intact. */
final class FalAdapter implements MediaProviderAdapter, StagesProviderReferences
{
    public function __construct(private readonly AiProviderTransport $transport, private readonly MediaReferenceStager $references) {}

    public function support(): AdapterSupport
    {
        return new AdapterSupport(async: true, polling: true, webhook: false, cancel: true);
    }

    public function buildRequest(MediaCapability $capability, array $inputs, string $upstreamModel): array
    {
        $bindings = $capability->providerBindings;
        $ownerId = isset($inputs['owner_id']) ? (int) $inputs['owner_id'] : null;
        if ($capability->contractVersion === 2) {
            $transport = $bindings['transport'] ?? 'queue';
            if (($bindings['adapter'] ?? null) !== 'fal_schema_v2' || ($bindings['endpoint'] ?? null) !== $upstreamModel
                || ! FalCapabilityImporter::validEndpoint($upstreamModel)
                || ! in_array($transport, ['queue', 'direct'], true)
                || ($transport === 'queue' && ($bindings['queue_root'] ?? null) !== AiProviderTransport::falQueueRoot($upstreamModel))) {
                throw new InvalidArgumentException('The published provider binding is invalid.');
            }
            $payload = array_replace($inputs['inputs'] ?? [], $bindings['constants'] ?? []);

            return [
                'mode' => $transport, 'endpoint' => $upstreamModel, 'bindings' => $bindings,
                'payload' => $payload, 'owner_id' => $ownerId,
                'assets' => $inputs['asset_paths'] ?? MediaJsonSchema::assetReferences($capability->inputSchema, $payload),
            ];
        }
        if ($bindings === [] && $capability->outputKind->value === 'image'
            && (FalProtocol::MEDIA_MODELS[$upstreamModel] ?? null) === 'image') {
            return [
                'mode' => 'native', 'endpoint' => $upstreamModel,
                'payload' => ['model' => $upstreamModel, ...$inputs['inputs'], ...$inputs['params']],
                'assets' => [], 'owner_id' => $ownerId,
            ];
        }
        if (($bindings['adapter'] ?? null) !== 'fal_image_v1' || ($bindings['endpoint'] ?? null) !== $upstreamModel
            || ! FalCapabilityImporter::validEndpoint($upstreamModel) || $capability->outputKind->value !== 'image') {
            throw new InvalidArgumentException('The published image adapter binding is invalid.');
        }
        $payload = $bindings['constants'] ?? [];
        $assets = [];
        foreach ($bindings['inputs'] ?? [] as $key => $providerKey) {
            if (! array_key_exists($key, $inputs['inputs'] ?? [])) {
                continue;
            }
            $value = $inputs['inputs'][$key];
            if ($capability->inputByKey($key)?->type === 'asset') {
                $ids = is_array($value) ? $value : [$value];
                if (count($ids) !== 1) {
                    throw new InvalidArgumentException('This historical image integration accepts one reference.');
                }
                $value = ($bindings['reference_array'] ?? false) ? array_values($ids) : reset($ids);
                $assets[] = ['path' => ($bindings['reference_array'] ?? false) ? [$providerKey, 0] : [$providerKey], 'asset_id' => reset($ids)];
            }
            $payload[$providerKey] = $value;
        }
        foreach ($bindings['params'] ?? [] as $key => $providerKey) {
            if (array_key_exists($key, $inputs['params'] ?? [])) {
                $payload[$providerKey] = $inputs['params'][$key];
            }
        }

        return ['mode' => 'catalog_image', 'endpoint' => $upstreamModel, 'payload' => $payload, 'assets' => $assets, 'owner_id' => $ownerId];
    }

    /** Uploads each owned reference once and writes its provider URL into the payload at its schema path. */
    public function stageReferences(AiProviderProfile $provider, array $request): array
    {
        $payload = $request['payload'];
        $staged = [];
        foreach ($request['assets'] ?? [] as $reference) {
            $id = $reference['asset_id'];
            $url = $staged[$id] ??= $this->references->stage($provider, $id, $request['owner_id'] ?? null);
            $target =& $payload;
            foreach ($reference['path'] as $segment) {
                if (! is_array($target) || ! array_key_exists($segment, $target)) {
                    throw new InvalidArgumentException('The reference association is invalid.');
                }
                $target =& $target[$segment];
            }
            $target = $url;
            unset($target);
        }

        return [...$request, 'payload' => $payload, 'assets' => []];
    }

    public function submit(AiProviderProfile $provider, array $request): SubmitResult
    {
        try {
            $request = $this->stageReferences($provider, $request);
        } catch (Throwable) {
            // Staging has not submitted a generation, so it can safely reject admission.
            return SubmitResult::rejected('The reference file could not be staged. No generation was submitted.');
        }
        $payload = $request['payload'];
        if (in_array($request['mode'] ?? null, ['queue', 'direct'], true)) {
            // Staged file URLs are in place; restore schema-declared (including empty) JSON objects.
            $payload = MediaJsonSchema::objectMembers($request['bindings']['request_schema'] ?? true, $payload);
        }
        try {
            if (($request['mode'] ?? null) === 'queue') {
                $submitted = $this->transport->submitFalQueue($provider, $request['endpoint'], $payload, $request['bindings'] ?? []);

                return SubmitResult::accepted($submitted['request_id']);
            }
            if (($request['mode'] ?? null) === 'direct') {
                return SubmitResult::immediate([], $this->transport->runFalDirect($provider, $request['endpoint'], $payload));
            }
            $result = ($request['mode'] ?? null) === 'native'
                ? $this->transport->imageGeneration($provider, $payload)
                : $this->transport->runCatalogImage($provider, $request['endpoint'], $payload);

            return SubmitResult::immediate(array_column($result['data'], 'url'), $result);
        } catch (AiProxyException $exception) {
            return $exception instanceof AiProviderRequestRejected || $exception->responseStatus() === 422
                ? SubmitResult::rejected($exception->getMessage()) : SubmitResult::uncertain();
        }
    }

    public function pollStatus(AiProviderProfile $provider, string $taskId, array $context = []): StatusResult
    {
        $status = $this->transport->falQueueStatus($provider, $taskId, $context);
        if ($status['status'] === 'COMPLETED') {
            try {
                $result = $this->transport->falQueueResult($provider, $taskId, $context);
            } catch (AiProviderRequestRejected $error) {
                if ($error->upstreamStatus !== 422) {
                    throw $error;
                }
                return StatusResult::failed('The media provider could not complete this request.');
            }
            return StatusResult::completed([], $result);
        }
        if ($status['status'] === 'FAILED') {
            return StatusResult::failed('The media provider could not complete this request.');
        }

        return StatusResult::processing();
    }

    public function cancel(AiProviderProfile $provider, string $taskId, array $context = []): array
    {
        return $this->transport->cancelFalQueue($provider, $taskId, $context);
    }

    public function normalizeError(Throwable $error): array
    {
        return [
            'public_code' => 'fal_media_error', 'public_message' => 'Media generation could not be completed.',
            'recoverable' => $error instanceof AiProxyException && in_array($error->responseStatus(), [503, 504], true),
            'internal' => $error::class,
        ];
    }
}
