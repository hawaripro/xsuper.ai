<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DurationOrder;
use App\Models\DurationPackagePrice;
use App\Models\PricingSetting;
use App\Models\User;
use App\Models\UserStorageUpgrade;
use App\Models\UserToken;
use App\Models\Wallet;
use App\Services\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MembershipBenefitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['referrals.enabled' => false]);
        $this->travelTo(now()->startOfSecond());
    }

    private function purchase(User $buyer, string $package = '1_month'): DurationOrder
    {
        $checkout = $this->actingAs($buyer)->postJson('/api/period/checkout', ['package' => $package])->assertCreated();
        $id = $this->postJson('/api/period/order', [
            'package' => $package, 'payment_reference' => $checkout->json('checkout.payment_reference'),
        ])->assertCreated()->json('order.id');

        return DurationOrder::findOrFail($id);
    }

    public function test_approval_grants_order_snapshots_once_and_cannot_be_deleted(): void
    {
        $buyer = User::factory()->create(['expires_at' => now()->addDays(4)]);
        $admin = User::factory()->create(['role' => 'admin']);
        $expectedEnd = $buyer->expires_at->copy()->addDays(30);
        $order = $this->purchase($buyer);
        $this->assertSame(400, (int) $order->bonus_tokens);
        $this->assertSame(1_500_000, (int) $order->bonus_wallet_microusd);
        DurationPackagePrice::where('package', '1_month')->update([
            'bonus_tokens' => 900, 'bonus_wallet_microusd' => 9_000_000, 'storage_bytes' => 10 * 1024 ** 3,
        ]);

        $this->actingAs($admin)->postJson("/api/a/period/approve/{$order->id}")->assertOk();
        $this->assertSame(400, UserToken::getBalance($buyer->id));
        $this->assertSame(1_500_000, Wallet::balance($buyer->id));
        $grant = UserStorageUpgrade::where('duration_order_id', $order->id)->sole();
        $this->assertSame(2 * 1024 ** 3, $grant->extra_bytes);
        $this->assertTrue($grant->expires_at->equalTo($expectedEnd));
        $this->assertTrue($buyer->fresh()->expires_at->equalTo($expectedEnd));
        $this->assertDatabaseHas('wallet_transactions', ['reference_id' => "duration-order:{$order->id}", 'type' => 'membership', 'amount_microusd' => 1_500_000]);
        $this->assertDatabaseHas('token_transactions', ['reference_id' => "duration-order:{$order->id}", 'amount' => 400]);
        $this->assertSame(400, AuditEvent::where('action', 'membership.approved')->sole()->metadata['bonus_tokens']);

        $this->postJson("/api/a/period/approve/{$order->id}")->assertUnprocessable();
        app(StorageQuotaService::class)->grantMembershipStorage($buyer, $order, $expectedEnd->copy()->addMonth());
        $this->assertSame(400, UserToken::getBalance($buyer->id));
        $this->assertSame(1_500_000, Wallet::balance($buyer->id));
        $this->assertSame(1, UserStorageUpgrade::where('duration_order_id', $order->id)->count());
        $this->assertTrue($grant->fresh()->expires_at->equalTo($expectedEnd));
        $this->deleteJson("/api/a/period/{$order->id}")->assertStatus(409);
        $this->assertDatabaseHas('duration_orders', ['id' => $order->id, 'status' => 'approved']);
    }

    public function test_renewal_uses_largest_active_membership_storage_plus_all_other_addons(): void
    {
        $buyer = User::factory()->create(['expires_at' => null]);
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->purchase($buyer);
        $this->actingAs($admin)->postJson("/api/a/period/approve/{$first->id}")->assertOk();
        $second = $this->purchase($buyer, '1_week');
        $this->actingAs($admin)->postJson("/api/a/period/approve/{$second->id}")->assertOk();
        $quota = app(StorageQuotaService::class);
        $quota->grantUpgrade($buyer, 'extra-a', 3 * 1024 ** 3, 60);
        $quota->grantUpgrade($buyer, 'extra-b', 4 * 1024 ** 3, 60);
        $this->assertSame($quota->baseBytes() + 9 * 1024 ** 3, $quota->quotaBytes($buyer));
        $this->travel(31)->days();
        $this->assertSame($quota->baseBytes() + 8 * 1024 ** 3, $quota->quotaBytes($buyer));
        $this->travel(7)->days();
        $this->assertSame($quota->baseBytes() + 7 * 1024 ** 3, $quota->quotaBytes($buyer));
        $this->assertSame(550, UserToken::getBalance($buyer->id));
        $this->assertSame(2_000_000, Wallet::balance($buyer->id));
    }

    public function test_manual_duration_and_zero_benefit_orders_grant_days_only(): void
    {
        $buyer = User::factory()->create(['expires_at' => null]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/a/period/add-duration', ['user_id' => $buyer->id, 'days' => 7])->assertOk();
        $this->assertTrue($buyer->fresh()->expires_at->equalTo(now()->addDays(7)));
        DurationPackagePrice::where('package', '1_day')->update(['bonus_tokens' => 0, 'bonus_wallet_microusd' => 0, 'storage_bytes' => 0]);
        $order = $this->purchase($buyer, '1_day');
        $this->actingAs($admin)->postJson("/api/a/period/approve/{$order->id}")->assertOk();
        $this->assertSame(0, UserToken::getBalance($buyer->id));
        $this->assertSame(0, Wallet::balance($buyer->id));
        $this->assertDatabaseCount('user_storage_upgrades', 0);
        $this->assertTrue($buyer->fresh()->expires_at->equalTo(now()->addDays(8)));
    }

    public function test_benefit_writes_validate_convert_and_audit_and_member_catalogs_expose_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create();
        $this->actingAs($admin)->patchJson('/api/pricing/durations/bulk', ['items' => [[
            'package' => '1_month', 'bonus_tokens' => 425, 'bonus_wallet_usd' => '1.75', 'storage_gb' => '2.5',
        ]]])->assertOk()->assertJsonPath('duration_packages.1_month.bonus_wallet_usd', '1.75');
        $this->assertDatabaseHas('duration_package_prices', ['package' => '1_month', 'bonus_tokens' => 425, 'bonus_wallet_microusd' => 1_750_000, 'storage_bytes' => (int) (2.5 * 1024 ** 3)]);
        $audit = AuditEvent::where('action', 'pricing.duration.updated')->sole();
        $this->assertSame(425, (int) $audit->metadata['after']['bonus_tokens']);
        $this->patchJson('/api/pricing/durations/bulk', ['items' => [[
            'package' => '1_month', 'bonus_tokens' => 1_000_001, 'bonus_wallet_usd' => 1001, 'storage_gb' => 1025,
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.bonus_tokens', 'items.0.bonus_wallet_usd', 'items.0.storage_gb']);
        PricingSetting::current()->update(['wallet_idr_per_usd' => 20000]);
        $this->getJson('/api/pricing/settings')->assertOk()->assertJsonPath('valuation.wallet_idr_per_usd', 20000);
        foreach (['/api/period/packages' => 'packages', '/api/deposits/catalog' => 'duration_packages'] as $url => $key) {
            $this->actingAs($member)->getJson($url)->assertOk()
                ->assertJsonPath("{$key}.1_month.bonus_tokens", 425)
                ->assertJsonPath("{$key}.1_month.bonus_wallet_microusd", 1_750_000)
                ->assertJsonPath("{$key}.1_month.bonus_wallet_usd", '1.75')
                ->assertJsonPath("{$key}.1_month.storage_gb", 2.5);
        }
    }

    public function test_repeated_wallet_credit_is_idempotent_but_distinct_types_and_null_references_still_credit(): void
    {
        $buyer = User::factory()->create();
        Wallet::credit($buyer->id, 1_500_000, 'Membership', 'duration-order:test', 'membership');
        Wallet::credit($buyer->id, 1_500_000, 'Repeated membership', 'duration-order:test', 'membership');
        $this->assertSame(1_500_000, Wallet::balance($buyer->id));
        Wallet::credit($buyer->id, 250_000, 'Different type', 'duration-order:test', 'credit');
        Wallet::credit($buyer->id, 100_000, 'No reference');
        Wallet::credit($buyer->id, 100_000, 'No reference');
        $this->assertSame(1_950_000, Wallet::balance($buyer->id));
        $this->assertThrows(fn () => Wallet::credit($buyer->id, 9_000_000, 'Changed amount', 'duration-order:test', 'membership'), \InvalidArgumentException::class);
        $this->assertSame(1_950_000, Wallet::balance($buyer->id));
    }

    public function test_null_expiry_means_no_membership_and_admin_cannot_assign_unlimited(): void
    {
        $member = User::factory()->create(['expires_at' => null]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($member)->getJson('/api/user')->assertOk()
            ->assertJsonPath('membership.active', false)
            ->assertJsonPath('membership.expires_at', null)
            ->assertJsonPath('membership.days_remaining', null);
        $body = ['name' => $member->name, 'email' => $member->email, 'role' => 'member', 'duration' => 'unlimited'];
        $this->actingAs($admin)->putJson('/api/a/u/'.$member->id, $body)->assertUnprocessable()->assertJsonValidationErrors('duration');
        $this->postJson('/api/a/u', [...$body, 'email' => 'new-member@example.test', 'password' => 'ValidPassword123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('duration');
        $member->update(['expires_at' => now()->addMonth()]);
        $this->putJson('/api/a/u/'.$member->id, [...$body, 'duration' => 'clear'])->assertOk();
        $users = $this->getJson('/api/a/u')->assertOk()->json('users');
        $this->assertSame(['active' => false, 'expires_at' => null, 'days_remaining' => null], collect($users)->firstWhere('id', $member->id)['membership']);
        $this->assertSame(0, UserToken::getBalance($member->id));
        $this->assertSame(0, Wallet::balance($member->id));
    }

    public function test_failed_grant_rolls_back_membership_and_can_be_retried_without_duplicate_credit(): void
    {
        $buyer = User::factory()->create(['expires_at' => null]);
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->purchase($buyer);
        UserToken::topup($buyer->id, 2_147_483_600);
        $this->actingAs($admin)->postJson("/api/a/period/approve/{$order->id}")->assertServerError();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($buyer->fresh()->expires_at);
        $this->assertSame(0, Wallet::balance($buyer->id));
        $this->assertDatabaseCount('user_storage_upgrades', 0);
        UserToken::deduct($buyer->id, 1000);
        $this->postJson("/api/a/period/approve/{$order->id}")->assertOk();
        $this->assertSame(1_500_000, Wallet::balance($buyer->id));
        $this->assertSame(1, DB::table('wallet_transactions')->where('reference_id', "duration-order:{$order->id}")->count());
    }
}
