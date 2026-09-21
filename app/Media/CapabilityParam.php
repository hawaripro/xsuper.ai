<?php

namespace App\Media;

/**
 * One declared scalar parameter of a MediaCapability (size, aspect_ratio, duration,
 * resolution, quality, voice, …). `name` is the stable request identity.
 */
final readonly class CapabilityParam
{
    public function __construct(
        public string $name,
        public string $type,
        public mixed $default = null,
        public ?array $options = null,
        public mixed $min = null,
        public mixed $max = null,
        public ?string $unit = null,
        public bool $required = false,
        public ?array $requiredWhen = null,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'default' => $this->default,
            'options' => $this->options,
            'min' => $this->min,
            'max' => $this->max,
            'unit' => $this->unit,
            'required' => $this->required,
            'required_when' => $this->requiredWhen,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['name'],
            (string) $data['type'],
            $data['default'] ?? null,
            isset($data['options']) && is_array($data['options']) ? $data['options'] : null,
            $data['min'] ?? null,
            $data['max'] ?? null,
            isset($data['unit']) ? (string) $data['unit'] : null,
            (bool) ($data['required'] ?? false),
            isset($data['required_when']) && is_array($data['required_when']) ? $data['required_when'] : null,
        );
    }
}
