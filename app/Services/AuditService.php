<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(User $actor, string $action, ?Model $subject = null, array $metadata = []): AuditEvent
    {
        $request = request();

        return AuditEvent::create([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
