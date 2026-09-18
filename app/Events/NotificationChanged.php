<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class NotificationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    public bool $afterCommit = true;

    public function __construct(
        public readonly int $userId,
        public readonly string $reason,
        public readonly ?int $notificationId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'notification_id' => $this->notificationId,
        ];
    }

    public function broadcastWhen(): bool
    {
        return config('broadcasting.default') === 'reverb';
    }
}
