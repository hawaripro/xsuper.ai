<?php

namespace Tests\Feature;

use App\Models\DepositOrder;
use App\Models\DurationOrder;
use App\Models\User;
use App\Models\UserToken;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepositCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function depositOrder(User $user, string $kind, string $status = DepositOrder::STATUS_PENDING): DepositOrder
    {
        return DepositOrder::create([
            'user_id' => $user->id, 'kind' => $kind, 'status' => $status,
            'payment_reference' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(30),
            'base_tokens' => $kind === 'tokens' ? 100 : 0, 'bonus_tokens' => 0,
            'total_tokens' => $kind === 'tokens' ? 100 : 0,
            'amount_idr' => 12000, 'credit_microusd' => $kind === 'wallet' ? 750000 : 0,
            'idr_per_usd' => 16000, 'payment_method' => 'qris',
            'confirmed_at' => $status === DepositOrder::STATUS_PENDING ? now() : null,
        ]);
    }

    public function test_member_cancels_pending_token_and_wallet_deposits_and_admin_cannot_approve_them(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $tokens = $this->depositOrder($member, DepositOrder::KIND_TOKENS);
        $wallet = $this->depositOrder($member, DepositOrder::KIND_WALLET);

        $this->actingAs($member)->postJson("/api/deposits/{$tokens->id}/cancel")
            ->assertOk()->assertJsonPath('order.status', 'cancelled');
        $this->actingAs($member)->postJson("/api/deposits/{$wallet->id}/cancel")
            ->assertOk()->assertJsonPath('order.status', 'cancelled');

        // Cancelled deposits are final: approval refuses and no value moves.
        $this->actingAs($admin)->postJson("/api/admin/deposits/{$tokens->id}/approve")->assertUnprocessable();
        $this->assertSame(0, UserToken::getBalance($member->id));
        $this->assertSame(0, Wallet::balance($member->id));
    }

    public function test_processed_or_foreign_deposits_cannot_be_cancelled(): void
    {
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $approved = $this->depositOrder($member, DepositOrder::KIND_TOKENS, DepositOrder::STATUS_APPROVED);
        $pending = $this->depositOrder($member, DepositOrder::KIND_TOKENS);

        $this->actingAs($member)->postJson("/api/deposits/{$approved->id}/cancel")->assertUnprocessable();
        $this->actingAs($stranger)->postJson("/api/deposits/{$pending->id}/cancel")->assertNotFound();
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_member_cancels_a_pending_subscription_order_and_admin_cannot_approve_it(): void
    {
        $member = User::factory()->create(['expires_at' => now()->addDays(3)]);
        $admin = User::factory()->create(['role' => 'admin']);
        $order = DurationOrder::create([
            'user_id' => $member->id, 'package' => '1_week', 'days' => 7, 'price' => 20000, 'status' => 'pending',
        ]);

        $this->actingAs($member)->postJson("/api/period/order/{$order->id}/cancel")
            ->assertOk()->assertJsonPath('order.status', 'cancelled');

        $expiryBefore = $member->fresh()->expires_at;
        $this->actingAs($admin)->postJson("/api/a/period/approve/{$order->id}")->assertUnprocessable();
        $this->assertTrue($expiryBefore->equalTo($member->fresh()->expires_at));

        // Only pending orders are cancellable.
        $this->actingAs($member)->postJson("/api/period/order/{$order->id}/cancel")->assertUnprocessable();
    }
}
