<?php

namespace Tests\Feature;

use App\Models\MediaToolJob;
use App\Models\TokenReservation;
use App\Models\User;
use App\Models\UserToken;
use App\Services\MediaToolService;
use App\Services\MediaTokenBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackgroundRemovalBillingTest extends TestCase
{
    use RefreshDatabase;

    private function pendingRembgJob(User $user, string $billingMode, int $tokens): MediaToolJob
    {
        return MediaToolJob::create([
            'user_id' => $user->id, 'job_id' => (string) \Illuminate\Support\Str::uuid(), 'kind' => 'rembg',
            'format' => 'png', 'billing_mode' => $billingMode, 'tokens_reserved' => $tokens,
            'input_name' => 'photo.png', 'title' => 'photo.png', 'status' => 'pending', 'stage' => 'queued', 'dispatched_at' => now(),
        ]);
    }

    public function test_capabilities_report_the_rembg_price_and_formats(): void
    {
        $capabilities = app(MediaToolService::class)->capabilities();

        $this->assertArrayHasKey('rembg_formats', $capabilities);
        $this->assertArrayHasKey('rembg_tokens', $capabilities);
        $this->assertSame(15, $capabilities['rembg_tokens']);
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/media-tools/rembg')->assertUnauthorized();
    }

    public function test_the_tool_is_unavailable_without_a_configured_runtime(): void
    {
        config(['media_tools.python' => null]);
        $user = User::factory()->create(['expires_at' => now()->addDays(5)]);

        // No sandbox runtime in the test environment: the tool refuses rather than pretending.
        $this->actingAs($user)
            ->postJson('/api/media-tools/rembg', ['file' => \Illuminate\Http\Testing\File::image('photo.png', 64, 64)])
            ->assertStatus(503);
        $this->assertDatabaseCount('media_tool_jobs', 0);
    }

    public function test_cancelling_a_paid_job_refunds_the_reserved_tokens(): void
    {
        $user = User::factory()->create();
        UserToken::topup($user->id, 100, 'seed');
        $billing = app(MediaTokenBillingService::class);
        $tools = app(MediaToolService::class);

        $job = $this->pendingRembgJob($user, 'tokens', 15);
        $billing->reserve($user, 'image', 'rembg', 1, $job->job_id, 15);
        $this->assertSame(85, UserToken::getBalance($user->id));

        $tools->cancel($user, $job->job_id);

        $this->assertSame(100, UserToken::getBalance($user->id));
        $this->assertSame(TokenReservation::STATUS_RELEASED, TokenReservation::where('reference_id', $job->job_id)->sole()->status);
        $this->assertSame('cancelled', $job->fresh()->status);
    }

    public function test_an_admin_job_is_free_and_carries_no_reservation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tools = app(MediaToolService::class);

        $job = $this->pendingRembgJob($admin, 'admin', 0);
        // Cancelling an admin (free) job must not attempt a refund or throw.
        $tools->cancel($admin, $job->job_id);

        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertDatabaseCount('token_reservations', 0);
    }
}
