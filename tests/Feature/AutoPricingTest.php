<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoPricingTest extends TestCase
{
    use RefreshDatabase;


    public function test_wallet_topup_route_is_removed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/pricing/wallet/topup', ['user_id' => $admin->id, 'amount_usd' => 5])->assertNotFound();
    }
}
