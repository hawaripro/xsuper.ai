<?php

namespace App\Media;

use Illuminate\Validation\ValidationException;

/** Maps the small OpenAI image contract into the model's published member schema. */
final class OpenAiImageInputs
{
    public function map(array $capability, string $prompt, int $count, ?string $size): array
    {
        $schema = $capability['input_schema'] ?? [];
        $properties = $this->properties($schema);
        $native = ($capability['contract_version'] ?? 1) < 2;
        if ($native) {
            foreach ($capability['inputs'] ?? [] as $input) {
                if (($input['role'] ?? null) === 'prompt') {
                    $properties[$input['key']] = ['type' => 'string'];
                }
            }
            foreach ($capability['params'] ?? [] as $param) {
                $properties[$param['name']] = ['type' => $param['type'], 'enum' => $param['options'] ?? [],
                    'minimum' => $param['min'] ?? null, 'maximum' => $param['max'] ?? null];
            }
        }
        $promptField = null;
        foreach (['prompt', 'positivePrompt', 'positive_prompt', 'text'] as $field) {
            if (isset($properties[$field])) {
                $promptField = $field;
                break;
            }
        }
        if ($promptField === null) {
            throw ValidationException::withMessages(['model' => 'This model needs structured inputs. Use /v1/media/generations.']);
        }
        $inputs = [$promptField => $prompt];
        $executionCount = 1;
        if ($native) {
            $executionCount = $count;
        } else {
            $quantity = $capability['billing']['quantity_input'] ?? null;
            foreach (array_unique(array_filter([$quantity, 'numberResults', 'num_images', 'num_outputs', 'n'])) as $field) {
                if (isset($properties[$field])) {
                    $inputs[$field] = $count;
                    $quantity = $field;
                    break;
                }
            }
            if ($count > 1 && (! is_string($quantity) || ! array_key_exists($quantity, $inputs))) {
                throw ValidationException::withMessages(['n' => 'This model supports one image per request. Use n=1.']);
            }
        }
        if ($size !== null) {
            $inputs = [...$inputs, ...$this->size($properties, $size)];
        }

        return ['inputs' => $inputs, 'count' => $executionCount];
    }

    private function properties(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        foreach ($schema['allOf'] ?? [] as $branch) {
            if (is_array($branch)) {
                $properties = array_replace_recursive($properties, $this->properties($branch));
            }
        }

        return $properties;
    }

    private function size(array $properties, string $size): array
    {
        if (! preg_match('/^([1-9][0-9]{0,4})x([1-9][0-9]{0,4})$/D', $size, $match)) {
            throw ValidationException::withMessages(['size' => 'Size must be a supported widthxheight, for example 1024x1024.']);
        }
        [$width, $height] = [(int) $match[1], (int) $match[2]];
        foreach (['size', 'image_size', 'aspect_ratio', 'aspectRatio'] as $field) {
            if (! isset($properties[$field])) {
                continue;
            }
            $node = $properties[$field];
            $options = $this->options($node);
            $selected = null;
            $best = INF;
            foreach ($options as $option) {
                if (! is_string($option) || ($dimensions = $this->dimensions($option)) === null) {
                    continue;
                }
                [$w, $h] = $dimensions;
                // Aspect dominates, then prefer the closest supported resolution of that aspect.
                $score = abs(log(($w / $h) / ($width / $height))) * 1000
                    + (str_contains($option, ':') ? 0 : abs(log(($w * $h) / ($width * $height))));
                if ($score < $best) {
                    $best = $score;
                    $selected = $option;
                }
            }
            if ($selected !== null) {
                return [$field => $selected];
            }
            $dimensions = $this->properties($node);
            if (isset($dimensions['width'], $dimensions['height'])) {
                return [$field => ['width' => $this->dimension($dimensions['width'], $width),
                    'height' => $this->dimension($dimensions['height'], $height)]];
            }
            foreach (['anyOf', 'oneOf'] as $keyword) {
                foreach ($node[$keyword] ?? [] as $branch) {
                    $dimensions = $this->properties($branch);
                    if (isset($dimensions['width'], $dimensions['height'])) {
                        return [$field => ['width' => $this->dimension($dimensions['width'], $width),
                            'height' => $this->dimension($dimensions['height'], $height)]];
                    }
                }
            }
        }
        if (isset($properties['width'], $properties['height'])) {
            return ['width' => $this->dimension($properties['width'], $width), 'height' => $this->dimension($properties['height'], $height)];
        }
        throw ValidationException::withMessages(['size' => 'This model has no supported size mapping. Omit size or use /v1/media/generations.']);
    }

    private function options(array $schema): array
    {
        $options = $schema['enum'] ?? (isset($schema['const']) ? [$schema['const']] : []);
        foreach (['anyOf', 'oneOf'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $branch) {
                $options = [...$options, ...$this->options($branch)];
            }
        }

        return $options;
    }

    private function dimensions(string $value): ?array
    {
        if (preg_match('/^([1-9][0-9]*)(?:x|:)([1-9][0-9]*)$/D', $value, $parts)) {
            return [(int) $parts[1], (int) $parts[2]];
        }

        return match ($value) {
            'square' => [512, 512], 'square_hd' => [1024, 1024],
            'portrait_4_3' => [768, 1024], 'portrait_16_9' => [576, 1024],
            'landscape_4_3' => [1024, 768], 'landscape_16_9' => [1024, 576],
            default => null,
        };
    }

    private function dimension(array $schema, int $requested): int
    {
        $options = array_values(array_filter($this->options($schema), static fn ($value): bool => is_int($value) && $value > 0));
        if ($options !== []) {
            usort($options, static fn (int $a, int $b): int => abs($a - $requested) <=> abs($b - $requested));

            return $options[0];
        }
        $minimum = max(1, (int) ($schema['minimum'] ?? 1));
        $maximum = max($minimum, (int) ($schema['maximum'] ?? 16384));
        $step = max(1, (int) ($schema['multipleOf'] ?? 1));
        $value = max((int) ceil($minimum / $step) * $step,
            min((int) floor($maximum / $step) * $step, (int) round($requested / $step) * $step));
        if ($value > $maximum) {
            throw ValidationException::withMessages(['size' => 'This model has no supported size mapping.']);
        }

        return $value;
    }
}
