<?php

namespace App\Media;

use App\Media\Enums\MediaOperation;
use App\Models\AiModelProfile;
use App\Services\MediaModelConfig;
use Throwable;

/**
 * Presents a model's genuinely-supported operations as frontend-facing capability payloads:
 * the resolved capability definition + default UI metadata + the source hash (for
 * expected_capability_hash) + the token price (for expected_price_tokens). A broken or
 * unsupported operation is simply omitted, never offered as a dead control.
 */
final class CapabilityPresenter
{
    public function __construct(private readonly CapabilityResolver $resolver) {}

    /** @return array<string, array<string, mixed>> operation value => payload */
    public function forModel(AiModelProfile $model): array
    {
        $out = [];
        foreach (array_keys(MediaModelConfig::deriveCapabilities($model)) as $operationValue) {
            $operation = MediaOperation::from($operationValue);
            try {
                $resolved = $this->resolver->resolve($model, $operation);
            } catch (Throwable) {
                continue;
            }
            $definition = $resolved->capability->toArray();
            $out[$operationValue] = [
                'operation' => $operationValue,
                'output_kind' => $definition['output_kind'],
                'contract_version' => $definition['contract_version'],
                'source_hash' => $resolved->sourceHash,
                'price_tokens' => (int) $model->token_cost,
                'inputs' => $definition['inputs'],
                'params' => $definition['params'],
                'ui' => CapabilityUi::describe($resolved->capability),
            ];
        }

        return $out;
    }
}
