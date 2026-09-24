<?php

namespace App\Media\Contracts;

use App\Models\AiProviderProfile;

/**
 * An adapter that uploads owned reference files to the provider before the generation request.
 * Uploading creates no paid generation, so callers stage while a job is still preparing and only
 * then durably mark it as submitting; the returned request needs no further staging in submit().
 */
interface StagesProviderReferences
{
    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function stageReferences(AiProviderProfile $provider, array $request): array;
}
