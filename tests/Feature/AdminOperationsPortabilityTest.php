<?php

namespace Tests\Feature;

use App\Models\DurationOrder;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOperationsPortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_analytics_are_grouped_in_the_requested_timezone_on_sqlite(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create();
        UsageLog::create([
            'user_id' => $member->id,
            'model' => 'chat-fast',
            'source' => 'web',
            'total_tokens' => 120,
            'credit' => 1.25,
            'created_at' => '2026-09-13 18:30:00',
            'updated_at' => '2026-09-13 18:30:00',
        ]);
        UsageLog::create([
            'user_id' => $member->id,
            'model' => 'chat-quality',
            'source' => 'api',
            'total_tokens' => 80,
            'credit' => 0.75,
            'created_at' => '2026-09-13 19:30:00',
            'updated_at' => '2026-09-13 19:30:00',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/usage?period=daily&tz=Asia/Jakarta')
            ->assertOk()
            ->assertJsonPath('total_tokens', 200)
            ->assertJsonPath('total_requests', 2)
            ->assertJsonPath('timeline.0.label', '09-14')
            ->assertJsonPath('timeline.0.tokens', 200)
            ->assertJsonPath('model_timeline.data.0.chat-fast', 120)
            ->assertJsonPath('model_timeline.data.0.chat-quality', 80);
    }

    public function test_order_search_is_case_insensitive_and_database_portable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['name' => 'Siti Rahma', 'email' => 'SITI@example.test']);
        DurationOrder::create([
            'user_id' => $member->id,
            'package' => '1_month',
            'days' => 30,
            'price' => 55000,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/a/stats/orders?search=siti')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $member->id);
    }
}
