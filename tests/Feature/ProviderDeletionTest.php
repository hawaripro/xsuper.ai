<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AiProviderController;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\TokenReservation;
use App\Models\UsageLog;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Models\Wallet;
use App\Services\AuditService;
use App\Services\GeneratedVideoStore;
use App\Services\MediaTokenBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_cascade_and_current_model_count_are_required_before_any_deletion(): void
    {
        $provider = $this->provider('confirmed');
        $model = $this->model($provider, 'confirmed-image');
        $rate = $this->rate($model);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = '/api/admin/ai/providers/'.$provider->id;

        $this->deleteJson($url, ['expected_model_count' => 1])->assertUnprocessable()->assertJsonValidationErrors('delete_models');
        $this->deleteJson($url, ['delete_models' => false, 'expected_model_count' => 1])->assertUnprocessable();
        $this->deleteJson($url, ['delete_models' => true])->assertUnprocessable()->assertJsonValidationErrors('expected_model_count');
        $this->deleteJson($url, ['delete_models' => true, 'expected_model_count' => -1])->assertUnprocessable();
        $this->getJson('/api/admin/ai/catalog')->assertOk()->assertJsonPath('providers.0.models_count', 1);
        $addedModel = $this->model($provider, 'added-after-confirmation');
        $this->deleteJson($url, ['delete_models' => true, 'expected_model_count' => 1])
            ->assertConflict()->assertJsonPath('code', 'provider_models_changed');

        $this->assertModelExists($provider);
        $this->assertModelExists($model);
        $this->assertModelExists($addedModel);
        $this->assertModelExists($rate);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_an_empty_environment_provider_can_be_deleted_without_mutating_server_configuration(): void
    {
        config()->set([
            'services.ai_proxy.url' => 'https://environment.example.test',
            'services.ai_proxy.key' => 'environment-fixture-secret',
        ]);
        $provider = AiProviderProfile::create(['slug' => 'ai-proxy', 'name' => 'Environment']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->deleteJson('/api/admin/ai/providers/'.$provider->id, ['delete_models' => true, 'expected_model_count' => 0])
            ->assertOk()->assertExactJson([
                'deleted_provider_id' => $provider->id, 'deleted_models' => 0, 'deleted_usage_rates' => 0,
            ]);

        $this->assertModelMissing($provider);
        $this->assertSame('https://environment.example.test', config('services.ai_proxy.url'));
        $this->assertSame('environment-fixture-secret', config('services.ai_proxy.key'));
    }

    public function test_populated_provider_deletion_preserves_history_ledgers_private_assets_and_other_providers(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $provider = $this->provider('retired');
        $imageModel = $this->model($provider, 'retired-image');
        $videoModel = $this->model($provider, 'retired-video', 'video');
        $imageRate = $this->rate($imageModel);
        $videoRate = $this->rate($videoModel);
        $otherProvider = $this->provider('retained');
        $otherModel = $this->model($otherProvider, 'retained-image');
        $otherRate = $this->rate($otherModel);
        UserToken::topup($user->id, 100, 'Opening balance');
        $billing = app(MediaTokenBillingService::class);
        $tokens = $billing->reserve($user, 'image', $imageModel->model_id, 1, 'retired-image-reference', 15);
        $billing->settle($user->id, $tokens);
        Wallet::credit($user->id, 1_000_000, 'Opening wallet');
        $wallet = Wallet::reserve($user->id, 250_000, 'retired-video-reference', [
            'service' => 'video', 'model' => $videoModel->model_id,
        ]);
        Wallet::settle($user->id, $wallet, 250_000, ['service' => 'video', 'model' => $videoModel->model_id]);
        $imagePath = 'generated-images/retired-image.png';
        Storage::disk('local')->put($imagePath, 'retained-image-bytes');
        $image = ImageJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'model' => $imageModel->model_id,
            'prompt' => 'Historical image', 'status' => 'completed', 'stage' => 'completed',
            'billing_status' => 'settled', 'billing_mode' => 'tokens', 'tokens_reserved' => 15,
            'billing_reference_id' => $tokens['reference_id'],
            'asset_paths' => [['path' => $imagePath, 'mime' => 'image/png']],
        ]);
        $videoId = (string) Str::uuid();
        $videoPath = GeneratedVideoStore::path($videoId);
        Storage::disk('local')->put($videoPath, 'retained-video-bytes');
        $video = VideoJob::create([
            'user_id' => $user->id, 'job_id' => $videoId, 'provider_id' => $provider->id,
            'model' => $videoModel->model_id, 'upstream_model_id' => 'native-video', 'upstream_job_id' => 'historical-task',
            'mode' => 'prompt', 'prompt' => 'Historical video', 'status' => 'completed', 'stage' => 'completed',
            'billing_status' => 'settled', 'billing_mode' => 'wallet', 'billing_reserved_microusd' => 250_000,
            'billing_reference_id' => $wallet['reference_id'], 'video_url' => '/api/v/'.$videoId.'/asset',
        ]);
        $log = UsageLog::create([
            'user_id' => $user->id, 'model' => $videoModel->model_id, 'source' => 'web',
            'cost_microusd' => 250_000, 'usage_rate_id' => $videoRate->id,
        ]);
        $imageBefore = $image->fresh()->getRawOriginal();
        $videoBefore = $video->fresh()->getRawOriginal();
        $logBefore = $log->fresh()->getRawOriginal();
        $historyBefore = [];
        foreach (['token_reservations', 'token_transactions', 'wallet_transactions'] as $table) {
            $historyBefore[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        $response = $this->actingAs($user)
            ->deleteJson('/api/admin/ai/providers/'.$provider->id, ['delete_models' => true, 'expected_model_count' => 2])
            ->assertOk()->assertExactJson([
                'deleted_provider_id' => $provider->id, 'deleted_models' => 2, 'deleted_usage_rates' => 2,
            ]);

        foreach ([$provider, $imageModel, $videoModel, $imageRate, $videoRate] as $deleted) {
            $this->assertModelMissing($deleted);
        }
        foreach ([$otherProvider, $otherModel, $otherRate] as $retained) {
            $this->assertModelExists($retained);
        }
        $this->assertSame($imageBefore, $image->fresh()->getRawOriginal());
        $this->assertSame([...$videoBefore, 'provider_id' => null], $video->fresh()->getRawOriginal());
        foreach ($historyBefore as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertSame([...$logBefore, 'usage_rate_id' => null], $log->fresh()->getRawOriginal());
        $this->assertSame(85, UserToken::getBalance($user->id));
        $this->assertSame(750_000, Wallet::balance($user->id));
        $this->assertSame('settled', TokenReservation::where('reference_id', $tokens['reference_id'])->value('status'));
        $this->assertSame('retained-image-bytes', Storage::disk('local')->get($imagePath));
        $this->assertSame('retained-video-bytes', Storage::disk('local')->get($videoPath));
        $this->get('/api/images/'.$image->job_id.'/assets/0')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/v/'.$videoId.'/asset')->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->getJson('/api/admin/ai/catalog')->assertOk()->assertJsonCount(1, 'providers')->assertJsonCount(1, 'models')
            ->assertJsonPath('models.0.model_id', 'retained-image');
        $audit = DB::table('audit_events')->where('action', 'ai_provider.deleted')->first();
        $this->assertNotNull($audit);
        foreach (['retired.example.test', 'retired-private-key'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $response->getContent());
            $this->assertStringNotContainsString($privateValue, $audit->metadata);
        }
    }

    #[DataProvider('busyMedia')]
    public function test_active_or_reserved_media_prevents_deletion_by_current_model_or_provider_snapshot(
        string $kind,
        string $status,
        string $billingStatus,
        bool $providerSnapshot,
    ): void {
        $provider = $this->provider('busy');
        $model = $this->model($provider, 'busy-model', $kind);
        $rate = $this->rate($model);
        $user = User::factory()->create(['role' => 'admin']);
        $attributes = [
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(),
            'model' => $providerSnapshot ? 'historical-model-no-longer-in-catalog' : $model->model_id,
            'prompt' => 'Must finish first', 'status' => $status, 'billing_status' => $billingStatus,
        ];
        $job = $kind === 'image'
            ? ImageJob::create($attributes)
            : VideoJob::create([...$attributes, 'mode' => 'prompt', 'provider_id' => $providerSnapshot ? $provider->id : null]);
        $before = $job->fresh()->getRawOriginal();

        $this->actingAs($user)
            ->deleteJson('/api/admin/ai/providers/'.$provider->id, ['delete_models' => true, 'expected_model_count' => 1])
            ->assertConflict()->assertJsonPath('code', 'provider_media_busy');

        $this->assertModelExists($provider);
        $this->assertModelExists($model);
        $this->assertModelExists($rate);
        $this->assertSame($before, $job->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function busyMedia(): array
    {
        return [
            'pending image' => ['image', 'pending', 'not_required', false],
            'processing image' => ['image', 'processing', 'not_required', false],
            'unreconciled image' => ['image', 'failed', 'reserved', false],
            'legacy video matched by model' => ['video', 'processing', 'not_required', false],
            'queued provider snapshot' => ['video', 'pending', 'not_required', true],
            'unreconciled provider snapshot' => ['video', 'completed', 'reserved', true],
        ];
    }

    public function test_audit_failure_rolls_back_provider_models_rates_and_historical_snapshot_detachment(): void
    {
        $provider = $this->provider('atomic');
        $model = $this->model($provider, 'atomic-video', 'video');
        $rate = $this->rate($model);
        $user = User::factory()->create(['role' => 'admin']);
        $video = VideoJob::create([
            'user_id' => $user->id, 'job_id' => (string) Str::uuid(), 'mode' => 'prompt',
            'provider_id' => $provider->id, 'model' => $model->model_id, 'prompt' => 'Retained',
            'status' => 'completed', 'billing_status' => 'settled',
        ]);
        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        $request = Request::create('/api/admin/ai/providers/'.$provider->id, 'DELETE', [
            'delete_models' => true, 'expected_model_count' => 1,
        ]);
        $request->setUserResolver(fn () => $user);

        try {
            app(AiProviderController::class)->destroy($request, $provider, $audit);
            $this->fail('Deletion must roll back when its audit cannot be recorded.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }

        $this->assertModelExists($provider);
        $this->assertModelExists($model);
        $this->assertModelExists($rate);
        $this->assertSame($provider->id, $video->fresh()->provider_id);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function provider(string $slug): AiProviderProfile
    {
        return AiProviderProfile::create([
            'slug' => $slug, 'name' => ucfirst($slug), 'protocol' => 'openai',
            'base_url' => 'https://'.$slug.'.example.test/v1', 'api_key' => $slug.'-private-key',
        ]);
    }

    private function model(AiProviderProfile $provider, string $id, string $category = 'image'): AiModelProfile
    {
        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => $id, 'display_name' => $id,
            'category' => $category, 'is_enabled' => true, 'is_available' => true,
        ]);
    }

    private function rate(AiModelProfile $model): UsageRate
    {
        return UsageRate::create([
            'service' => $model->category, 'meter' => 'unit', 'model' => $model->model_id,
            'label' => $model->display_name, 'unit' => 'generation', 'price_usd' => 0.25, 'is_active' => true,
        ]);
    }
}
