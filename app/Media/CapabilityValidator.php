<?php

namespace App\Media;

use App\Media\Exceptions\CapabilityValidationException;
use InvalidArgumentException;

/**
 * The single backend authority that checks normalized member input against a resolved
 * MediaCapability. Enforces required + conditional-required rules, cardinality, param
 * enum/range, and rejects unknown fields. Rules are a fixed declarative vocabulary
 * (`param_equals`, `has_input`) — never evaluated as free code. Returns the sanitized
 * `{inputs, params}` set; throws CapabilityValidationException with a field=>message map.
 */
final class CapabilityValidator
{
    /**
     * @param  array<string, mixed>  $raw  member payload keyed by input key / param name
     * @return array{inputs: array<string, mixed>, params: array<string, mixed>}
     */
    public function validate(MediaCapability $capability, array $raw): array
    {
        $errors = [];
        $inputByKey = [];
        foreach ($capability->inputs as $input) {
            $inputByKey[$input->key] = $input;
        }
        $paramByName = [];
        foreach ($capability->params as $param) {
            $paramByName[$param->name] = $param;
        }

        foreach (array_keys($raw) as $key) {
            if (! isset($inputByKey[$key]) && ! isset($paramByName[$key])) {
                $errors[(string) $key] = 'Field tidak dikenal untuk model/operasi ini.';
            }
        }

        // Params first: validate provided values, apply defaults, so conditional-required
        // rules can read a stable param snapshot.
        $params = [];
        foreach ($capability->params as $param) {
            if (array_key_exists($param->name, $raw) || $param->default !== null) {
                $value = array_key_exists($param->name, $raw) ? $raw[$param->name] : $param->default;
                $message = $this->validateParam($param, $value);
                if ($message !== null) {
                    $errors[$param->name] = $message;

                    continue;
                }
                $params[$param->name] = $value;
            }
        }
        foreach ($capability->params as $param) {
            if (isset($errors[$param->name])) {
                continue;
            }
            $required = $param->required || $this->evaluateRule($param->requiredWhen, $params, $raw);
            if ($required && ! array_key_exists($param->name, $params)) {
                $errors[$param->name] = 'Parameter ini wajib diisi.';
            }
        }

        $inputs = [];
        foreach ($capability->inputs as $input) {
            $required = $input->required || $this->evaluateRule($input->requiredWhen, $params, $raw);
            $present = array_key_exists($input->key, $raw) && $this->nonEmpty($raw[$input->key]);
            if (! $present) {
                if ($required) {
                    $errors[$input->key] = $errors[$input->key] ?? 'Input ini wajib disediakan.';
                }

                continue;
            }
            [$value, $message] = $this->coerceInput($input, $raw[$input->key]);
            if ($message !== null) {
                $errors[$input->key] = $message;

                continue;
            }
            $inputs[$input->key] = $value;
        }

        if ($errors !== []) {
            throw new CapabilityValidationException($errors);
        }

        return ['inputs' => $inputs, 'params' => $params];
    }

    private function validateParam(CapabilityParam $param, mixed $value): ?string
    {
        $validType = match ($param->type) {
            'integer' => is_int($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'enum' => $param->options !== null && in_array($value, $param->options, true),
            default => false,
        };
        if (! $validType) {
            return 'Tipe nilai tidak sesuai dengan parameter model ini.';
        }
        if ($param->options !== null && ! in_array($value, $param->options, true)) {
            return 'Pilihan tidak didukung oleh model ini.';
        }
        if ($param->min !== null && is_numeric($value) && $value < $param->min) {
            return 'Nilai di bawah batas minimum model ini.';
        }
        if ($param->max !== null && is_numeric($value) && $value > $param->max) {
            return 'Nilai melebihi batas maksimum model ini.';
        }

        return null;
    }

    /**
     * @return array{0: mixed, 1: ?string}
     */
    private function coerceInput(CapabilityInput $input, mixed $value): array
    {
        if ($input->type === 'string') {
            if (! is_string($value)) {
                return [null, 'Nilai harus berupa teks.'];
            }
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [null, 'Input ini tidak boleh kosong.'];
            }

            return [$trimmed, null];
        }

        if ($input->type === 'asset') {
            $ids = is_array($value) ? array_values($value) : [$value];
            foreach ($ids as $id) {
                if (! is_string($id) || trim($id) === '') {
                    return [null, 'Referensi aset tidak valid.'];
                }
            }
            if ($ids === []) {
                return [null, 'Sertakan minimal satu aset.'];
            }
            if (count($ids) > max(1, $input->max)) {
                return [null, "Maksimum {$input->max} aset untuk input ini."];
            }

            return $input->single ? [$ids[0], null] : [$ids, null];
        }

        return [$value, null];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $raw
     */
    private function evaluateRule(?array $rule, array $params, array $raw): bool
    {
        if ($rule === null) {
            return false;
        }
        if (isset($rule['param_equals']) && is_array($rule['param_equals'])) {
            $name = $rule['param_equals']['name'] ?? null;

            return $name !== null && ($params[$name] ?? null) === ($rule['param_equals']['value'] ?? null);
        }
        if (isset($rule['has_input'])) {
            $key = (string) $rule['has_input'];

            return array_key_exists($key, $raw) && $this->nonEmpty($raw[$key]);
        }

        throw new InvalidArgumentException('Unsupported required_when rule: '.json_encode($rule));
    }

    private function nonEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return ! (is_array($value) && $value === []);
    }
}
