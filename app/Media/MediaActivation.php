<?php

namespace App\Media;

use App\Exceptions\ImageGenerationException;
use App\Models\User;

/**
 * Server-side gate for NEW capability-driven submissions. Enforces the kill switch and
 * limited-activation (single test user) for a bounded production smoke test. Only NEW
 * submissions call this; in-flight jobs (process/poll) never do, so accepted work always
 * completes. No data is ever deleted here.
 */
final class MediaActivation
{
    public function assertCanSubmit(User $user): void
    {
        if ((bool) config('media.kill_switch', false)) {
            throw new ImageGenerationException('Media generation is temporarily paused. Jobs already in progress will finish.', 503);
        }
        $restricted = config('media.restricted_user_id');
        if ($restricted !== null && (int) $restricted !== (int) $user->id) {
            throw new ImageGenerationException('This model is not available for your account yet.', 503);
        }
    }
}
