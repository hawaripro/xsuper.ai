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
        // Limited-activation smoke gate. When restricted, ONLY the configured test user may
        // submit; an empty/invalid id is fail-safe CLOSED for everyone (never opens the path).
        if ((bool) config('media.coordinator_restricted', false)) {
            $allowed = config('media.restricted_user_id');
            if ($allowed === null || (int) $allowed !== (int) $user->id) {
                throw new ImageGenerationException('This model is not available for your account yet.', 503);
            }
        }
    }
}
