<?php

namespace App\Media;

/**
 * Public official documentation for exact Fal endpoints whose exported contract is missing or known
 * to be incomplete (resources/media/fal/manifest.json). Only the source document is replaced: the
 * endpoint identity, metadata and every other entry key stay untouched, other endpoints are never
 * aliased, and the original export is kept under source_evidence.original.
 */
final class FalSupplementalContracts
{
    private const DIRECTORY = 'media/fal';

    /** @var array<string, array> */
    private static array $documents = [];

    /** @var array<string, string> */
    private static array $hashes = [];

    /** Idempotent: an enriched entry, a newer export or any other endpoint is returned unchanged. */
    public static function enrich(array $entry): array
    {
        $endpoint = $entry['endpoint_id'] ?? null;
        $supplement = is_string($endpoint) ? (self::document('manifest.json')['supplements'][$endpoint] ?? null) : null;
        if ($supplement === null) {
            return $entry;
        }
        $original = $entry['openapi'] ?? null;
        $originalHash = FalCapabilityImporter::hash(is_array($original) ? $original : []);
        $knownIncomplete = array_map(self::hash(...), $supplement['known_incomplete']);
        if (! self::missing($original) && ! in_array($originalHash, $knownIncomplete, true)) {
            return $entry;
        }
        $contract = self::document($supplement['contract']);
        $documentation = $supplement['documentation'] === 'embedded'
            ? $contract['x-workspace-fal-documentation'] : $supplement['documentation'];

        return [...$entry, 'openapi' => $contract, 'source_evidence' => [
            'version' => 1,
            'kind' => 'official_public_documentation_supplement',
            'endpoint_id' => $endpoint,
            // Mirrors FalCapabilityImporter/FalSchemaNormalizer dispatch for the recovered document.
            'transport' => match (true) {
                isset($contract['asyncapi'], $contract['x-fal-wma']) => 'realtime',
                ($contract['x-workspace-transport'] ?? null) === 'direct' => 'direct',
                default => 'queue',
            },
            'contract' => [
                'file' => 'resources/'.self::DIRECTORY.'/'.$supplement['contract'],
                'format' => isset($contract['asyncapi']) ? 'asyncapi-'.$contract['asyncapi'] : 'openapi-'.$contract['openapi'],
                'source_kind' => $supplement['source_kind'],
                'source_hash' => self::hash($supplement['contract']),
            ],
            'sources' => $documentation['provenance'],
            'warnings' => $documentation['warnings'],
            'original' => [
                'openapi_present' => array_key_exists('openapi', $entry),
                'openapi' => $original,
                'source_hash' => $originalHash,
            ],
        ]];
    }

    /** An absent, empty or error-only export publishes no contract of its own. */
    private static function missing(mixed $source): bool
    {
        return ! is_array($source) || $source === [] || array_keys($source) === ['error'];
    }

    private static function hash(string $file): string
    {
        return self::$hashes[$file] ??= FalCapabilityImporter::hash(self::document($file));
    }

    private static function document(string $file): array
    {
        return self::$documents[$file] ??= json_decode(
            file_get_contents(resource_path(self::DIRECTORY.'/'.$file)), true, 512, JSON_THROW_ON_ERROR,
        );
    }
}
