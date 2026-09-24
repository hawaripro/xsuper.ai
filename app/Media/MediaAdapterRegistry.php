<?php

namespace App\Media;

use App\Media\Adapters\FalAdapter;
use App\Media\Adapters\KinoviAdapter;
use App\Media\Adapters\OpenAiAdapter;
use App\Media\Contracts\MediaProviderAdapter;
use InvalidArgumentException;

/**
 * Resolves a provider protocol to its media adapter. Only integrations that are actually
 * implemented are registered; asking for an unimplemented protocol is a hard error, never
 * a silent empty adapter marked active.
 */
final class MediaAdapterRegistry
{
    public function __construct(private readonly KinoviAdapter $kinovi, private readonly FalAdapter $fal, private readonly OpenAiAdapter $openAi) {}

    public function for(string $protocol): MediaProviderAdapter
    {
        return match ($protocol) {
            'kinovi' => $this->kinovi,
            'fal' => $this->fal,
            'openai' => $this->openAi,
            default => throw new InvalidArgumentException("No media adapter is implemented for protocol '{$protocol}'."),
        };
    }

    public function has(string $protocol): bool
    {
        return in_array($protocol, ['kinovi', 'fal', 'openai'], true);
    }
}
