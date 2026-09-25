<?php

namespace App\Media;

/**
 * Admin-only provider price reference captured with a Runware import. It never sets a sale
 * price: token billing meters per result (`generation`) or per whole second of the `duration`
 * input (`second`), so every other provider unit is disclosed in a note for manual pricing.
 */
final class RunwarePriceReference
{
    /** Provider units token billing can meter directly. */
    private const METERED = ['output', 'durationSecond'];

    private const UNIT_LABELS = [
        'character' => 'character', 'utf8Byte' => 'UTF-8 byte', 'inputToken' => 'input token', 'outputToken' => 'output token',
        'cachedInputToken' => 'cached input token', 'cacheWriteToken' => 'cache write token', 'inputImage' => 'input image',
        'inputMegapixel' => 'input megapixel', 'outputMegapixel' => 'output megapixel', 'step' => 'step',
        'computeSecond' => 'compute second',
    ];

    private const TEXT_UNITS = ['character', 'utf8Byte', 'inputToken', 'outputToken', 'cachedInputToken', 'cacheWriteToken'];

    /**
     * @param  array<string, mixed>  $document  the model's OpenAPI document (x-pricing)
     * @param  array<string, mixed>|null  $content  the content catalog entry (pricing fallback)
     * @param  array<string, mixed>  $request  the resolved task item, for input bounds in the note
     * @return array{currency: string, overview: ?string, basis: ?string, rates: list<array<string, mixed>>, measured: list<array<string, mixed>>, examples: list<array<string, mixed>>, catalog_unit: string, note: ?string}
     */
    public static function build(array $document, ?array $content, array $request): array
    {
        $pricing = is_array($document['info']['x-pricing'] ?? null) ? $document['info']['x-pricing'] : [];
        $content ??= [];
        $rates = self::rates($pricing['rates'] ?? $content['pricingRates'] ?? []);
        $measured = self::runs($pricing['measured'] ?? $content['pricingMeasured'] ?? []);
        $examples = self::runs($pricing['examples'] ?? $content['pricingExamples'] ?? []);
        $units = array_values(array_unique(array_column($rates, 'unit')));
        $unit = in_array('durationSecond', $units, true) ? 'second' : 'generation';

        return [
            'currency' => 'USD',
            'overview' => self::text($pricing['overview'] ?? $content['pricingOverview'] ?? null, 500),
            'basis' => self::text($pricing['basis'] ?? $content['pricingBasis'] ?? null, 40),
            'rates' => $rates, 'measured' => $measured, 'examples' => $examples,
            'catalog_unit' => $unit,
            'note' => self::note($units, $rates === [] && $measured !== [], $rates === [] && $measured === [] && $examples === [], $unit, $request),
        ];
    }

    private static function note(array $units, bool $computeTime, bool $unpriced, string $unit, array $request): ?string
    {
        $price = $unit === 'second' ? 'per-second token price' : 'flat token price per result';
        if ($unpriced) {
            return 'Runware publishes no price for this model. Verify the provider cost before setting a token price.';
        }
        if ($computeTime) {
            $limits = self::limits(['step', 'outputMegapixel', 'duration'], $request);

            return "Runware bills this model by compute time, so the cost varies with the configuration. The {$price} must cover the most expensive configuration the schema allows".($limits === '' ? '.' : ": {$limits}.");
        }
        $unmetered = array_values(array_diff($units, self::METERED));
        if ($unmetered === []) {
            return null;
        }
        $labels = implode(', ', array_map(static fn (string $name): string => self::UNIT_LABELS[$name] ?? $name, $unmetered));
        $lead = array_intersect($units, self::METERED) === [] ? "Runware bills this model per {$labels}" : "Runware also bills per {$labels}";
        $limits = self::limits($unmetered, $request);

        return "{$lead}, which token billing cannot meter. The {$price} must cover the largest input the schema allows".($limits === '' ? '.' : ": {$limits}.");
    }

    /** Human-readable schema bounds for the inputs a provider unit scales with. */
    private static function limits(array $units, array $request): string
    {
        $properties = is_array($request['properties'] ?? null) ? $request['properties'] : [];
        $parts = [];
        if (array_intersect($units, self::TEXT_UNITS) !== []) {
            foreach (['speech.text' => $properties['speech']['properties']['text'] ?? null, 'positivePrompt' => $properties['positivePrompt'] ?? null,
                'prompt' => $properties['prompt'] ?? null] as $field => $schema) {
                if (is_array($schema) && is_int($schema['maxLength'] ?? null)) {
                    $parts[] = "{$field} up to ".number_format($schema['maxLength']).' characters';
                }
            }
        }
        if (array_intersect($units, ['inputImage', 'inputMegapixel']) !== []) {
            foreach (is_array($properties['inputs']['properties'] ?? null) ? $properties['inputs']['properties'] : [] as $name => $schema) {
                if (! is_array($schema) || ! str_contains(strtolower((string) $name), 'image')) {
                    continue;
                }
                $parts[] = ($schema['type'] ?? null) === 'array'
                    ? "inputs.{$name} up to ".(is_int($schema['maxItems'] ?? null) ? $schema['maxItems'] : 'an unbounded number of').' images'
                    : "inputs.{$name} (1 image)";
            }
            if (in_array('inputMegapixel', $units, true)) {
                $parts[] = 'input image resolution is not bounded by the schema';
            }
        }
        if (in_array('outputMegapixel', $units, true) && is_numeric($properties['width']['maximum'] ?? null) && is_numeric($properties['height']['maximum'] ?? null)) {
            $parts[] = 'width and height up to '.$properties['width']['maximum'].' × '.$properties['height']['maximum'].' px';
        }
        if (in_array('step', $units, true) && is_numeric($properties['steps']['maximum'] ?? null)) {
            $parts[] = 'steps up to '.$properties['steps']['maximum'];
        }
        if (in_array('duration', $units, true) && is_numeric($properties['duration']['maximum'] ?? null)) {
            $parts[] = 'duration up to '.$properties['duration']['maximum'].' s';
        }

        return implode('; ', array_values(array_unique($parts)));
    }

    /** @return list<array{amount: float|int, unit: string, label: ?string, display: ?string}> */
    private static function rates(mixed $rates): array
    {
        $result = [];
        foreach (is_array($rates) ? array_slice($rates, 0, 50) : [] as $rate) {
            if (! is_array($rate) || ! is_numeric($rate['amount'] ?? null) || ! is_string($rate['unit'] ?? null)
                || preg_match('/^[A-Za-z][A-Za-z0-9]{0,39}$/D', $rate['unit']) !== 1) {
                continue;
            }
            $result[] = [
                'amount' => $rate['amount'] + 0, 'unit' => $rate['unit'],
                'label' => self::text($rate['label'] ?? null, 120), 'display' => self::text($rate['display'] ?? null, 120),
                ...(is_numeric($rate['after'] ?? null) ? ['after' => $rate['after'] + 0] : []),
            ];
        }

        return $result;
    }

    /** @return list<array{configuration: string, price: float|int}> */
    private static function runs(mixed $runs): array
    {
        $result = [];
        foreach (is_array($runs) ? array_slice($runs, 0, 20) : [] as $run) {
            $configuration = is_array($run) ? self::text($run['configuration'] ?? null, 160) : null;
            if ($configuration === null || ! is_numeric($run['price'] ?? null)) {
                continue;
            }
            $result[] = ['configuration' => $configuration, 'price' => $run['price'] + 0,
                ...(is_numeric($run['latencyMs'] ?? null) ? ['latency_ms' => (int) $run['latencyMs']] : [])];
        }

        return $result;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
