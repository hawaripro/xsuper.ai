<?php

namespace App\Http\Controllers\Api;

use App\Events\NotificationChanged;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
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
        $userId = (int) $request->user()->getAuthIdentifier();
        abort_unless((int) $notification->user_id === $userId, 403);

        $notification = DB::transaction(function () use ($notification, $userId): Notification {
            $record = Notification::query()->where('user_id', $userId)
                ->lockForUpdate()->findOrFail($notification->id);
            if ($record->read_at === null) {
                $record->forceFill(['read_at' => now()])->save();
            }

            return $record;
        });

        return response()->json([
            'notification' => $this->notificationData($notification),
            'unread_count' => Notification::query()->where('user_id', $userId)->whereNull('read_at')->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $updatedCount = DB::transaction(function () use ($userId): int {
            $now = now();
            $updated = Notification::query()
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->update([
                    'read_at' => $now,
                    'updated_at' => $now,
                ]);

            // Query-builder updates deliberately bypass model observers.
            if ($updated > 0) {
                event(new NotificationChanged($userId, 'read_all'));
            }

            return $updated;
        });

        return response()->json([
            'updated_count' => $updatedCount,
            'unread_count' => Notification::query()->where('user_id', $userId)->whereNull('read_at')->count(),
        ]);
    }

    private function notificationData(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'kind' => $notification->kind,
            'title' => $notification->title,
            'body' => $notification->body,
            'action_url' => $this->safeActionUrl($notification->action_url),
            'metadata' => $notification->metadata ?? [],
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }

    private function safeActionUrl(?string $value): ?string
    {
        if ($value === null || ! str_starts_with($value, '/')) {
            return null;
        }

        $decoded = rawurldecode($value);
        $path = parse_url($decoded, PHP_URL_PATH);
        if (str_starts_with($decoded, '//') || str_starts_with($decoded, '/en//')
            || preg_match('/[\\\\\x00-\x20\x7f]/', $decoded)
            || ! is_string($path) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            return null;
        }

        return $value;
    }
}
