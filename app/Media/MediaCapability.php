<?php

namespace App\Media;

use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;

/**
 * The uniform, versioned internal contract for one model + operation. Produced only
 * by CapabilityResolver (from a stored published revision or explicit legacy
 * derivation) so frontend, backend, and validation share one rule set. `contract_version`
 * is the FORMAT version and is independent of the stored model-config revision.
 */
final readonly class MediaCapability
{
    /**
     * @param  CapabilityInput[]  $inputs
     * @param  CapabilityParam[]  $params
     */
    public function __construct(
        public string $modelPublicId,
        public MediaOperation $operation,
        public OutputKind $outputKind,
        public int $contractVersion,
        public array $inputs = [],
        public array $params = [],
        /** Internal routing is deliberately excluded from toArray()/member payloads. */
        public array $providerBindings = [],
        public array $inputSchema = [],
        public array $outputSchema = [],
    ) {}

    /** @return CapabilityInput[] */
    public function requiredInputs(): array
    {
        return array_values(array_filter($this->inputs, static fn (CapabilityInput $i): bool => $i->required));
    }

    public function input(InputRole $role): ?CapabilityInput
    {
        foreach ($this->inputs as $input) {
            if ($input->role === $role) {
                return $input;
            }
        }

        return null;
    }

    public function inputByKey(string $key): ?CapabilityInput
    {
        foreach ($this->inputs as $input) {
            if ($input->key === $key) {
                return $input;
            }
        }

        return null;
    }

    public function param(string $name): ?CapabilityParam
    {
        foreach ($this->params as $param) {
            if ($param->name === $name) {
                return $param;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'model_public_id' => $this->modelPublicId,
            'operation' => $this->operation->value,
            'output_kind' => $this->outputKind->value,
            'contract_version' => $this->contractVersion,
            'inputs' => array_map(static fn (CapabilityInput $i): array => $i->toArray(), $this->inputs),
            'params' => array_map(static fn (CapabilityParam $p): array => $p->toArray(), $this->params),
            ...($this->contractVersion >= 2 ? [
                'input_schema' => $this->inputSchema,
                'output_schema' => $this->outputSchema,
            ] : []),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['model_public_id'],
            MediaOperation::from((string) $data['operation']),
            OutputKind::from((string) $data['output_kind']),
            (int) $data['contract_version'],
            array_map(static fn (array $i): CapabilityInput => CapabilityInput::fromArray($i), $data['inputs'] ?? []),
            array_map(static fn (array $p): CapabilityParam => CapabilityParam::fromArray($p), $data['params'] ?? []),
            $data['provider_bindings'] ?? [],
            $data['input_schema'] ?? [],
            $data['output_schema'] ?? [],
        );
    }
}
