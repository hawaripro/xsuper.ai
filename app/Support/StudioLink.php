<?php

namespace App\Support;

/**
 * Deep links into the full-page media studio, mirroring studioHref() in studioLinks.js. `kind` is only a
 * studio filter and is dropped when unknown; job, track, model and operation keep the meaning the legacy
 * studio pages gave them. Callers pass the query in contract order: model, operation, job, track.
 */
final class StudioLink
{
    public const KINDS = ['image', 'video', 'audio', 'avatar', 'model3d'];

    public static function to(?string $kind = null, array $query = []): string
    {
        $parameters = array_filter(
            ['kind' => in_array($kind, self::KINDS, true) ? $kind : null, ...$query],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return $parameters === [] ? '/studio' : '/studio?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
