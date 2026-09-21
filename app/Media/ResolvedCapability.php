<?php

namespace App\Media;

/**
 * A resolved capability plus the provenance a job must record. `origin` is
 * 'published' (from a stored revision) or 'legacy' (derived from existing config).
 * `revisionId` is set for published; legacy revisions are materialized on demand via
 * CapabilityResolver::ensureRevision so every core job carries a stable reference.
 */
final readonly class ResolvedCapability
{
    public function __construct(
        public MediaCapability $capability,
        public string $origin,
        public ?int $revisionId,
        public string $sourceHash,
    ) {}
}
