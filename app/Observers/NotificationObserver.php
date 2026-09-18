<?php

namespace App\Observers;

use App\Events\NotificationChanged;
use App\Models\Notification;

final class NotificationObserver
{
    public function created(Notification $notification): void
    {
        event(new NotificationChanged((int) $notification->user_id, 'created', (int) $notification->id));
    }

    public function updated(Notification $notification): void
    {
        if (! $notification->wasChanged(['user_id', 'kind', 'title', 'body', 'action_url', 'metadata', 'read_at'])) {
            return;
        }

        $reason = $notification->wasChanged('read_at') ? 'read' : 'updated';
        event(new NotificationChanged((int) $notification->user_id, $reason, (int) $notification->id));

        if ($notification->wasChanged('user_id')) {
            event(new NotificationChanged((int) $notification->getRawOriginal('user_id'), 'updated'));
        }
    }
}
