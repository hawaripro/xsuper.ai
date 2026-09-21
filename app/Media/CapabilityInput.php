<?php

namespace App\Media;

use App\Media\Enums\InputRole;

/**
 * One declared input of a MediaCapability. `key` is the stable request identity;
 * `role` is its meaning. `single`/`max` express cardinality; `requiredWhen` carries
 * a declarative conditional-required rule (interpreted by CapabilityValidator, never
 * evaluated as free code).
 */
final readonly class CapabilityInput
{
    public function __construct(
        public string $key,
        public InputRole $role,
        public string $type,
        public bool $single = true,
        public int $max = 1,
        public bool $required = false,
        public ?array $requiredWhen = null,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'role' => $this->role->value,
            'type' => $this->type,
            'single' => $this->single,
            'max' => $this->max,
            'required' => $this->required,
            'required_when' => $this->requiredWhen,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['key'],
            InputRole::from((string) $data['role']),
            (string) $data['type'],
            (bool) ($data['single'] ?? true),
            (int) ($data['max'] ?? 1),
            (bool) ($data['required'] ?? false),
            isset($data['required_when']) && is_array($data['required_when']) ? $data['required_when'] : null,
        );
    }
}
