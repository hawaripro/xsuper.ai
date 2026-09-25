<?php

namespace Tests\Feature;

use App\Models\DepositOrder;
use App\Models\DurationOrder;
use App\Models\TokenReservation;
use App\Models\UsageLog;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\UserToken;
use App\Models\Wallet;
use App\Services\MediaTokenBillingService;
use App\Services\UsageBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUsageRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_usage_earnings_count_settled_costs_and_tokens_without_deposits_pending_work_or_refunds(): void
    {
        Carbon::setTestNow('2026-08-31 23:59:59');
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create();
        Wallet::credit($admin->id, 50_000_000, 'Approved wallet deposit');
        Wallet::credit($member->id, 10_000_000, 'opening balance');
        UserToken::topup($admin->id, 1000, 'Approved token deposit');
        UserToken::topup($member->id, 500, 'opening balance');
        foreach (['model-a', 'model-b'] as $model) {
            foreach (['input_tokens' => 1, 'output_tokens' => 2] as $meter => $price) {
                UsageRate::create([
                    'service' => 'api', 'meter' => $meter, 'model' => $model, 'label' => $meter,
                    'unit' => '1M tokens', 'price_idr' => 16000 * $price, 'price_usd' => $price, 'is_active' => true,
                ]);
            }
        }
        $api = app(UsageBillingService::class);
        $media = app(MediaTokenBillingService::class);

        $oldApi = $api->reserveApi($admin->id, 'model-a', 1_000_000, 1_000_000, 'august-api');
        $this->recordApiSettlement($admin, 'model-a', $oldApi, 1_000_000, 1_000_000);
        $oldVideo = $media->reserve($admin, 'video', 'video-a', 1, 'august-video', 200);
        $media->settle($admin->id, $oldVideo);
        $septemberApi = $api->reserveApi($admin->id, 'model-a', 1_000_000, 1_000_000, 'september-api');
        $septemberImage = $media->reserve($admin, 'image', 'image-a', 2, 'september-image', 15);

        Carbon::setTestNow('2026-09-01 00:00:00');
        $this->recordApiSettlement($admin, 'model-a', $septemberApi, 500_000, 250_000);
        $media->settle($admin->id, $septemberImage);

        Carbon::setTestNow('2026-09-10 12:00:00');
        $memberApi = $api->reserveApi($member->id, 'model-b', 1_000_000, 1_000_000, 'member-api');
        $this->recordApiSettlement($member, 'model-b', $memberApi, 125_000, 125_000);
        $memberVideo = $media->reserve($member, 'video', 'video-a', 1, 'member-video', 200);
        $media->settle($member->id, $memberVideo);

        Carbon::setTestNow('2026-09-17 12:00:00');
        $api->reserveApi($admin->id, 'model-a', 1_000_000, 1_000_000, 'pending-api');
        $releasedApi = $api->reserveApi($admin->id, 'model-a', 1_000_000, 1_000_000, 'released-api');
        Wallet::release($admin->id, $releasedApi, 'provider failed');
        $media->reserve($admin, 'image', 'image-a', 3, 'pending-image', 15);
        $releasedVideo = $media->reserve($admin, 'video', 'video-a', 1, 'released-video', 200);
        $media->release($admin->id, $releasedVideo, 'cancelled before submission');
        TokenReservation::create([
            'user_id' => $admin->id, 'reference_id' => 'historical-free-admin',
            'service' => 'video', 'model' => 'video-a', 'quantity' => 99, 'unit_tokens' => 0,
            'amount_tokens' => 0, 'billing_mode' => 'admin', 'status' => 'settled', 'settled_at' => now(),
        ]);
        $chat = $api->reserveApi($member->id, 'model-b', 100_000, 50_000, 'web-chat', 'chat');
        $chatUsage = ['prompt_tokens' => 100_000, 'completion_tokens' => 50_000, 'total_tokens' => 150_000];
        $chatCost = $api->settleApi($member->id, 'model-b', $chatUsage, $chat, 'chat');
        UsageLog::record($member->id, 'model-b', [...$chatUsage, 'cost_microusd' => $chatCost], 'web');
        UsageLog::record($admin->id, 'admin-chat', ['total_tokens' => 100_000], 'web');
        UsageLog::record($admin->id, 'models', ['cost_microusd' => 0], 'api');
        DepositOrder::create([
            'user_id' => $admin->id, 'payment_reference' => (string) Str::uuid(),
            'kind' => 'wallet', 'amount_idr' => 800_000, 'credit_microusd' => 50_000_000,
            'idr_per_usd' => 16_000, 'status' => 'approved', 'approved_at' => now(), 'expires_at' => now()->addHour(),
        ]);
        DepositOrder::create([
            'user_id' => $admin->id, 'payment_reference' => (string) Str::uuid(),
            'kind' => 'tokens', 'amount_idr' => 100_000, 'base_tokens' => 1000, 'total_tokens' => 1000,
            'idr_per_usd' => 16_000, 'status' => 'approved', 'approved_at' => now(), 'expires_at' => now()->addHour(),
        ]);
        DurationOrder::create([
            'user_id' => $member->id, 'package' => '1_month', 'days' => 30, 'price' => 50_000,
            'status' => 'approved', 'approved_at' => now(),
        ]);
        DurationOrder::create([
            'user_id' => $member->id, 'package' => '1_week', 'days' => 7, 'price' => 20_000,
            'status' => 'approved', 'approved_at' => Carbon::parse('2026-08-20 12:00:00'),
        ]);

        $this->actingAs($admin)->getJson('/api/a/stats/revenue')->assertOk()
            ->assertJsonPath('revenue_this_month', 50_000)
            ->assertJsonPath('revenue_last_month', 20_000)
            ->assertJsonPath('total_revenue', 70_000)
            ->assertJsonPath('usage_earnings.month', '2026-09')
            ->assertJsonPath('usage_earnings.payg.month_cost_microusd', 1_575_000)
            ->assertJsonPath('usage_earnings.payg.total_cost_microusd', 4_575_000)
            ->assertJsonPath('usage_earnings.payg.by_model', [
                ['service' => 'api', 'model' => 'model-a', 'cost_microusd' => 1_000_000, 'requests' => 1],
                ['service' => 'api', 'model' => 'model-b', 'cost_microusd' => 375_000, 'requests' => 1],
                ['service' => 'chat', 'model' => 'model-b', 'cost_microusd' => 200_000, 'requests' => 1],
            ])
            ->assertJsonPath('usage_earnings.generators.month_tokens', 230)
            ->assertJsonPath('usage_earnings.generators.total_tokens', 430)
            ->assertJsonPath('usage_earnings.generators.by_model', [
                ['service' => 'video', 'model' => 'video-a', 'tokens' => 200, 'generations' => 1],
                ['service' => 'image', 'model' => 'image-a', 'tokens' => 30, 'generations' => 2],
            ]);

        $this->getJson('/api/a/stats/revenue?month=2026-08')->assertOk()
            ->assertJsonPath('revenue_this_month', 50_000)
            ->assertJsonPath('total_revenue', 70_000)
            ->assertJsonPath('usage_earnings.month', '2026-08')
            ->assertJsonPath('usage_earnings.payg.month_cost_microusd', 3_000_000)
            ->assertJsonPath('usage_earnings.payg.total_cost_microusd', 4_575_000)
            ->assertJsonPath('usage_earnings.payg.by_model', [
                ['service' => 'api', 'model' => 'model-a', 'cost_microusd' => 3_000_000, 'requests' => 1],
            ])
            ->assertJsonPath('usage_earnings.generators.month_tokens', 200)
            ->assertJsonPath('usage_earnings.generators.total_tokens', 430)
            ->assertJsonPath('usage_earnings.generators.by_model', [
                ['service' => 'video', 'model' => 'video-a', 'tokens' => 200, 'generations' => 1],
            ]);
    }

    public function test_month_with_no_settled_earnings_returns_zero_amounts_and_no_model_rows(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        UserToken::topup($admin->id, 100, 'opening balance');
        $media = app(MediaTokenBillingService::class);
        $reservation = $media->reserve($admin, 'image', 'image-a', 2, 'current-month', 15);
        $media->settle($admin->id, $reservation);

        $this->actingAs($admin)->getJson('/api/a/stats/revenue?month=2026-08')->assertOk()
            ->assertJsonPath('usage_earnings.payg.month_cost_microusd', 0)
            ->assertJsonPath('usage_earnings.payg.total_cost_microusd', 0)
            ->assertJsonPath('usage_earnings.payg.by_model', [])
            ->assertJsonPath('usage_earnings.generators.month_tokens', 0)
            ->assertJsonPath('usage_earnings.generators.total_tokens', 30)
            ->assertJsonPath('usage_earnings.generators.by_model', []);
    }

    public function test_invalid_usage_month_is_rejected_instead_of_silently_reading_another_period(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->getJson('/api/a/stats/revenue?month=2026-13')
            ->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    private function recordApiSettlement(User $user, string $model, array $reservation, int $input, int $output): void
    {
        $usage = ['prompt_tokens' => $input, 'completion_tokens' => $output, 'total_tokens' => $input + $output];
        $cost = app(UsageBillingService::class)->settleApi($user->id, $model, $usage, $reservation);
        UsageLog::record($user->id, $model, [...$usage, 'cost_microusd' => $cost], 'api');
    }
}
