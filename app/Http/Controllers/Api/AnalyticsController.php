<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\DurationOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public const EVENTS = [
        'app.opened',
        'onboarding.completed',
        'chat.started',
        'image.requested',
        'video.requested',
        'checkout.started',
        'order.submitted',
    ];

    private const PROPERTY_KEYS = [
        'app.opened' => ['source'],
        'onboarding.completed' => ['mode', 'source'],
        'chat.started' => ['model', 'source'],
        'image.requested' => ['model', 'source'],
        'video.requested' => ['model', 'source'],
        'checkout.started' => ['package', 'source'],
        'order.submitted' => ['package', 'source'],
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', Rule::in(self::EVENTS)],
            'session_id' => ['sometimes', 'nullable', 'string', 'max:80'],
            'path' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'properties' => ['sometimes', 'nullable', 'array'],
        ]);

        $properties = collect($validated['properties'] ?? [])
            ->only(self::PROPERTY_KEYS[$validated['name']])
            ->filter(static fn (mixed $value): bool => is_string($value) || is_int($value) || is_bool($value))
            ->map(static fn (mixed $value): mixed => is_string($value) ? mb_substr($value, 0, 160) : $value)
            ->all();

        $event = AnalyticsEvent::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'session_id' => isset($validated['session_id']) ? mb_substr($validated['session_id'], 0, 80) : null,
            'properties' => $properties,
            'path' => $this->normalizedPath($validated['path'] ?? null),
        ]);

        return response()->json(['data' => $event], 201);
    }

    public function funnel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', Rule::in([7, 30, 90, 365])],
        ]);
        $days = (int) ($validated['days'] ?? 30);
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $eventQuery = AnalyticsEvent::query()->whereBetween('created_at', [$from, $to]);
        $orderQuery = DurationOrder::query()->whereBetween('created_at', [$from, $to]);

        $eventCounts = (clone $eventQuery)
            ->select('name', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('name')
            ->pluck('aggregate', 'name')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $totalUsers = User::query()->where('role', 'member')->count();
        $newUsers = User::query()->where('role', 'member')->whereBetween('created_at', [$from, $to])->count();
        $uniqueEventUsers = (clone $eventQuery)->whereNotNull('user_id')->distinct()->count('user_id');
        $orders = (clone $orderQuery)->count();
        $approvedOrders = (clone $orderQuery)->where('status', 'approved')->count();
        $revenue = (clone $orderQuery)->where('status', 'approved')->sum('price');

        return response()->json([
            'period' => [
                'days' => $days,
                'from' => $from->toISOString(),
                'to' => $to->toISOString(),
            ],
            'counts' => [
                'events' => (clone $eventQuery)->count(),
                'unique_event_users' => $uniqueEventUsers,
                'total_users' => $totalUsers,
                'new_users' => $newUsers,
                'orders' => $orders,
                'approved_orders' => $approvedOrders,
                'revenue_idr' => (int) $revenue,
            ],
            'event_counts' => $eventCounts,
            'funnel' => [
                'registered_users' => $newUsers,
                'engaged_users' => $uniqueEventUsers,
                'orders_created' => $orders,
                'orders_approved' => $approvedOrders,
            ],
        ]);
    }

    private function normalizedPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $parsed = parse_url($path);
        if ($parsed === false) {
            return null;
        }

        $normalized = $parsed['path'] ?? '/';

        return str_starts_with($normalized, '/') ? mb_substr($normalized, 0, 255) : null;
    }
}
