<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_device_activity_is_written_at_most_once_a_minute_unless_the_address_changes(): void
    {
        Carbon::setTestNow('2026-09-25 10:00:00');
        $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
        $device = UserDevice::create([
            'user_id' => $member->id,
            'device_hash' => hash('sha256', implode('|', [$member->id, 'Steady browser', '', '', ''])),
            'device_name' => 'Steady browser', 'device_type' => 'browser', 'user_agent' => 'Steady browser',
            'status' => 'active', 'ip_address' => '192.0.2.10', 'last_active_at' => now()->subDay(),
        ]);
        // Same fingerprint signals as the stored device (the test client sends a default Accept-Language).
        $request = fn (string $address) => $this->actingAs($member)->withServerVariables(['REMOTE_ADDR' => $address])
            ->getJson('/api/u/me', ['User-Agent' => 'Steady browser', 'Accept-Language' => null])->assertOk();

        $request('192.0.2.10');
        $this->assertEquals(now(), $device->fresh()->last_active_at);

        // Polling a few seconds later is the same visit: no write per request.
        Carbon::setTestNow('2026-09-25 10:00:20');
        $request('192.0.2.10');
        $this->assertEquals(Carbon::parse('2026-09-25 10:00:00'), $device->fresh()->last_active_at);

        // A new address is recorded at once, so the device list never shows a stale location.
        $request('198.51.100.7');
        $this->assertSame('198.51.100.7', $device->fresh()->ip_address);
        $this->assertEquals(now(), $device->fresh()->last_active_at);

        Carbon::setTestNow('2026-09-25 10:01:30');
        $request('198.51.100.7');
        $this->assertEquals(now(), $device->fresh()->last_active_at);
        $this->assertDatabaseCount('user_devices', 1);
    }
}
