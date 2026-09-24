<?php

namespace App\Media;

use App\Media\Enums\SubmitOutcome;

/** Normalized result of an adapter submit; construct via the tagged factory methods. */
final readonly class SubmitResult
{
    /** @param  string[]|null  $resultUrls */
    private function __construct(
        public SubmitOutcome $outcome,
        public ?string $taskId = null,
        public ?array $resultUrls = null,
        public ?string $publicError = null,
        /** Complete provider JSON, including native base64 images and non-file fields. Internal only. */
        public mixed $resultData = null,
    ) {}

    /** @param  string[]  $resultUrls */
    public static function immediate(array $resultUrls, mixed $resultData = null): self
    {
        return new self(SubmitOutcome::Immediate, resultUrls: array_values($resultUrls), resultData: $resultData);
    }

    public static function accepted(string $taskId): self
    {
        return new self(SubmitOutcome::Accepted, taskId: $taskId);
    }

    public static function rejected(string $publicError): self
    {
        return new self(SubmitOutcome::Rejected, publicError: $publicError);
    }

    public static function uncertain(): self
    {
        return new self(SubmitOutcome::Uncertain);
    }
}
