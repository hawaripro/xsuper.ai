<?php

namespace App\Media;

use App\Media\Enums\MediaOperation;
use App\Models\AiModelProfile;
use App\Models\MediaCapabilityRevision;
use App\Services\RealtimeMediaService;
use Throwable;

/**
 * Presents a model's genuinely-supported operations as frontend-facing capability payloads:
 * the resolved definition (version-2 typed schemas included) + stored/default UI metadata +
 * the quote hash (for expected_capability_hash) + the token price (for expected_price_tokens)
 * + public execution facts. Provider bindings never leave the server. A broken or unsupported
 * operation is simply omitted, never offered as a dead control.
 */
final class CapabilityPresenter
{
    public function __construct(private readonly CapabilityResolver $resolver) {}

    /** @return array<string, array<string, mixed>> operation value => payload */
    public function forModel(AiModelProfile $model): array
    {
        $out = [];
        foreach ($this->resolver->operations($model) as $operationValue) {
            $operation = MediaOperation::from($operationValue);
            try {
                $resolved = $this->resolver->resolve($model, $operation, schemaContracts: true);
            } catch (Throwable) {
                continue;
            }
            $definition = $resolved->capability->toArray();
            $storedUi = $resolved->revisionId === null ? [] : (MediaCapabilityRevision::find($resolved->revisionId)?->ui_metadata ?? []);
            $payload = [
                'operation' => $operationValue,
                'output_kind' => $definition['output_kind'],
                'contract_version' => $definition['contract_version'],
                'source_hash' => $resolved->sourceHash,
                'price_tokens' => (int) $model->token_cost,
                'inputs' => $definition['inputs'],
                'params' => $definition['params'],
                'ui' => array_replace_recursive(CapabilityUi::describe($resolved->capability), $storedUi),
            ];
            if ($definition['contract_version'] >= 2) {
                $payload['input_schema'] = $definition['input_schema'];
                $payload['output_schema'] = $definition['output_schema'];
                $payload['execution'] = self::execution($resolved->capability->providerBindings);
            }
            $out[$operationValue] = $payload;
        }

        return $out;
    }

    /** Public execution facts only: no endpoint, queue root, request schema or credentials. */
    private static function execution(array $bindings): array
    {
        if (($bindings['adapter'] ?? null) === 'fal_wma_v1') {
            return [
                'transport' => 'realtime',
                'max_session_seconds' => RealtimeMediaService::maxSessionSeconds($bindings),
                'update_schema' => $bindings['execution']['update_schema'] ?? null,
            ];
        }

        return ['transport' => ($bindings['transport'] ?? 'queue') === 'direct' ? 'direct' : 'queue'];
    }
}
