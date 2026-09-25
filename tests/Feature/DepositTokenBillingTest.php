<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DepositOrder;
use App\Models\PricingSetting;
use App\Models\TokenPackage;
use App\Models\TokenReservation;
use App\Models\TokenTransaction;
use App\Models\User;
use App\Models\UserToken;
use App\Models\Wallet;
use App\Services\MediaTokenBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class DepositTokenBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_exposes_persistent_owner_priced_packages_without_invented_savings(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/deposits/catalog')->assertOk();

        $this->assertSame([
            ['tokens_100', 100, 0, 100, 12000],
            ['tokens_320', 300, 20, 320, 29000],
            ['tokens_750', 700, 50, 750, 69000],
            ['tokens_1650', 1500, 150, 1650, 149000],
            ['tokens_4500', 4000, 500, 4500, 399000],
        ], collect($response->json('token_packages'))->map(fn (array $package): array => [
            $package['code'],
            $package['base_tokens'],
            $package['bonus_tokens'],
            $package['total_tokens'],
            $package['price_idr'],
        ])->all());
        $this->assertArrayNotHasKey('savings', $response->json('token_packages.1'));
        $this->assertDatabaseCount('token_packages', 5);
    }

    public function test_catalog_reflects_owner_package_updates_without_mutating_other_defaults(): void
    {
        $user = User::factory()->create();
        TokenPackage::where('code', 'tokens_100')->update([
            'price_idr' => 13000,
            'is_active' => false,
        ]);
        TokenPackage::where('code', 'tokens_320')->update(['price_idr' => 30000]);

        $packages = $this->actingAs($user)->getJson('/api/deposits/catalog')
            ->assertOk()->json('token_packages');

        $this->assertSame(['tokens_320', 'tokens_750', 'tokens_1650', 'tokens_4500'], array_column($packages, 'code'));
        $this->assertSame(30000, $packages[0]['price_idr']);
        $this->assertSame(69000, $packages[1]['price_idr']);
    }

    public function test_admin_can_manage_packages_without_changing_package_identity_or_last_active_invariant(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $package = TokenPackage::where('code', 'tokens_320')->firstOrFail();
        $catalogPackage = collect($this->actingAs($admin)->getJson('/api/admin/token-packages')->assertOk()->json('token_packages'))
            ->firstWhere('code', 'tokens_320');
        $catalogId = $catalogPackage['id'] ?? 0;

        $this->actingAs($admin)->patchJson("/api/admin/token-packages/{$catalogId}", [
            'base_tokens' => 300,
            'bonus_tokens' => 25,
            'price_idr' => 30000,
        ])->assertOk()
            ->assertJsonPath('token_package.code', 'tokens_320')
            ->assertJsonPath('token_package.total_tokens', 325);

        TokenPackage::whereKeyNot($package->id)->update(['is_active' => false]);
        $this->actingAs($admin)->patchJson("/api/admin/token-packages/{$package->id}", [
            'is_active' => false,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('token_packages', ['id' => $package->id, 'is_active' => true]);
    }

    public function test_token_checkout_uses_an_immutable_package_snapshot_and_confirmation_never_credits_early(): void
    {
        $user = User::factory()->create();
        $checkout = $this->actingAs($user)->postJson('/api/deposits/checkout', [
            'kind' => 'tokens',
            'package_code' => 'tokens_320',
        ])->assertCreated()->json('checkout');

        $package = TokenPackage::where('code', 'tokens_320')->firstOrFail();
        $package->update([
            'price_idr' => 999999,
            'base_tokens' => 1,
            'bonus_tokens' => 0,
        ]);

        $this->actingAs($user)->postJson('/api/deposits', [
            'payment_reference' => $checkout['payment_reference'],
            'amount_idr' => 1,
            'total_tokens' => 999999,
            'credit_microusd' => 999999999,
        ])->assertCreated()
            ->assertJsonPath('order.amount_idr', 29000)
            ->assertJsonPath('order.total_tokens', 320)
            ->assertJsonPath('order.status', 'pending');

        $this->assertSame(0, UserToken::getBalance($user->id));
        $this->assertDatabaseHas('deposit_orders', [
            'user_id' => $user->id,
            'payment_reference' => $checkout['payment_reference'],
            'amount_idr' => 29000,
            'total_tokens' => 320,
            'status' => 'pending',
        ]);
    }

    public function test_checkout_reference_is_same_user_scoped_single_use_and_expiring(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $checkout = $this->actingAs($owner)->postJson('/api/deposits/checkout', [
            'kind' => 'wallet',
            'amount_idr' => 16000,
        ])->assertCreated()->json('checkout');

        $this->actingAs($other)->postJson('/api/deposits', [
            'payment_reference' => $checkout['payment_reference'],
        ])->assertUnprocessable();

        $this->actingAs($owner)->postJson('/api/deposits', [
            'payment_reference' => $checkout['payment_reference'],
        ])->assertCreated();
        $this->actingAs($owner)->postJson('/api/deposits', [
            'payment_reference' => $checkout['payment_reference'],
        ])->assertUnprocessable();

        $expired = $this->actingAs($owner)->postJson('/api/deposits/checkout', [
            'kind' => 'tokens',
            'package_code' => 'tokens_100',
        ])->assertCreated()->json('checkout');
        DepositOrder::where('payment_reference', $expired['payment_reference'])
            ->update(['expires_at' => now()->subSecond()]);

        $this->actingAs($owner)->postJson('/api/deposits', [
            'payment_reference' => $expired['payment_reference'],
        ])->assertUnprocessable();
    }

    public function test_wallet_checkout_validates_integer_limits_and_snapshots_the_pricing_wallet_rate(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        PricingSetting::current()->update(['wallet_idr_per_usd' => 16000]);
        $this->actingAs($user)->getJson('/api/deposits/catalog')->assertOk()->assertJsonPath('conversion.idr_per_usd', 16000);

        $this->actingAs($user)->postJson('/api/deposits/checkout', [
            'kind' => 'wallet',
            'amount_idr' => 9999,
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/deposits/checkout', [
            'kind' => 'wallet',
            'amount_idr' => 10000001,
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/deposits/checkout', [
            'kind' => 'wallet',
            'amount_idr' => 16000.5,
        ])->assertUnprocessable();

        $checkout = $this->actingAs($user)->postJson('/api/deposits/checkout', [
            'kind' => 'wallet',
            'amount_idr' => 29000,
        ])->assertCreated()
            ->assertJsonPath('checkout.idr_per_usd', 16000)
            ->assertJsonPath('checkout.credit_microusd', 1812500)
            ->json('checkout');

        // A new wallet rate prices new checkouts only; the confirmed checkout keeps and credits its snapshot.
        PricingSetting::current()->update(['wallet_idr_per_usd' => 20000]);
        $orderId = $this->actingAs($user)->postJson('/api/deposits', [
            'payment_reference' => $checkout['payment_reference'],
        ])->assertCreated()
            ->assertJsonPath('order.idr_per_usd', 16000)
            ->assertJsonPath('order.credit_microusd', 1812500)
            ->json('order.id');
        $this->actingAs($user)->getJson('/api/deposits/catalog')->assertOk()->assertJsonPath('conversion.idr_per_usd', 20000);
        $this->actingAs($user)->postJson('/api/deposits/checkout', [
            'kind' => 'wallet',
            'amount_idr' => 29000,
        ])->assertCreated()
            ->assertJsonPath('checkout.idr_per_usd', 20000)
            ->assertJsonPath('checkout.credit_microusd', 1450000);

        $this->actingAs($admin)->postJson("/api/admin/deposits/{$orderId}/approve")->assertOk();
        $this->assertSame(1812500, Wallet::balance($user->id));
    }

    public function test_admin_approval_credits_snapshot_exactly_once_and_audits_idempotent_replay(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $checkout = $this->actingAs($member)->postJson('/api/deposits/checkout', [
            'kind' => 'tokens',
            'package_code' => 'tokens_750',
        ])->assertCreated()->json('checkout');
        $orderId = $this->actingAs($member)->postJson('/api/deposits', [
            'payment_reference' => $checkout['payment_reference'],
        ])->assertCreated()->json('order.id');

        $this->actingAs($admin)->postJson("/api/admin/deposits/{$orderId}/approve", ['note' => 'verified'])
            ->assertOk()->assertJsonPath('order.status', 'approved');
        $this->actingAs($admin)->postJson("/api/admin/deposits/{$orderId}/approve", ['note' => 'replayed callback'])
            ->assertOk()->assertJsonPath('order.status', 'approved');

        $this->assertSame(750, UserToken::getBalance($member->id));
        $this->assertSame(1, TokenTransaction::where('user_id', $member->id)
            ->where('type', 'topup')->where('reference_id', "deposit-order:{$orderId}")->count());
        $this->assertSame(2, AuditEvent::query()
            ->where('subject_type', DepositOrder::class)
            ->where('subject_id', $orderId)
            ->whereIn('action', ['deposit.approved', 'deposit.approval_replayed'])
            ->count());
    }

    public function test_wallet_approval_credits_once_while_rejection_never_credits(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $walletOrder = $this->confirmedOrder($member, ['kind' => 'wallet', 'amount_idr' => 16000]);
        $this->actingAs($admin)->postJson("/api/admin/deposits/{$walletOrder}/approve")->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/deposits/{$walletOrder}/approve")->assertOk();
        $this->assertSame(1_000_000, Wallet::balance($member->id));

        $tokenOrder = $this->confirmedOrder($member, ['kind' => 'tokens', 'package_code' => 'tokens_100']);
        $this->actingAs($admin)->postJson("/api/admin/deposits/{$tokenOrder}/reject", ['note' => 'not received'])
            ->assertOk()->assertJsonPath('order.status', 'rejected');
        $this->actingAs($admin)->postJson("/api/admin/deposits/{$tokenOrder}/reject", ['note' => 'duplicate'])
            ->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/deposits/{$tokenOrder}/approve")
            ->assertUnprocessable();
        $this->assertSame(0, UserToken::getBalance($member->id));
    }

    public function test_history_and_status_are_owner_scoped_and_include_resumable_checkout(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $checkout = $this->actingAs($owner)->postJson('/api/deposits/checkout', [
            'kind' => 'tokens', 'package_code' => 'tokens_100',
        ])->assertCreated()->json('checkout');

        $this->actingAs($owner)->getJson('/api/deposits?status=checkout')
            ->assertOk()->assertJsonPath('orders.0.payment_reference', $checkout['payment_reference'])
            ->assertJsonPath('orders.0.status', 'checkout');
        $this->actingAs($owner)->getJson('/api/deposits/'.$checkout['id'])
            ->assertOk()->assertJsonPath('order.qr_image_url', '/assets/payments/qris-xsuper.png');
        $this->actingAs($other)->getJson('/api/deposits/'.$checkout['id'])->assertNotFound();
        $image = getimagesize(public_path('assets/payments/qris-xsuper.png'));
        $this->assertIsArray($image);
        $this->assertSame('image/png', $image['mime']);
    }

    public function test_matching_checkout_reuses_reference_while_different_snapshot_expires_the_old_checkout(): void
    {
        $user = User::factory()->create();
        $first = $this->actingAs($user)->postJson('/api/deposits/checkout', [
            'kind' => 'tokens', 'package_code' => 'tokens_100',
        ])->assertCreated()->json('checkout');
        $same = $this->actingAs($user)->postJson('/api/deposits/checkout', [
            'kind' => 'tokens', 'package_code' => 'tokens_100',
        ])->assertCreated()->json('checkout');
        $replacement = $this->actingAs($user)->postJson('/api/deposits/checkout', [
            'kind' => 'wallet', 'amount_idr' => 16000,
        ])->assertCreated()->json('checkout');

        $this->assertSame($first['payment_reference'], $same['payment_reference']);
        $this->assertNotSame($first['payment_reference'], $replacement['payment_reference']);
        $this->assertSame('wallet', $replacement['kind']);
        $this->assertSame('expired', DepositOrder::findOrFail($first['id'])->displayStatus());
        $this->assertSame(0, UserToken::getBalance($user->id));
        $this->assertSame(0, Wallet::balance($user->id));
    }

    public function test_member_reservation_is_linear_reference_idempotent_and_uses_stored_snapshot_for_terminal_state(): void
    {
        $user = User::factory()->create();
        UserToken::topup($user->id, 100, 'opening balance');
        $billing = app(MediaTokenBillingService::class);

        $reservation = $billing->reserve($user, 'image', 'model-a', 2, 'job-1', 15);
        $duplicate = $billing->reserve($user, 'image', 'model-a', 2, 'job-1', 999);
        $reservation['amount_tokens'] = 1;
        $duplicate['amount_tokens'] = 9999;
        $billing->settle($user->id, $reservation, ['provider_job_id' => 'remote-1']);
        $billing->settle($user->id, $duplicate, ['provider_job_id' => 'remote-1']);
        $billing->release($user->id, $reservation, 'late failure callback');

        $this->assertSame(70, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenTransaction::where('user_id', $user->id)->where('type', 'deduct')->count());
        $this->assertDatabaseHas('token_reservations', [
            'user_id' => $user->id,
            'reference_id' => 'job-1',
            'quantity' => 2,
            'unit_tokens' => 15,
            'amount_tokens' => 30,
            'status' => 'settled',
        ]);
    }

    public function test_retrying_reserve_after_terminal_state_returns_original_snapshot_without_new_debit(): void
    {
        $user = User::factory()->create();
        UserToken::topup($user->id, 100, 'opening balance');
        $billing = app(MediaTokenBillingService::class);

        $reservation = $billing->reserve($user, 'video', 'model-v', 1, 'retry-job', 20);
        $billing->settle($user->id, $reservation);
        $retry = $billing->reserve($user, 'video', 'model-v', 1, 'retry-job', 999);

        $this->assertSame($reservation, $retry);
        $this->assertSame(80, UserToken::getBalance($user->id));
        $this->assertSame(1, TokenTransaction::where('user_id', $user->id)->where('type', 'deduct')->count());
    }

    public function test_reservation_reference_scope_is_per_user_and_release_refunds_only_once(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        UserToken::topup($first->id, 50, 'opening balance');
        UserToken::topup($second->id, 50, 'opening balance');
        $billing = app(MediaTokenBillingService::class);

        $firstReservation = $billing->reserve($first, 'image', 'model-a', 1, 'shared-ref', 15);
        $billing->reserve($second, 'image', 'model-a', 1, 'shared-ref', 15);
        $billing->release($first->id, $firstReservation, 'generation failed');
        $billing->release($first->id, $firstReservation, 'duplicate callback');

        $this->assertSame(50, UserToken::getBalance($first->id));
        $this->assertSame(35, UserToken::getBalance($second->id));
        $this->assertSame(1, TokenTransaction::where('user_id', $first->id)->where('type', 'refund')->count());
    }

    public function test_insufficient_balance_rolls_back_without_reservation_or_partial_debit(): void
    {
        $user = User::factory()->create();
        UserToken::topup($user->id, 29, 'opening balance');

        try {
            app(MediaTokenBillingService::class)->reserve($user, 'image', 'model-a', 2, 'insufficient', 15);
            $this->fail('Expected insufficient token validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tokens', $exception->errors());
        }

        $this->assertSame(29, UserToken::getBalance($user->id));
        $this->assertDatabaseMissing('token_reservations', ['reference_id' => 'insufficient']);
    }

    public function test_admin_media_reservation_charges_the_stored_cost_once_and_does_not_refund_settled_work(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        UserToken::topup($admin->id, 1000, 'opening balance');
        $billing = app(MediaTokenBillingService::class);

        $reservation = $billing->reserve($admin, 'video', 'model-v', 3, 'admin-job', 200);
        $duplicate = $billing->reserve($admin, 'video', 'model-v', 3, 'admin-job', 999);
        $billing->settle($admin->id, $reservation, ['provider_job_id' => 'remote-admin']);
        $billing->settle($admin->id, $duplicate);
        $billing->release($admin->id, $reservation, 'ignored late failure');

        $this->assertSame(400, UserToken::getBalance($admin->id));
        $this->assertSame(1, TokenTransaction::where('user_id', $admin->id)->where('type', 'deduct')->count());
        $this->assertDatabaseHas('token_transactions', [
            'user_id' => $admin->id, 'type' => 'deduct', 'amount' => 600,
        ]);
        $this->assertDatabaseMissing('token_transactions', ['user_id' => $admin->id, 'type' => 'refund']);
        $this->assertDatabaseHas('token_reservations', [
            'user_id' => $admin->id,
            'reference_id' => 'admin-job',
            'unit_tokens' => 200,
            'amount_tokens' => 600,
            'status' => 'settled',
            'billing_mode' => 'tokens',
        ]);
    }

    public function test_admin_insufficient_tokens_refuses_reservation_without_partial_debit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        UserToken::topup($admin->id, 29, 'opening balance');

        try {
            app(MediaTokenBillingService::class)->reserve($admin, 'image', 'model-a', 2, 'admin-insufficient', 15);
            $this->fail('Expected insufficient token validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tokens', $exception->errors());
        }

        $this->assertSame(29, UserToken::getBalance($admin->id));
        $this->assertDatabaseMissing('token_reservations', ['reference_id' => 'admin-insufficient']);
        $this->assertDatabaseMissing('token_transactions', ['user_id' => $admin->id, 'type' => 'deduct']);
    }

    public function test_releasing_admin_media_refunds_only_the_original_reservation_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        UserToken::topup($admin->id, 100, 'opening balance');
        $billing = app(MediaTokenBillingService::class);
        $reservation = $billing->reserve($admin, 'image', 'model-a', 2, 'admin-release', 15);

        $this->assertSame(70, UserToken::getBalance($admin->id));
        $reservation['amount_tokens'] = 9999;
        $billing->release($admin->id, $reservation, 'generation failed');
        $billing->release($admin->id, $reservation, 'duplicate callback');
        $billing->settle($admin->id, $reservation);

        $this->assertSame(100, UserToken::getBalance($admin->id));
        $this->assertSame(1, TokenTransaction::where('user_id', $admin->id)->where('type', 'refund')->count());
        $this->assertDatabaseHas('token_transactions', [
            'user_id' => $admin->id, 'type' => 'refund', 'amount' => 30,
        ]);
        $this->assertDatabaseHas('token_reservations', [
            'reference_id' => 'admin-release', 'amount_tokens' => 30, 'status' => 'released',
        ]);
    }

    public function test_new_admin_media_cannot_reserve_an_unpriced_model(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        UserToken::topup($admin->id, 100, 'opening balance');

        try {
            app(MediaTokenBillingService::class)->reserve($admin, 'image', 'unpriced-model', 1, 'admin-unpriced', 0);
            $this->fail('Expected a missing token price to be refused.');
        } catch (InvalidArgumentException) {
            $this->assertSame(100, UserToken::getBalance($admin->id));
        }

        $this->assertDatabaseMissing('token_reservations', ['reference_id' => 'admin-unpriced']);
        $this->assertDatabaseMissing('token_transactions', ['user_id' => $admin->id, 'type' => 'deduct']);
    }

    public function test_historical_admin_free_reservations_keep_their_snapshot_when_retried_settled_or_released(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $billing = app(MediaTokenBillingService::class);
        $historical = TokenReservation::create([
            'user_id' => $admin->id, 'reference_id' => 'historical-admin',
            'service' => 'video', 'model' => 'model-v', 'quantity' => 2,
            'unit_tokens' => 0, 'amount_tokens' => 0, 'billing_mode' => 'admin', 'status' => 'reserved',
        ]);
        $released = TokenReservation::create([
            'user_id' => $admin->id, 'reference_id' => 'historical-release',
            'service' => 'image', 'model' => 'model-a', 'quantity' => 1,
            'unit_tokens' => 0, 'amount_tokens' => 0, 'billing_mode' => 'admin', 'status' => 'reserved',
        ]);

        $retry = $billing->reserve($admin, 'video', 'model-v', 2, 'historical-admin', 0);
        $this->assertSame($historical->payload(), $retry);
        $billing->settle($admin->id, $retry);
        $billing->release($admin->id, $retry, 'late callback');
        $billing->release($admin->id, $released->payload(), 'cancelled before dispatch');
        $billing->release($admin->id, $released->payload(), 'duplicate cancellation');

        $this->assertSame('settled', $historical->fresh()->status);
        $this->assertSame('released', $released->fresh()->status);
        $this->assertSame($historical->payload(), $historical->fresh()->payload());
        $this->assertSame($released->payload(), $released->fresh()->payload());
        $this->assertDatabaseMissing('user_tokens', ['user_id' => $admin->id]);
        $this->assertDatabaseMissing('token_transactions', ['user_id' => $admin->id]);
    }

    public function test_legacy_token_operations_are_atomic_reference_idempotent_and_reject_invalid_amounts(): void
    {
        $user = User::factory()->create();
        UserToken::topup($user->id, 20, 'first', 'credit-ref');
        UserToken::topup($user->id, 20, 'duplicate', 'credit-ref');
        $this->assertTrue(UserToken::deduct($user->id, 10, 'usage', 'debit-ref'));
        $this->assertTrue(UserToken::deduct($user->id, 10, 'duplicate', 'debit-ref'));
        UserToken::refund($user->id, 10, 'failed', 'refund-ref');
        UserToken::refund($user->id, 10, 'duplicate', 'refund-ref');

        $this->assertSame(20, UserToken::getBalance($user->id));
        $this->assertSame(3, TokenTransaction::where('user_id', $user->id)->count());

        foreach ([0, -1] as $invalid) {
            try {
                UserToken::topup($user->id, $invalid, 'invalid', (string) Str::uuid());
                $this->fail('Expected invalid amount exception.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    private function confirmedOrder(User $member, array $checkoutPayload): int
    {
        $checkout = $this->actingAs($member)->postJson('/api/deposits/checkout', $checkoutPayload)
            ->assertCreated()->json('checkout');

        return (int) $this->actingAs($member)->postJson('/api/deposits', [
            'payment_reference' => $checkout['payment_reference'],
        ])->assertCreated()->json('order.id');
    }
}
