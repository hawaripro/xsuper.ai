<?php

namespace Tests\Feature;

use App\Models\AiProviderProfile;
use App\Models\ContentBlock;
use App\Models\DurationOrder;
use App\Models\PaymentCheckout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        \Illuminate\Support\Facades\Http::fake();
    }

    public function test_qris_checkout_reference_is_required_and_persisted_on_order(): void
    {
        $member = User::factory()->create();

        $checkout = $this->actingAs($member)
            ->postJson('/api/period/checkout', ['package' => '1_month'])
            ->assertCreated()
            ->assertJsonPath('checkout.payment_method', 'qris')
            ->assertJsonPath('checkout.qr_image_url', '/assets/payments/qris-xsuper.png')
            ->json('checkout');

        $this->assertTrue(Str::isUuid($checkout['payment_reference']));

        $this->actingAs($member)
            ->postJson('/api/period/order', ['package' => '1_month'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_reference');

        $this->actingAs($member)
            ->postJson('/api/period/order', [
                'package' => '1_month',
                'payment_reference' => $checkout['payment_reference'],
            ])
            ->assertCreated()
            ->assertJsonPath('order.payment_method', 'qris');

        $this->assertDatabaseHas('duration_orders', [
            'user_id' => $member->id,
            'package' => '1_month',
            'payment_method' => 'qris',
            'payment_reference' => $checkout['payment_reference'],
        ]);
    }

    public function test_qris_checkout_reference_is_single_use_and_user_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $checkout = $this->actingAs($owner)
            ->postJson('/api/period/checkout', ['package' => '1_week'])
            ->assertCreated()
            ->json('checkout');

        $this->actingAs($other)
            ->postJson('/api/period/order', [
                'package' => '1_week',
                'payment_reference' => $checkout['payment_reference'],
            ])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->postJson('/api/period/order', [
                'package' => '1_week',
                'payment_reference' => $checkout['payment_reference'],
            ])
            ->assertCreated();

        DurationOrder::where('user_id', $owner->id)->delete();
        $this->actingAs($owner)
            ->postJson('/api/period/order', [
                'package' => '1_week',
                'payment_reference' => $checkout['payment_reference'],
            ])
            ->assertUnprocessable();
    }

    public function test_expired_qris_checkout_cannot_create_an_order(): void
    {
        $member = User::factory()->create();
        $checkout = $this->actingAs($member)
            ->postJson('/api/period/checkout', ['package' => '1_day'])
            ->assertCreated()
            ->json('checkout');

        PaymentCheckout::query()
            ->whereKey($checkout['payment_reference'])
            ->update(['expires_at' => now()->subSecond()]);

        $this->actingAs($member)
            ->postJson('/api/period/order', [
                'package' => '1_day',
                'payment_reference' => $checkout['payment_reference'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_reference');
    }

    public function test_dashboard_announcement_is_localized_and_surface_scoped(): void
    {
        $member = User::factory()->create();
        ContentBlock::create([
            'key' => 'system.announcement',
            'locale' => 'id',
            'draft' => ['message' => 'Pemeliharaan malam ini', 'level' => 'warning', 'surfaces' => ['dashboard']],
            'published' => ['message' => 'Pemeliharaan malam ini', 'level' => 'warning', 'surfaces' => ['dashboard']],
            'is_published' => true,
            'published_at' => now(),
        ]);
        ContentBlock::create([
            'key' => 'system.announcement',
            'locale' => 'en',
            'draft' => ['message' => 'Maintenance tonight', 'level' => 'warning', 'surfaces' => ['dashboard']],
            'published' => ['message' => 'Maintenance tonight', 'level' => 'warning', 'surfaces' => ['dashboard']],
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->actingAs($member)
            ->getJson('/api/content/announcement?locale=id')
            ->assertOk()
            ->assertJsonPath('announcement.message', 'Pemeliharaan malam ini');

        $this->actingAs($member)
            ->getJson('/api/content/announcement?locale=en')
            ->assertOk()
            ->assertJsonPath('announcement.message', 'Maintenance tonight');

        $this->get('/')->assertOk()->assertDontSee('Pemeliharaan malam ini');
    }

    public function test_public_announcement_targets_landing_pricing_and_models_independently(): void
    {
        ContentBlock::create([
            'key' => 'system.announcement',
            'locale' => 'id',
            'draft' => ['message' => 'Khusus halaman harga', 'level' => 'info', 'surfaces' => ['pricing']],
            'published' => ['message' => 'Khusus halaman harga', 'level' => 'info', 'surfaces' => ['pricing']],
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->get('/')->assertOk()->assertDontSee('Khusus halaman harga');
        $this->get('/pricing')->assertOk()->assertSee('Khusus halaman harga');
        $this->get('/models')->assertOk()->assertDontSee('Khusus halaman harga');
    }

    public function test_announcement_surface_validation_accepts_explicit_page_targets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/content', [
            'key' => 'system.announcement',
            'locale' => 'id',
            'draft' => [
                'message' => 'Target pilihan',
                'level' => 'success',
                'surfaces' => ['dashboard', 'landing', 'pricing', 'models'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.draft.surfaces.0', 'dashboard')
            ->assertJsonPath('data.draft.surfaces.3', 'models');
    }

    public function test_admin_can_create_model_profile_and_it_renders_publicly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = AiProviderProfile::create(['slug' => 'test-provider', 'name' => 'Test Provider', 'is_enabled' => true]);

        $this->actingAs($admin)
            ->postJson('/api/admin/ai/models', [
                'model_id' => 'new-live-model',
                'display_name' => 'New Live Model',
                'provider_name' => 'Test Provider',
                'provider_slug' => $provider->slug,
                'category' => 'chat',
                'description_id' => 'Model baru Indonesia',
                'description_en' => 'New English model',
                'context_window' => 64000,
                'capabilities' => ['chat'],
                'input_modalities' => ['text'],
                'output_modalities' => ['text'],
                'is_enabled' => true,
                'rates' => ['input_tokens' => 0.4, 'output_tokens' => 1.2],
            ])
            ->assertCreated()
            ->assertJsonPath('model.model_id', 'new-live-model');

        $this->assertDatabaseHas('ai_model_profiles', ['model_id' => 'new-live-model']);
        $this->get('/models')->assertOk()->assertSee('New Live Model');
        $this->get('/en/models')->assertOk()->assertSee('New English model');
    }

    public function test_all_supported_english_spa_routes_use_the_app_shell(): void
    {
        foreach ([
            '/en/login', '/en/dashboard', '/en/profile', '/en/chat', '/en/video',
            '/en/templates', '/en/library', '/en/generate-image', '/en/token-usage',
            '/en/paket', '/en/referral', '/en/bantuan', '/en/notifications',
            '/en/admin', '/en/admin/overview', '/en/admin/users', '/en/admin/operations',
            '/en/admin/token-usage', '/en/admin/ai', '/en/admin/content', '/en/admin/system',
            '/en/admin/settings',
        ] as $path) {
            $this->get($path)->assertOk()->assertSee('id="app"', false);
        }
    }
}
