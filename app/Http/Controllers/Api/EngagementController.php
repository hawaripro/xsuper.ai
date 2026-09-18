<?php

namespace App\Http\Controllers\Api;

use App\Events\NotificationChanged;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EngagementController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function broadcast(Request $request): JsonResponse
    {
        $admin = $this->adminUser($request);
        $validated = $request->validate([
            'segment' => ['required', Rule::in(['all', 'active', 'expired'])],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:10000'],
            'action_url' => ['bail', 'sometimes', 'nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null) {
                    return;
                }

                $decoded = rawurldecode($value);
                $path = parse_url($decoded, PHP_URL_PATH);
                if (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//')
                    || str_starts_with($decoded, '/en//') || preg_match('/[\\\\\x00-\x20\x7f]/', $decoded)
                    || ! is_string($path) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
                    $fail('The action URL must be a local application path.');
                }
            }],
        ]);

        $broadcastId = (string) Str::uuid();
        $recipientCount = DB::transaction(function () use ($admin, $validated, $broadcastId): int {
            $count = 0;
            $now = now();
            $metadata = json_encode([
                'broadcast_id' => $broadcastId,
                'segment' => $validated['segment'],
            ], JSON_THROW_ON_ERROR);

            $this->recipients($validated['segment'])
                ->select('id')
                ->orderBy('id')
                ->chunkById(500, function ($recipients) use (&$count, $validated, $metadata, $now): void {
                    $rows = [];
                    foreach ($recipients as $recipient) {
                        $rows[] = [
                            'user_id' => $recipient->id,
                            'kind' => 'broadcast',
                            'title' => $validated['title'],
                            'body' => $validated['body'],
                            'action_url' => $validated['action_url'] ?? null,
                            'metadata' => $metadata,
                            'read_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    Notification::query()->insert($rows);
                    foreach ($recipients as $recipient) {
                        event(new NotificationChanged((int) $recipient->id, 'created'));
                    }
                    $count += count($rows);
                });

            $this->audit->record($admin, 'engagement.broadcast.created', null, [
                'broadcast_id' => $broadcastId,
                'segment' => $validated['segment'],
                'recipient_count' => $count,
                'title' => $validated['title'],
            ]);

            return $count;
        });

        return response()->json([
            'data' => [
                'id' => $broadcastId,
                'segment' => $validated['segment'],
                'recipient_count' => $recipientCount,
            ],
        ], 201);
    }

    private function recipients(string $segment): Builder
    {
        $query = User::query()->where('role', 'member');

        if ($segment === 'active') {
            $query->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
        } elseif ($segment === 'expired') {
            $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
        }

        return $query;
    }

    private function adminUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');
        abort_unless($user->isAdmin(), 403, 'Forbidden.');

        return $user;
    }
}
