<?php

namespace App\Media;

use App\Media\Enums\MediaState;

/** Normalized result of an adapter status poll; construct via the tagged factory methods. */
final readonly class StatusResult
{
    /** @param  string[]|null  $resultUrls */
    private function __construct(
        public MediaState $state,
        public ?array $resultUrls = null,
        public ?string $publicError = null,
    ) {}

    public static function processing(): self
    {
        return new self(MediaState::Processing);
    }

    /** @param  string[]  $resultUrls */
    public static function completed(array $resultUrls): self
    {
        return new self(MediaState::Completed, resultUrls: array_values($resultUrls));
    }

    public static function failed(?string $publicError = null): self
    {
        return new self(MediaState::Failed, publicError: $publicError);
    }
}
