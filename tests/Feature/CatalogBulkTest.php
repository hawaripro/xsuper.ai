<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AiCatalogController;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\DurationOrder;
use App\Models\DurationPackagePrice;
use App\Models\ImageJob;
use App\Models\UsageLog;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\VideoJob;
use App\Models\Wallet;
use App\Services\AuditService;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogBulkTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_bulk_validation_rolls_back_other_rows_and_rates(): void
    {
        $first = $this->model('bulk-first');
        $second = $this->model('bulk-second');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/api/admin/ai/models/bulk', ['items' => [
                ['id' => $first->id, 'display_name' => 'Changed together', 'token_cost' => 15],
                ['id' => $second->id, 'is_enabled' => true, 'rates' => ['input_tokens' => 1]],
            ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.rates');

        $this->assertSame('bulk-first', $first->fresh()->display_name);
        $this->assertNull($first->fresh()->token_cost);
        $this->assertFalse($second->fresh()->is_enabled);
        $this->assertDatabaseMissing('usage_rates', ['model' => 'bulk-second']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'ai_model.updated']);
    }

    public function test_model_bulk_edits_preserve_identity_availability_and_private_provider_key(): void
    {
        $provider = AiProviderProfile::create([
            'slug' => 'bulk-provider', 'name' => 'Bulk Provider', 'api_key' => 'fixture-private-value',
            'base_url' => 'https://bulk.example.test', 'protocol' => 'openai',
        ]);
        $image = $this->model('bulk-image', ['category' => 'image', 'provider_id' => $provider->id, 'upstream_model_id' => 'native-image']);
        $video = $this->model('bulk-video', ['category' => 'video']);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/api/admin/ai/models/bulk', ['items' => [
                ['id' => $image->id, 'token_cost' => 15, 'generation_config' => ['sizes' => ['auto'], 'max_quantity' => 4, 'supports_n' => false], 'rates' => ['unit' => 0.25]],
                ['id' => $video->id, 'token_cost' => 200, 'generation_config' => ['video_path' => 'videos/generations', 'video_status_path' => 'videos/generations/{id}', 'durations' => []]],
            ]])->assertOk()->assertJsonPath('updated_count', 2);

        $this->assertSame('native-image', $image->fresh()->upstream_model_id);
        $this->assertFalse($image->fresh()->is_available);
        $this->assertSame(15, $image->fresh()->token_cost);
        $this->assertSame(200, $video->fresh()->token_cost);
        $this->assertDatabaseHas('usage_rates', ['model' => 'bulk-image', 'service' => 'image', 'meter' => 'unit', 'price_usd' => 0.25]);
        $this->assertStringNotContainsString('fixture-private-value', $response->getContent());
        $this->assertStringNotContainsString('bulk.example.test', $response->getContent());
    }

    public function test_generation_config_rejects_secret_keys_unsafe_paths_and_duplicate_placeholders(): void
    {
        $model = $this->model('bulk-safe', ['category' => 'video']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([
            ['api_key' => 'must-not-persist'],
            ['video_path' => 'https://outside.example.test/generate'],
            ['video_path' => '../generate'],
            ['video_path' => 'videos/%2e%2e/generate'],
            ['video_status_path' => 'videos/{id}/{id}'],
            ['video_status_path' => 'videos/status'],
            ['max_quantity' => 0],
        ] as $configuration) {
            $this->patchJson('/api/admin/ai/models/bulk', ['items' => [[
                'id' => $model->id, 'token_cost' => 200, 'generation_config' => $configuration,
            ]]])->assertUnprocessable();
        }
        $this->assertNull($model->fresh()->generation_config);
        $this->assertNull($model->fresh()->token_cost);
    }

    public function test_exact_selection_and_confirmation_conflicts_never_mutate_models(): void
    {
        $model = $this->model('selected-model');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson('/api/admin/ai/models/bulk', ['items' => [
            ['id' => $model->id, 'display_name' => 'First'], ['id' => $model->id, 'display_name' => 'Second'],
        ]])->assertUnprocessable();
        $this->deleteJson('/api/admin/ai/models/bulk', [
            'ids' => [$model->id], 'expected_count' => 2, 'delete_usage_rates' => true,
        ])->assertStatus(409);
        $this->deleteJson('/api/admin/ai/models/bulk', [
            'ids' => [$model->id, $model->id + 100], 'expected_count' => 2, 'delete_usage_rates' => true,
        ])->assertStatus(409);
        $this->deleteJson('/api/admin/ai/models/bulk', [
            'all_matching' => true, 'expected_count' => 1, 'delete_usage_rates' => true,
        ])->assertUnprocessable();
        $this->assertModelExists($model);
        $this->assertSame('selected-model', $model->fresh()->display_name);
    }

    public function test_active_or_unreconciled_media_prevents_whole_model_delete(): void
    {
        $user = User::factory()->create();
        $image = $this->model('busy-image', ['category' => 'image']);
        $video = $this->model('busy-video', ['category' => 'video']);
        $job = ImageJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'model' => $image->model_id,
            'prompt' => 'Image in progress', 'status' => 'processing',
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = ['ids' => [$image->id, $video->id], 'expected_count' => 2, 'delete_usage_rates' => true];
        $this->deleteJson('/api/admin/ai/models/bulk', $payload)->assertStatus(409);
        $job->update(['status' => 'failed', 'billing_status' => 'reserved']);
        $this->deleteJson('/api/admin/ai/models/bulk', $payload)->assertStatus(409);
        $job->update(['billing_status' => 'released']);
        VideoJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'model' => $video->model_id,
            'prompt' => 'Video queued', 'mode' => 'prompt', 'aspect_ratio' => '16:9', 'duration' => 8,
            'tokens_used' => 0, 'status' => 'pending',
        ]);
        $this->deleteJson('/api/admin/ai/models/bulk', $payload)->assertStatus(409);
        $this->assertModelExists($image);
        $this->assertModelExists($video);
    }

    public function test_model_delete_preserves_usage_history_and_reserved_wallet_refund(): void
    {
        $user = User::factory()->create();
        $model = $this->model('retired-media', ['category' => 'image']);
        $rate = $this->rate('retired-media', 'unit', ['service' => 'image', 'is_active' => true, 'price_usd' => 0.25]);
        Wallet::credit($user->id, 1_000_000, 'Fixture balance');
        $reservation = app(UsageBillingService::class)->reserveUnit($user->id, 'image', $model->model_id, 1, 'bulk-refund');
        $history = ImageJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'model' => $model->model_id,
            'prompt' => 'Completed history', 'status' => 'completed', 'billing_status' => 'settled',
        ]);
        $log = UsageLog::create([
            'user_id' => $user->id, 'model' => $model->model_id, 'source' => 'api',
            'cost_microusd' => 250000, 'usage_rate_id' => $rate->id,
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->deleteJson('/api/admin/ai/models/bulk', [
                'ids' => [$model->id], 'expected_count' => 1, 'delete_usage_rates' => true,
            ])->assertOk()->assertJsonPath('deleted_count', 1);
        Wallet::release($user->id, $reservation, 'Generation failed');
        Wallet::release($user->id, $reservation, 'Repeated callback');

        $this->assertModelMissing($model);
        $this->assertModelExists($history);
        $this->assertSame('retired-media', $log->fresh()->model);
        $this->assertNull($log->fresh()->usage_rate_id);
        $this->assertSame(1_000_000, Wallet::balance($user->id));
        $this->assertDatabaseHas('wallet_transactions', ['reference_id' => 'bulk-refund', 'model' => 'retired-media']);
    }

    public function test_bulk_audit_failure_rolls_back_every_model(): void
    {
        $first = $this->model('audited-first');
        $second = $this->model('audited-second');
        $admin = User::factory()->create(['role' => 'admin']);
        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        $request = Request::create('/api/admin/ai/models/bulk', 'PATCH', ['items' => [
            ['id' => $first->id, 'display_name' => 'Audited first'], ['id' => $second->id, 'display_name' => 'Audited second'],
        ]]);
        $request->setUserResolver(fn () => $admin);
        try {
            app(AiCatalogController::class)->bulkUpdateModels($request, $audit);
            $this->fail('The required audit write must be transactional.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('audited-first', $first->fresh()->display_name);
        $this->assertSame('audited-second', $second->fresh()->display_name);
    }

    public function test_rate_bulk_uses_combined_prices_before_activating_api_pair(): void
    {
        $input = $this->rate('paired-bulk', 'input_tokens', ['price_usd' => null, 'price_idr' => null]);
        $output = $this->rate('paired-bulk', 'output_tokens', ['price_usd' => null, 'price_idr' => null]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/api/pricing/rates/bulk', ['items' => [
                ['id' => $input->id, 'price_usd' => 1, 'price_idr' => 16000, 'is_active' => true],
                ['id' => $output->id, 'price_usd' => 3, 'price_idr' => 48000, 'is_active' => true],
            ]])->assertOk()->assertJsonPath('updated_count', 2);
        $this->assertSame(4_000_000, app(UsageBillingService::class)->estimateApiMaximum('paired-bulk', 1_000_000, 1_000_000));

        $this->patchJson('/api/pricing/rates/bulk', ['items' => [
            ['id' => $input->id, 'price_usd' => 2], ['id' => $output->id, 'price_usd' => null],
        ]])->assertUnprocessable();
        $this->assertSame(4_000_000, app(UsageBillingService::class)->estimateApiMaximum('paired-bulk', 1_000_000, 1_000_000));
        $this->patchJson('/api/pricing/rates/bulk', ['items' => [
            ['id' => $input->id, 'is_active' => false], ['id' => $output->id, 'is_active' => true],
        ]])->assertUnprocessable();
        $this->assertTrue($input->fresh()->is_active);
        $this->assertTrue($output->fresh()->is_active);
    }

    public function test_rate_delete_deactivates_only_invalid_api_siblings_and_preserves_logs(): void
    {
        $input = $this->rate('delete-pair', 'input_tokens', ['is_active' => true]);
        $output = $this->rate('delete-pair', 'output_tokens', ['is_active' => true]);
        $cache = $this->rate('delete-pair', 'cache_read', ['is_active' => true]);
        $log = UsageLog::create([
            'user_id' => User::factory()->create()->id, 'model' => 'delete-pair', 'source' => 'api',
            'cost_microusd' => 100, 'usage_rate_id' => $input->id,
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->deleteJson('/api/pricing/rates/bulk', ['ids' => [$cache->id], 'expected_count' => 1])->assertOk();
        $this->assertTrue($input->fresh()->is_active);
        $this->assertTrue($output->fresh()->is_active);
        $this->deleteJson('/api/pricing/rates/bulk', ['ids' => [$input->id], 'expected_count' => 1])->assertOk();
        $this->assertFalse($output->fresh()->is_active);
        $this->assertSame(100, (int) $log->fresh()->cost_microusd);
        $this->assertNull($log->fresh()->usage_rate_id);
    }

    public function test_inactive_null_prices_remain_null_and_cache_meter_uses_million_units(): void
    {
        $rate = $this->rate('nullable-rate', 'cache_read');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/api/pricing/rates/bulk', ['items' => [[
                'id' => $rate->id, 'price_usd' => null, 'price_idr' => null,
            ]]])->assertOk();
        $this->assertNull($rate->fresh()->price_usd);
        $this->assertNull($rate->fresh()->price_idr);
        $rate->update(['price_usd' => 0.25]);
        $this->assertSame(125000, $rate->costMicrousd(500000));
    }

    public function test_duration_bulk_cannot_disable_every_package_or_partially_apply_prices(): void
    {
        $catalog = DurationPackagePrice::catalog();
        $items = [];
        foreach (array_keys(DurationOrder::PACKAGES) as $package) {
            $items[] = ['package' => $package, 'is_active' => false, 'price_idr' => 99999];
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/api/pricing/durations/bulk', ['items' => $items])->assertUnprocessable();
        $this->assertSame($catalog, DurationPackagePrice::catalog());
        $items[0]['is_active'] = true;
        $this->patchJson('/api/pricing/durations/bulk', ['items' => $items])->assertOk();
        $active = array_filter(DurationPackagePrice::catalog(), fn (array $package): bool => $package['is_active']);
        $this->assertCount(1, $active);
        $this->assertSame(99999, array_values($active)[0]['price_idr']);
    }

    public function test_shared_media_options_are_valid_per_row_and_mass_changes_preserve_unselected_models(): void
    {
        $first = $this->model('config-first', ['category' => 'image']);
        $second = $this->model('config-second', ['category' => 'image']);
        $untouched = $this->model('config-untouched', ['category' => 'image']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/api/admin/ai/models/bulk', ['items' => [
                ['id' => $first->id, 'generation_config' => ['sizes' => ['auto', '1024x1024']]],
                ['id' => $second->id, 'generation_config' => ['sizes' => ['auto', '1024x1024']]],
            ]])->assertOk();
        $this->patchJson('/api/admin/ai/models/bulk', [
            'ids' => [$first->id, $second->id], 'changes' => ['token_cost' => 15],
        ])->assertOk();
        $this->assertSame(15, $first->fresh()->token_cost);
        $this->assertSame(['auto', '1024x1024'], $second->fresh()->generation_config['sizes']);
        $this->assertNull($untouched->fresh()->token_cost);
        $this->patchJson('/api/admin/ai/models/bulk', ['items' => [[
            'id' => $first->id, 'generation_config' => ['sizes' => ['auto', 'auto']],
        ]]])->assertUnprocessable();
    }

    public function test_bulk_limits_and_protected_model_identity_cannot_be_bypassed(): void
    {
        $model = $this->model('bulk-protected');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson('/api/admin/ai/models/bulk', [
            'ids' => range(1, 201), 'changes' => ['is_enabled' => true],
        ])->assertUnprocessable();
        $this->patchJson('/api/admin/ai/models/bulk', ['items' => [[
            'id' => $model->id, 'is_available' => true, 'model_id' => 'changed-id',
        ]]])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'member']))
            ->patchJson('/api/admin/ai/models/bulk', ['items' => [['id' => $model->id, 'is_enabled' => true]]])
            ->assertForbidden();
        $this->assertFalse($model->fresh()->is_enabled);
        $this->assertFalse($model->fresh()->is_available);
        $this->assertSame('bulk-protected', $model->fresh()->model_id);
    }

    public function test_cache_activation_cannot_override_explicit_pair_deactivation(): void
    {
        $input = $this->rate('cache-conflict', 'input_tokens', ['is_active' => true]);
        $output = $this->rate('cache-conflict', 'output_tokens', ['is_active' => true]);
        $cache = $this->rate('cache-conflict', 'cache_read');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/api/pricing/rates/bulk', ['items' => [
                ['id' => $input->id, 'is_active' => false],
                ['id' => $cache->id, 'is_active' => true],
            ]])->assertUnprocessable();
        $this->assertTrue($input->fresh()->is_active);
        $this->assertTrue($output->fresh()->is_active);
        $this->assertFalse($cache->fresh()->is_active);
    }

    public function test_usage_rate_bulk_confirmation_and_missing_ids_leave_all_rates_unchanged(): void
    {
        $rate = $this->rate('precise-rate', 'unit', ['service' => 'image']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson('/api/pricing/rates/bulk', ['ids' => [$rate->id], 'expected_count' => 2])->assertStatus(409);
        $this->patchJson('/api/pricing/rates/bulk', ['items' => [
            ['id' => $rate->id, 'price_usd' => 3], ['id' => $rate->id + 100, 'price_usd' => 4],
        ]])->assertStatus(409);
        $this->assertSame('1.00000000', $rate->fresh()->price_usd);
    }

    private function model(string $id, array $values = []): AiModelProfile
    {
        return AiModelProfile::create(['model_id' => $id, 'display_name' => $id, 'category' => 'chat', 'is_enabled' => false, ...$values]);
    }

    private function rate(string $model, string $meter, array $values = []): UsageRate
    {
        return UsageRate::create([
            'service' => 'api', 'meter' => $meter, 'model' => $model, 'label' => $meter, 'unit' => '1M tokens',
            'price_usd' => 1, 'price_idr' => 16000, 'is_active' => false, ...$values,
        ]);
    }
}
