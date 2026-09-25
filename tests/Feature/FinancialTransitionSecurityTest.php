<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\StorageUpgradeController;
use App\Models\AuditEvent;
use App\Models\DurationOrder;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\StorageUpgradeOrder;
use App\Models\User;
use App\Models\UserStorageUpgrade;
use App\Services\ReferralService;
use App\Services\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialTransitionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['referrals.enabled' => true, 'referrals.reward_days' => 3]);
    }

    private function durationOrder(User $user, string $package = '1_week', int $days = 7, int $price = 20000): DurationOrder
    {
        return DurationOrder::create(['user_id' => $user->id, 'package' => $package, 'days' => $days, 'price' => $price, 'status' => 'pending']);
    }

    private function storageOrder(User $user): StorageUpgradeOrder
    {
        return StorageUpgradeOrder::create([
            'user_id' => $user->id, 'plan_key' => 'plus_2gb', 'label' => '+2 GB', 'extra_bytes' => 2 * 1024 ** 3,
            'days' => 30, 'price' => 25000, 'status' => 'pending',
        ]);
    }

    /** A rejection request whose order was route-bound before a concurrent decision committed. */
    private function staleRejection(User $admin, string $uri): Request
    {
        $request = Request::create($uri, 'POST', ['note' => 'Stale rejection']);
        $request->setUserResolver(fn () => $admin);

        return $request;
    }

    public function test_stale_period_rejection_cannot_overwrite_an_approved_or_cancelled_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['expires_at' => now()->addDays(3)]);
        $expiryBeforeApproval = $member->expires_at->copy();
        $approved = $this->durationOrder($member);
        $boundBeforeApproval = DurationOrder::findOrFail($approved->id);
        $this->actingAs($admin)->postJson("/api/a/period/approve/{$approved->id}", ['note' => 'Payment verified'])->assertOk();
        $expiryAfterApproval = $member->fresh()->expires_at;
        $this->assertTrue($expiryBeforeApproval->addDays(7)->equalTo($expiryAfterApproval));
        $this->assertDatabaseCount('referral_rewards', 0);

        $this->assertThrows(
            fn () => app(PeriodController::class)->reject($this->staleRejection($admin, "/api/a/period/reject/{$approved->id}"), $boundBeforeApproval),
            ValidationException::class,
        );
        $this->assertDatabaseHas('duration_orders', ['id' => $approved->id, 'status' => 'approved', 'note' => 'Payment verified']);
        $this->assertTrue($expiryAfterApproval->equalTo($member->fresh()->expires_at));

        $cancelled = $this->durationOrder($member);
        $boundBeforeCancel = DurationOrder::findOrFail($cancelled->id);
        $this->actingAs($member)->postJson("/api/period/order/{$cancelled->id}/cancel")->assertOk();
        $cancellationNote = $cancelled->fresh()->note;

        $this->assertThrows(
            fn () => app(PeriodController::class)->reject($this->staleRejection($admin, "/api/a/period/reject/{$cancelled->id}"), $boundBeforeCancel),
            ValidationException::class,
        );
        $this->assertDatabaseHas('duration_orders', ['id' => $cancelled->id, 'status' => 'cancelled', 'note' => $cancellationNote]);

        $pending = $this->durationOrder($member);
        $this->actingAs($admin)->postJson("/api/a/period/reject/{$pending->id}", ['note' => 'Payment not received'])
            ->assertOk();
        $this->actingAs($admin)->postJson("/api/a/period/reject/{$pending->id}")
            ->assertUnprocessable();
        $this->assertDatabaseHas('duration_orders', ['id' => $pending->id, 'status' => 'rejected', 'note' => 'Payment not received']);
        $this->assertTrue($expiryAfterApproval->equalTo($member->fresh()->expires_at));
    }

    public function test_stale_storage_rejection_cannot_overwrite_an_approved_or_cancelled_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['expires_at' => now()->addDays(3)]);
        $approved = $this->storageOrder($member);
        $boundBeforeApproval = StorageUpgradeOrder::findOrFail($approved->id);
        $this->actingAs($admin)->postJson("/api/a/storage/orders/{$approved->id}/approve", ['note' => 'Payment verified'])->assertOk();
        $grant = UserStorageUpgrade::where('order_id', $approved->id)->sole();

        $this->assertThrows(
            fn () => app(StorageUpgradeController::class)->reject($this->staleRejection($admin, "/api/a/storage/orders/{$approved->id}/reject"), $boundBeforeApproval),
            ValidationException::class,
        );
        $this->assertDatabaseHas('storage_upgrade_orders', ['id' => $approved->id, 'status' => 'approved', 'note' => 'Payment verified']);
        $this->assertSame(1, UserStorageUpgrade::where('order_id', $approved->id)->count());
        $this->assertSame(2 * 1024 ** 3, app(StorageQuotaService::class)->activeUpgradeBytes($member));

        $cancelled = $this->storageOrder($member);
        $boundBeforeCancel = StorageUpgradeOrder::findOrFail($cancelled->id);
        $this->actingAs($member)->postJson("/api/storage/order/{$cancelled->id}/cancel")->assertOk();
        $cancellationNote = $cancelled->fresh()->note;

        $this->assertThrows(
            fn () => app(StorageUpgradeController::class)->reject($this->staleRejection($admin, "/api/a/storage/orders/{$cancelled->id}/reject"), $boundBeforeCancel),
            ValidationException::class,
        );
        $this->assertDatabaseHas('storage_upgrade_orders', ['id' => $cancelled->id, 'status' => 'cancelled', 'note' => $cancellationNote]);

        $pending = $this->storageOrder($member);
        $this->actingAs($admin)->postJson("/api/a/storage/orders/{$pending->id}/reject", ['note' => 'Payment not received'])
            ->assertOk();
        $this->actingAs($admin)->postJson("/api/a/storage/orders/{$pending->id}/reject")
            ->assertUnprocessable();
        $this->assertDatabaseHas('storage_upgrade_orders', ['id' => $pending->id, 'status' => 'rejected', 'note' => 'Payment not received']);
        $this->assertSame(1, UserStorageUpgrade::count());
        $this->assertTrue($grant->starts_at->equalTo($grant->fresh()->starts_at));
        $this->assertTrue($grant->expires_at->equalTo($grant->fresh()->expires_at));
        $this->assertSame(2 * 1024 ** 3, app(StorageQuotaService::class)->activeUpgradeBytes($member));
    }

    public function test_delayed_referral_release_rewards_the_first_paid_purchase_once_after_later_purchases(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 10:00:00'));
        $admin = User::factory()->create(['role' => 'admin']);
        $referrer = User::factory()->create(['expires_at' => now()->addDays(10)]);
        $referred = User::factory()->create(['expires_at' => now()->subDay()]);
        $referral = Referral::create([
            'referrer_id' => $referrer->id, 'referred_id' => $referred->id, 'code' => $referrer->referral_code,
            'status' => 'flagged', 'attributed_at' => now()->subWeek(), 'risk_level' => 'review', 'risk_reasons' => ['shared_ip'],
        ]);
        // Earlier manual and free grants are never the qualifying first purchase.
        DurationOrder::create(['user_id' => $referred->id, 'package' => 'manual', 'days' => 2, 'price' => 0, 'status' => 'approved', 'approved_at' => now()->subDays(2), 'approved_by' => $admin->id]);
        DurationOrder::create(['user_id' => $referred->id, 'package' => '1_day', 'days' => 1, 'price' => 0, 'status' => 'approved', 'approved_at' => now()->subDay(), 'approved_by' => $admin->id]);
        $first = $this->durationOrder($referred);
        $this->actingAs($admin)->postJson("/api/a/period/approve/{$first->id}")->assertOk();
        // The member had lapsed: approval time must not absorb the purchased period.
        $this->assertTrue(now()->equalTo($first->fresh()->approved_at));
        $this->travel(1)->hours();
        $second = $this->durationOrder($referred, '1_day', 1, 5000);
        $this->actingAs($admin)->postJson("/api/a/period/approve/{$second->id}")->assertOk();
        $this->assertSame('flagged', $referral->fresh()->status);
        $this->assertDatabaseCount('referral_rewards', 0);
        $referrerExpiry = $referrer->fresh()->expires_at;
        $referredExpiry = $referred->fresh()->expires_at;

        $this->actingAs($admin)->postJson("/api/admin/referrals/{$referral->id}/review", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('referral.status', 'qualified');

        $this->assertDatabaseCount('referral_rewards', 2);
        $this->assertDatabaseHas('referral_rewards', [
            'referral_id' => $referral->id, 'user_id' => $referrer->id, 'days' => 3,
            'reference' => "referral:{$referral->id}:first-purchase:referrer",
        ]);
        $this->assertDatabaseHas('referral_rewards', [
            'referral_id' => $referral->id, 'user_id' => $referred->id, 'days' => 3,
            'reference' => "referral:{$referral->id}:first-purchase:referred",
        ]);
        $this->assertSame($first->id, AuditEvent::where('action', 'referral.rewarded')->sole()->metadata['order_id']);
        $this->assertTrue($referrerExpiry->copy()->addDays(3)->equalTo($referrer->fresh()->expires_at));
        $this->assertTrue($referredExpiry->copy()->addDays(3)->equalTo($referred->fresh()->expires_at));

        $this->actingAs($admin)->postJson("/api/admin/referrals/{$referral->id}/review", ['decision' => 'approve'])
            ->assertUnprocessable();
        $referrals = app(ReferralService::class);
        $this->assertFalse($referrals->rewardFirstPurchase($first->fresh(), $admin, true));
        $this->assertFalse($referrals->rewardFirstPurchase($second->fresh(), $admin, true));
        $this->assertDatabaseCount('referral_rewards', 2);
        $this->assertSame(1, AuditEvent::where('action', 'referral.rewarded')->count());
        $this->assertTrue($referrerExpiry->copy()->addDays(3)->equalTo($referrer->fresh()->expires_at));
        $this->assertTrue($referredExpiry->copy()->addDays(3)->equalTo($referred->fresh()->expires_at));
    }

    public function test_a_later_or_tied_paid_order_cannot_qualify_instead_of_the_first_approved_purchase(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 10:00:00'));
        $admin = User::factory()->create(['role' => 'admin']);
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::create([
            'referrer_id' => $referrer->id, 'referred_id' => $referred->id, 'code' => $referrer->referral_code,
            'status' => 'attributed', 'attributed_at' => now()->subWeek(),
        ]);
        $paid = fn (Carbon $approvedAt): DurationOrder => DurationOrder::create([
            'user_id' => $referred->id, 'package' => '1_week', 'days' => 7, 'price' => 20000,
            'status' => 'approved', 'approved_at' => $approvedAt, 'approved_by' => $admin->id,
        ]);
        $approvedLater = $paid(now()->addHour()); // Lowest id, but approved last.
        $first = $paid(now());
        $tied = $paid(now()); // Same approval time; the higher id loses the tie.
        $referrals = app(ReferralService::class);

        $this->assertFalse($referrals->rewardFirstPurchase($approvedLater, $admin));
        $this->assertFalse($referrals->rewardFirstPurchase($tied, $admin));
        $this->assertSame('attributed', $referral->fresh()->status);
        $this->assertDatabaseCount('referral_rewards', 0);

        $this->assertTrue($referrals->rewardFirstPurchase($first, $admin));
        $this->assertSame('qualified', $referral->fresh()->status);
        $this->assertDatabaseCount('referral_rewards', 2);
        $this->assertSame($first->id, AuditEvent::where('action', 'referral.rewarded')->sole()->metadata['order_id']);
    }

    public function test_order_approval_and_duration_are_rolled_back_when_referral_awarding_fails(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $referrer = User::factory()->create(['expires_at' => now()->addDays(10)]);
        $buyer = User::factory()->create(['expires_at' => now()->addDays(2)]);
        $buyerExpiry = $buyer->expires_at->copy();
        $referrerExpiry = $referrer->expires_at->copy();
        $referral = Referral::create([
            'referrer_id' => $referrer->id, 'referred_id' => $buyer->id, 'code' => $referrer->referral_code,
            'status' => 'attributed', 'attributed_at' => now()->subWeek(),
        ]);
        ReferralReward::create([
            'referral_id' => $referral->id, 'user_id' => $referrer->id, 'kind' => 'duration', 'days' => 3,
            'reference' => "referral:{$referral->id}:first-purchase:referrer", 'awarded_at' => now(),
        ]);
        $order = $this->durationOrder($buyer);
        $request = Request::create("/api/a/period/approve/{$order->id}", 'POST');
        $request->setUserResolver(fn () => $admin);
        $this->actingAs($admin);

        $this->assertThrows(
            fn () => app(PeriodController::class)->approve($request, $order, app(ReferralService::class)),
            \LogicException::class,
        );

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->approved_at);
        $this->assertSame('attributed', $referral->fresh()->status);
        $this->assertTrue($buyerExpiry->equalTo($buyer->fresh()->expires_at));
        $this->assertTrue($referrerExpiry->equalTo($referrer->fresh()->expires_at));
        $this->assertDatabaseCount('referral_rewards', 1);
        $this->assertDatabaseMissing('audit_events', ['action' => 'referral.rewarded']);
    }
}
