<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Models\AiProviderProfile;
use App\Models\DurationOrder;
use App\Models\Notification;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MemberControlCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app['router'];
        $applicationRoutes = iterator_to_array($router->getRoutes());
        $router->setRoutes(new RouteCollection());

        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::get('/api/dashboard', [DashboardController::class, 'show']);
            Route::get('/api/usage/me', [DashboardController::class, 'usage']);
            Route::get('/api/notifications', [NotificationController::class, 'index']);
            Route::patch('/api/notifications/{notification}/read', [NotificationController::class, 'markRead']);
            Route::post('/api/notifications/read-all', [NotificationController::class, 'markAllRead']);
        });

        foreach ($applicationRoutes as $route) {
            $router->getRoutes()->add($route);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_returns_truthful_member_only_aggregates(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $member = User::factory()->create([
            'permissions' => [
                ...User::DEFAULT_PERMISSIONS,
                'chat' => true,
                'chat_history' => true,
                'video_generator' => false,
                'ai_api' => false,
            ],
            'expires_at' => now()->addDays(10),
        ]);
        $other = User::factory()->create();

        $this->usage($member, 'chat-alpha', 120, 1.25, 250, now()->subDay());
        $this->usage($member, 'chat-beta', 80, 0.75, 150, now());
        $this->usage($other, 'private-model', 9999, 99, 9999, now());

        Wallet::create(['user_id' => $member->id, 'balance_microusd' => 1_500_000]);
        Wallet::create(['user_id' => $other->id, 'balance_microusd' => 9_000_000]);
        WalletTransaction::create([
            'user_id' => $member->id,
            'type' => 'topup',
            'amount_microusd' => 2_000_000,
            'balance_after_microusd' => 2_000_000,
        ]);
        WalletTransaction::create([
            'user_id' => $member->id,
            'type' => 'usage',
            'amount_microusd' => -500_000,
            'balance_after_microusd' => 1_500_000,
        ]);
        WalletTransaction::create([
            'user_id' => $other->id,
            'type' => 'topup',
            'amount_microusd' => 9_000_000,
            'balance_after_microusd' => 9_000_000,
        ]);

        DurationOrder::create([
            'user_id' => $member->id,
            'package' => '1_month',
            'days' => 30,
            'price' => 55_000,
            'status' => 'approved',
        ]);
        DurationOrder::create([
            'user_id' => $member->id,
            'package' => '1_week',
            'days' => 7,
            'price' => 20_000,
            'status' => 'pending',
        ]);
        DurationOrder::create([
            'user_id' => $other->id,
            'package' => '12_months',
            'days' => 365,
            'price' => 499_000,
            'status' => 'rejected',
        ]);

        DB::table('chat_history')->insert([
            'user_id' => $member->id,
            'conversation_id' => 'member-conversation',
            'role' => 'user',
            'content' => 'Member conversation',
            'model' => 'chat-alpha',
            'created_at' => now(),
        ]);
        DB::table('chat_history')->insert([
            'user_id' => $other->id,
            'conversation_id' => 'other-private-conversation',
            'role' => 'user',
            'content' => 'Other private conversation',
            'model' => 'private-model',
            'created_at' => now(),
        ]);

        UserDevice::create([
            'user_id' => $member->id,
            'device_hash' => str_repeat('a', 64),
            'device_name' => 'Member browser',
            'device_type' => 'browser',
            'status' => 'active',
            'last_active_at' => now(),
        ]);
        UserDevice::create([
            'user_id' => $other->id,
            'device_hash' => str_repeat('b', 64),
            'device_name' => 'Other browser',
            'device_type' => 'browser',
            'status' => 'pending',
            'last_active_at' => now(),
        ]);

        AiProviderProfile::create([
            'slug' => 'truthful-provider',
            'name' => 'Truthful Provider',
            'status' => 'degraded',
            'is_enabled' => true,
            'last_checked_at' => now()->subMinute(),
        ]);
        AiProviderProfile::create([
            'slug' => 'disabled-provider',
            'name' => 'Disabled Provider',
            'status' => 'online',
            'is_enabled' => false,
        ]);

        $this->actingAs($member);
        $response = $this->getJson('/api/dashboard');


        $response->assertOk()
            ->assertJsonStructure([
                'account' => ['id', 'name', 'email', 'role', 'is_active', 'expires_at', 'is_expired', 'days_remaining', 'created_at'],
                'usage' => ['total_requests', 'total_tokens', 'total_credits', 'total_cost_microusd', 'last_used_at'],
                'wallet' => ['balance_microusd', 'transaction_count', 'total_credits_microusd', 'total_debits_microusd', 'last_transaction_at'],
                'activity' => [
                    'conversation_count',
                    'orders' => ['total', 'pending', 'approved', 'rejected', 'last_order_at'],
                    'devices' => ['total', 'active', 'pending', 'blocked', 'last_active_at'],
                    'recent' => [['id', 'type', 'title', 'model', 'occurred_at']],
                ],
                'services' => [['key', 'name', 'status', 'is_enabled', 'last_checked_at']],
                'actions' => [['key', 'label', 'href']],
            ])
            ->assertJsonPath('account.id', $member->id)
            ->assertJsonPath('usage.total_requests', 2)
            ->assertJsonPath('usage.total_tokens', 200)
            ->assertJsonPath('usage.total_credits', 2)
            ->assertJsonPath('usage.total_cost_microusd', 400)
            ->assertJsonPath('wallet.balance_microusd', 1_500_000)
            ->assertJsonPath('wallet.transaction_count', 2)
            ->assertJsonPath('wallet.total_credits_microusd', 2_000_000)
            ->assertJsonPath('wallet.total_debits_microusd', 500_000)
            ->assertJsonPath('activity.conversation_count', 1)
            ->assertJsonPath('activity.orders.total', 2)
            ->assertJsonPath('activity.orders.pending', 1)
            ->assertJsonPath('activity.orders.approved', 1)
            ->assertJsonPath('activity.orders.rejected', 0)
            ->assertJsonPath('activity.devices.total', 2)
            ->assertJsonPath('activity.devices.active', 2)
            ->assertJsonPath('activity.devices.pending', 0)
            ->assertJsonPath('activity.recent.0.id', 'member-conversation')
            ->assertJsonPath('services.0.key', 'disabled-provider')
            ->assertJsonPath('services.0.status', 'online')
            ->assertJsonPath('services.0.is_enabled', false)
            ->assertJsonPath('services.1.key', 'truthful-provider')
            ->assertJsonPath('services.1.status', 'degraded')
            ->assertJsonMissing(['title' => 'Other private conversation'])
            ->assertJsonMissing(['key' => 'private-model']);
    }

    public function test_dashboard_uses_real_empty_states_instead_of_fabricated_service_health(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('usage.total_requests', 0)
            ->assertJsonPath('usage.total_tokens', 0)
            ->assertJsonPath('wallet.balance_microusd', 0)
            ->assertJsonPath('activity.conversation_count', 0)
            ->assertJsonPath('activity.orders.total', 0)
            ->assertJsonPath('activity.recent', [])
            ->assertJsonPath('services', []);
    }

    public function test_usage_endpoint_is_period_bounded_and_cannot_be_switched_to_another_user(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $member = User::factory()->create();
        $other = User::factory()->create();

        $this->usage($member, 'alpha', 100, 1.5, 150, now()->subDay());
        $this->usage($member, 'beta', 250, 2.5, 350, now());
        $this->usage($member, 'old', 500, 5, 500, now()->subDays(40));
        $this->usage($other, 'private-model', 9999, 99, 9999, now());

        $response = $this->actingAs($member)->getJson("/api/usage/me?period=daily&user_id={$other->id}");

        $response->assertOk()
            ->assertJsonPath('period', 'daily')
            ->assertJsonPath('total_requests', 2)
            ->assertJsonPath('total_tokens', 350)
            ->assertJsonPath('total_credits', 4)
            ->assertJsonPath('total_cost_microusd', 500)
            ->assertJsonCount(2, 'timeline')
            ->assertJsonCount(2, 'by_model')
            ->assertJsonMissing(['model' => 'private-model'])
            ->assertJsonMissing(['model' => 'old']);

        $this->actingAs($member)->getJson('/api/usage/me?period=quarterly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
    }

    public function test_notification_inbox_is_paginated_and_scoped_with_an_accurate_unread_count(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        Notification::create([
            'user_id' => $member->id,
            'kind' => 'account',
            'title' => 'Read member notice',
            'body' => 'Already read',
            'read_at' => now(),
        ]);
        Notification::create([
            'user_id' => $member->id,
            'kind' => 'billing',
            'title' => 'Unread member notice one',
            'body' => 'Unread one',
        ]);
        Notification::create([
            'user_id' => $member->id,
            'kind' => 'system',
            'title' => 'Unread member notice two',
            'body' => 'Unread two',
        ]);
        Notification::create([
            'user_id' => $other->id,
            'kind' => 'private',
            'title' => 'Other private notice',
            'body' => 'Must not leak',
        ]);

        $this->actingAs($member)->getJson('/api/notifications?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonMissing(['title' => 'Other private notice']);
    }

    public function test_notification_read_operations_enforce_ownership_and_only_touch_current_user(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $first = Notification::create([
            'user_id' => $member->id,
            'kind' => 'system',
            'title' => 'First',
            'body' => 'First body',
        ]);
        $second = Notification::create([
            'user_id' => $member->id,
            'kind' => 'system',
            'title' => 'Second',
            'body' => 'Second body',
        ]);
        $otherNotification = Notification::create([
            'user_id' => $other->id,
            'kind' => 'private',
            'title' => 'Other',
            'body' => 'Other body',
        ]);

        $this->actingAs($member)->patchJson("/api/notifications/{$first->id}/read")
            ->assertOk()
            ->assertJsonPath('notification.id', $first->id);
        $this->assertNotNull($first->fresh()->read_at);

        $this->actingAs($member)->patchJson("/api/notifications/{$otherNotification->id}/read")
            ->assertForbidden();
        $this->assertNull($otherNotification->fresh()->read_at);

        $this->actingAs($member)->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_member_control_center_endpoints_require_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/usage/me')->assertUnauthorized();
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->patchJson('/api/notifications/1/read')->assertUnauthorized();
        $this->postJson('/api/notifications/read-all')->assertUnauthorized();
    }

    private function usage(
        User $user,
        string $model,
        int $tokens,
        float $credits,
        int $costMicrousd,
        Carbon $createdAt,
    ): void {
        $usage = UsageLog::create([
            'user_id' => $user->id,
            'model' => $model,
            'source' => 'web',
            'prompt_tokens' => $tokens,
            'completion_tokens' => 0,
            'total_tokens' => $tokens,
            'credit' => $credits,
            'cost_microusd' => $costMicrousd,
        ]);
        $usage->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }
}
