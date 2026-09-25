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
            $revision = $resolved->revisionId === null ? null : MediaCapabilityRevision::query()->select(['id', 'ui_metadata', 'source_metadata->examples as examples'])->find($resolved->revisionId);
            $storedUi = $revision?->ui_metadata ?? [];
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
                $examples = self::examples($revision?->examples);
                if ($examples !== []) {
                    $payload['examples'] = $examples;
                }
            }
            $out[$operationValue] = $payload;
        }

        return $out;
    }

    /** Public execution facts only: no endpoint, queue root, request schema or credentials. Async Runware tasks are queued jobs. */
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

    /**
     * Reviewed form presets imported with the revision: title, prompt and member-schema values only.
     *
     * @return list<array{title: string, prompt: ?string, values: array<string, mixed>}>
     */
    private static function examples(mixed $stored): array
    {
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;
        $examples = [];
        foreach (is_array($stored) && array_is_list($stored) ? array_slice($stored, 0, 6) : [] as $example) {
            if (is_array($example) && is_string($example['title'] ?? null) && is_array($example['values'] ?? null)) {
                $examples[] = ['title' => $example['title'], 'prompt' => is_string($example['prompt'] ?? null) ? $example['prompt'] : null,
                    'values' => $example['values']];
            }
        }

        return $examples;
    }
}
