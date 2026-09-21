<?php

namespace App\Media;

use App\Exceptions\ImageGenerationException;
use App\Models\User;

/**
 * Server-side control for NEW media submissions. Two orthogonal concerns:
 *
 * - Kill switch (assertNotPaused): an emergency pause for ALL new submissions. In-flight jobs
 *   (process/poll) never call this, so accepted work always completes.
 * - Limited activation (usesCoordinator): ROUTES a submission to the capability coordinator vs
 *   the existing verified path. It NEVER blocks service. When unrestricted, everyone uses the
 *   coordinator. When restricted, only the configured pilot user uses the coordinator and every
 *   other member keeps the existing path. An empty/invalid restricted id is fail-safe to the
 *   existing path for everyone (the coordinator opens for no one).
 *
 * No data is ever deleted here.
 */
final class MediaActivation
{
    public function assertNotPaused(): void
    {
        if ((bool) config('media.kill_switch', false)) {
            throw new ImageGenerationException('Media generation is temporarily paused. Jobs already in progress will finish.', 503);
        }
    }

    public function usesCoordinator(User $user): bool
    {
        if (! (bool) config('media.coordinator_restricted', false)) {
            return true;
        }
        $allowed = config('media.restricted_user_id');

        return $allowed !== null && (int) $allowed === (int) $user->id;
    }
}
