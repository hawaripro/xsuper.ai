<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiProviderProfile;
use App\Models\DurationOrder;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\StudioLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    private const USAGE_PERIODS = ['hourly', '7d', 'daily', 'weekly', 'monthly', 'all'];

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $userId = (int) $user->getAuthIdentifier();

        $usage = $this->usageSummary(UsageLog::query()->where('user_id', $userId));
        $wallet = $this->walletSummary($userId);
        $orders = $this->orderSummary($userId);
        $devices = $this->deviceSummary($userId);
        $conversations = $this->conversationSummary($userId);

        return response()->json([
            'account' => [
                'id' => $userId,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => (bool) $user->is_active,
                'expires_at' => $this->timestamp($user->expires_at),
                'is_expired' => $user->isExpired(),
                'days_remaining' => $user->daysRemaining(),
                'created_at' => $this->timestamp($user->created_at),
            ],
            'membership' => $user->membershipSummary(),
            'usage' => $usage,
            'wallet' => $wallet,
            'activity' => [
                'conversation_count' => $conversations['count'],
                'orders' => $orders,
                'devices' => $devices,
                'recent' => $conversations['recent'],
            ],
            'services' => $this->services(),
            'actions' => $this->actions($user),
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['sometimes', 'string', Rule::in(self::USAGE_PERIODS)],
        ]);
        $period = $validated['period'] ?? 'daily';
        $from = $this->periodStart($period);

        $query = UsageLog::query()
            ->where('user_id', (int) $request->user()->getAuthIdentifier());

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        $summary = $this->usageSummary(clone $query);
        $bucket = $this->usageBucketSql($period);

        $timeline = (clone $query)
            ->selectRaw("{$bucket} as bucket, COUNT(*) as requests, COALESCE(SUM(total_tokens), 0) as tokens, COALESCE(SUM(credit), 0) as credits, COALESCE(SUM(cost_microusd), 0) as cost_microusd")
            ->groupByRaw($bucket)
            ->orderByDesc('bucket')
            ->limit(120)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (UsageLog $row): array => [
                'period' => (string) $row->getAttribute('bucket'),
                'requests' => (int) $row->getAttribute('requests'),
                'tokens' => (int) $row->getAttribute('tokens'),
                'credits' => round((float) $row->getAttribute('credits'), 4),
                'cost_microusd' => (int) $row->getAttribute('cost_microusd'),
            ]);

        $byModel = (clone $query)
            ->selectRaw('model, COUNT(*) as requests, COALESCE(SUM(total_tokens), 0) as tokens, COALESCE(SUM(credit), 0) as credits, COALESCE(SUM(cost_microusd), 0) as cost_microusd')
            ->groupBy('model')
            ->orderByDesc('tokens')
            ->orderBy('model')
            ->limit(100)
            ->get()
            ->map(fn (UsageLog $row): array => [
                'model' => $row->model,
                'requests' => (int) $row->getAttribute('requests'),
                'tokens' => (int) $row->getAttribute('tokens'),
                'credits' => round((float) $row->getAttribute('credits'), 4),
                'cost_microusd' => (int) $row->getAttribute('cost_microusd'),
            ]);

        return response()->json([
            'period' => $period,
            'from' => $from?->toISOString(),
            ...$summary,
            'timeline' => $timeline,
            'by_model' => $byModel,
        ]);
    }

    private function usageSummary(Builder $query): array
    {
        $summary = $query
            ->selectRaw('COUNT(*) as total_requests, COALESCE(SUM(total_tokens), 0) as total_tokens, COALESCE(SUM(credit), 0) as total_credits, COALESCE(SUM(cost_microusd), 0) as total_cost_microusd, MAX(created_at) as last_used_at')
            ->first();

        return [
            'total_requests' => (int) ($summary?->getAttribute('total_requests') ?? 0),
            'total_tokens' => (int) ($summary?->getAttribute('total_tokens') ?? 0),
            'total_credits' => round((float) ($summary?->getAttribute('total_credits') ?? 0), 4),
            'total_cost_microusd' => (int) ($summary?->getAttribute('total_cost_microusd') ?? 0),
            'last_used_at' => $this->timestamp($summary?->getAttribute('last_used_at')),
        ];
    }

    private function walletSummary(int $userId): array
    {
        $balance = Wallet::query()->where('user_id', $userId)->value('balance_microusd');
        $transactions = WalletTransaction::query()
            ->where('user_id', $userId)
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(CASE WHEN amount_microusd > 0 THEN amount_microusd ELSE 0 END), 0) as total_credits_microusd, COALESCE(SUM(CASE WHEN amount_microusd < 0 THEN -amount_microusd ELSE 0 END), 0) as total_debits_microusd, MAX(created_at) as last_transaction_at')
            ->first();

        return [
            'balance_microusd' => (int) ($balance ?? 0),
            'transaction_count' => (int) ($transactions?->getAttribute('transaction_count') ?? 0),
            'total_credits_microusd' => (int) ($transactions?->getAttribute('total_credits_microusd') ?? 0),
            'total_debits_microusd' => (int) ($transactions?->getAttribute('total_debits_microusd') ?? 0),
            'last_transaction_at' => $this->timestamp($transactions?->getAttribute('last_transaction_at')),
        ];
    }

    private function orderSummary(int $userId): array
    {
        $orders = DurationOrder::query()
            ->where('user_id', $userId)
            ->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending, COALESCE(SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END), 0) as approved, COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END), 0) as rejected, MAX(created_at) as last_order_at")
            ->first();

        return [
            'total' => (int) ($orders?->getAttribute('total') ?? 0),
            'pending' => (int) ($orders?->getAttribute('pending') ?? 0),
            'approved' => (int) ($orders?->getAttribute('approved') ?? 0),
            'rejected' => (int) ($orders?->getAttribute('rejected') ?? 0),
            'last_order_at' => $this->timestamp($orders?->getAttribute('last_order_at')),
        ];
    }

    private function deviceSummary(int $userId): array
    {
        $devices = UserDevice::query()
            ->where('user_id', $userId)
            ->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active, COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending, COALESCE(SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END), 0) as blocked, MAX(last_active_at) as last_active_at")
            ->first();

        return [
            'total' => (int) ($devices?->getAttribute('total') ?? 0),
            'active' => (int) ($devices?->getAttribute('active') ?? 0),
            'pending' => (int) ($devices?->getAttribute('pending') ?? 0),
            'blocked' => (int) ($devices?->getAttribute('blocked') ?? 0),
            'last_active_at' => $this->timestamp($devices?->getAttribute('last_active_at')),
        ];
    }

    private function conversationSummary(int $userId): array
    {
        $history = DB::table('chat_history')->where('user_id', $userId)->where('conversation_id', '<>', '')
            ->select('conversation_id')->selectRaw('MAX(created_at) as last_message')->groupBy('conversation_id');
        // Chat metadata owns renamed titles; its tombstone hides a deleted conversation even if a stray message survives.
        $conversations = DB::query()->fromSub($history, 'history')
            ->leftJoin('chat_conversations as metadata', function (JoinClause $join) use ($userId): void {
                $join->on('metadata.conversation_key', '=', 'history.conversation_id')->where('metadata.user_id', '=', $userId);
            })
            ->whereNull('metadata.deleted_at');
        $count = (clone $conversations)->count();

        $recent = $conversations
            ->select('history.conversation_id', 'history.last_message', 'metadata.title as metadata_title', 'metadata.title_is_custom')
            ->selectSub(DB::table('chat_history as first_message')->selectRaw('SUBSTR(first_message.content, 1, 160)')
                ->where('first_message.user_id', $userId)->whereColumn('first_message.conversation_id', 'history.conversation_id')
                ->where('first_message.role', 'user')->orderBy('first_message.created_at')->orderBy('first_message.id')->limit(1), 'first_message')
            ->selectSub(DB::table('chat_history as latest_message')->select('latest_message.model')
                ->where('latest_message.user_id', $userId)->whereColumn('latest_message.conversation_id', 'history.conversation_id')
                ->whereNotNull('latest_message.model')->orderByDesc('latest_message.created_at')->orderByDesc('latest_message.id')->limit(1), 'model')
            ->orderByDesc('history.last_message')->orderBy('history.conversation_id')
            ->limit(8)
            ->get()
            ->map(function (object $conversation): array {
                $title = $conversation->title_is_custom ? (string) $conversation->metadata_title : trim((string) $conversation->first_message);

                return [
                    'id' => $conversation->conversation_id,
                    'type' => 'conversation',
                    'title' => $title !== '' ? mb_substr($title, 0, 50) : 'New Chat',
                    'model' => $conversation->model,
                    'occurred_at' => $this->timestamp($conversation->last_message),
                ];
            })
            ->values();

        return ['count' => $count, 'recent' => $recent];
    }

    private function services(): array
    {
        return AiProviderProfile::query()
            ->orderBy('name')
            ->orderBy('id')
            ->limit(100)
            ->get(['slug', 'name', 'status', 'is_enabled', 'last_checked_at'])
            ->map(fn (AiProviderProfile $provider): array => [
                'key' => $provider->slug,
                'name' => $provider->name,
                'status' => $provider->status,
                'is_enabled' => (bool) $provider->is_enabled,
                'last_checked_at' => $this->timestamp($provider->last_checked_at),
            ])
            ->values()
            ->all();
    }

    private function actions(User $user): array
    {
        $actions = [];
        // Same rule as EnsureActive: only an explicit false is inactive (an unloaded column is null).
        // Membership expiry never locks tools; usage is gated by permission and balance.
        $hasAccess = $user->is_active !== false;

        if ($hasAccess && $user->hasPermission('chat')) {
            $actions[] = ['key' => 'chat', 'label' => 'Chat AI', 'href' => '/chat'];
        }
        if ($hasAccess) {
            $actions[] = ['key' => 'library', 'label' => 'Library', 'href' => '/library'];
        }
        if ($hasAccess && $user->hasPermission('video_generator')) {
            $actions[] = ['key' => 'video', 'label' => 'Video generator', 'href' => StudioLink::to('video')];
        }
        if ($hasAccess && $user->hasPermission('ai_api')) {
            $actions[] = ['key' => 'api', 'label' => 'API XSuper.ai', 'href' => 'https://api.xsuper.dev'];
        }

        $actions[] = ['key' => 'usage', 'label' => 'Pemakaian', 'href' => '/token-usage'];
        $actions[] = ['key' => 'extend', 'label' => 'Deposit dan langganan', 'href' => '/deposit'];

        return $actions;
    }

    private function periodStart(string $period): ?Carbon
    {
        return match ($period) {
            'hourly' => now()->subHours(23)->startOfHour(),
            '7d' => now()->subDays(6)->startOfDay(),
            'daily' => now()->subDays(29)->startOfDay(),
            'weekly' => now()->subWeeks(11)->startOfWeek(),
            'monthly' => now()->subMonths(11)->startOfMonth(),
            'all' => null,
        };
    }

    private function usageBucketSql(string $period): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => match ($period) {
                'hourly' => <<<'SQL'
                    TO_CHAR(DATE_TRUNC('hour', created_at), 'YYYY-MM-DD"T"HH24:00:00"Z"')
                    SQL,
                'daily', '7d' => "TO_CHAR(created_at, 'YYYY-MM-DD')",
                'weekly' => <<<'SQL'
                    TO_CHAR(created_at, 'IYYY-"W"IW')
                    SQL,
                default => "TO_CHAR(created_at, 'YYYY-MM')",
            },
            default => match ($period) {
                'hourly' => "strftime('%Y-%m-%dT%H:00:00Z', created_at)",
                'daily', '7d' => "strftime('%Y-%m-%d', created_at)",
                'weekly' => "strftime('%Y-W%W', created_at)",
                default => "strftime('%Y-%m', created_at)",
            },
        };
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toISOString();
    }
}
