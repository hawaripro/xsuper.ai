<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $userId = (int) $request->user()->getAuthIdentifier();
        $query = Notification::query()->where('user_id', $userId);
        $unreadCount = (clone $query)->whereNull('read_at')->count();
        $notifications = $query
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'notifications' => $notifications->getCollection()
                ->map(fn (Notification $notification): array => $this->notificationData($notification))
                ->values(),
            'unread_count' => $unreadCount,
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === (int) $request->user()->getAuthIdentifier(), 403);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'notification' => $this->notificationData($notification->fresh()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $now = now();
        $userId = (int) $request->user()->getAuthIdentifier();
        $updatedCount = Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update([
                'read_at' => $now,
                'updated_at' => $now,
            ]);

        return response()->json([
            'updated_count' => $updatedCount,
            'unread_count' => 0,
        ]);
    }

    private function notificationData(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'kind' => $notification->kind,
            'title' => $notification->title,
            'body' => $notification->body,
            'action_url' => $notification->action_url,
            'metadata' => $notification->metadata ?? [],
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }
}
