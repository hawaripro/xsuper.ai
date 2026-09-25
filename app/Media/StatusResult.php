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
        /** Complete provider JSON. Provider URLs must be replaced before member presentation. */
        public mixed $resultData = null,
        /** Provider-reported completion percentage (0–100) while processing; null when not reported. */
        public ?int $progress = null,
    ) {}

    public static function processing(?int $progress = null): self
    {
        return new self(MediaState::Processing, progress: $progress === null ? null : max(0, min(100, $progress)));
    }

    /** @param  string[]  $resultUrls */
    public static function completed(array $resultUrls, mixed $resultData = null): self
    {
        return new self(MediaState::Completed, resultUrls: array_values($resultUrls), resultData: $resultData);
    }

    public static function failed(?string $publicError = null): self
    {
        return new self(MediaState::Failed, publicError: $publicError);
    }
}
