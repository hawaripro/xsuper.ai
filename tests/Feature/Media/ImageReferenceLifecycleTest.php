<?php

namespace Tests\Feature\Media;

use App\Exceptions\ImageGenerationException;
use App\Media\AssetService;
use App\Media\CapabilityResolver;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\ImageJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\UserToken;
use App\Services\ImageGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F1b image_edit (reference) pipeline: an owned private reference asset is minted into a
 * short-lived cookie-independent provider-fetch grant that the adapter passes as uploadedUrls,
 * and the job persists the stable asset id (never a signed URL). All provider calls are
 * Http::fake (mock) — NOT live Kinovi.
 */
class ImageReferenceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function model(): AiModelProfile
    {
        $provider = AiProviderProfile::create([
            'slug' => 'kinovi-ai', 'name' => 'Kinovi', 'protocol' => 'kinovi',
            'base_url' => 'https://kinovi.ai/api/v1', 'api_key' => 'k', 'is_enabled' => true,
        ]);

        return AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'kinovi-ai/gpt-image-2', 'upstream_model_id' => 'gpt-image-2',
            'display_name' => 'GPT Image 2', 'category' => 'image', 'is_enabled' => true, 'is_available' => true, 'token_cost' => 10,
        ]);
    }

    private function reference(User $user): MediaAsset
    {
        return app(AssetService::class)->store($user, UploadedFile::fake()->image('ref.jpg', 64, 64), InputRole::ImageRef);
    }

    private function editHash(AiModelProfile $model): string
    {
        return app(CapabilityResolver::class)->resolve($model, MediaOperation::ImageEdit)->sourceHash;
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public function test_image_edit_lifecycle_mints_grant_and_persists_stable_asset_id(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://kinovi.ai/api/v1/jobs/createTask' => Http::response(['taskId' => 'edit_task']),
            'https://kinovi.ai/api/v1/jobs/recordInfo*' => Http::response(['status' => 'success', 'output' => [['url' => 'https://static.seedance2-pro.com/x.png']]]),
            'https://static.seedance2-pro.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
        $model = $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $asset = $this->reference($user);
        $svc = app(ImageGenerationService::class);

        $job = $svc->generate($user, 'kinovi-ai/gpt-image-2', 'make it a watercolor', '1024x1024', 1, [
            'operation' => 'image_edit', 'reference_image' => $asset->id,
            'expected_price_tokens' => 10, 'expected_capability_hash' => $this->editHash($model),
        ]);

        $this->assertSame('pending', $job->status);
        $this->assertNotNull($job->capability_revision_id);
        $this->assertSame([$asset->id], $job->reference_asset_ids, 'stable asset id persisted, not a signed URL');
        $this->assertSame(90, UserToken::getBalance($user->id));

        $svc->process($job->id);

        // The provider received a grant URL (signed media.asset.deliver for this asset), never a raw path.
        Http::assertSent(function ($request) use ($asset): bool {
            if (! str_contains($request->url(), '/jobs/createTask')) {
                return false;
            }
            $uploaded = data_get($request->data(), 'inputs.uploadedUrls');

            return is_array($uploaded) && count($uploaded) === 1
                && str_contains($uploaded[0], 'signature=')
                && str_contains($uploaded[0], $asset->id);
        });

        $this->travel(10)->seconds();
        $svc->poll($job->id);
        $job->refresh();

        $this->assertSame('completed', $job->status);
        $this->assertSame('settled', $job->billing_status);
        $this->assertSame(90, UserToken::getBalance($user->id), 'settled once, not double-charged');
    }

    public function test_missing_required_reference_is_rejected_before_reserve(): void
    {
        $model = $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        try {
            app(ImageGenerationService::class)->generate($user, 'kinovi-ai/gpt-image-2', 'edit this', '1024x1024', 1, [
                'operation' => 'image_edit', 'expected_capability_hash' => $this->editHash($model),
            ]);
            $this->fail('expected image_edit without a reference to be rejected');
        } catch (ImageGenerationException $e) {
            $this->assertContains($e->responseStatus(), [422]);
        }
        $this->assertDatabaseCount('image_jobs', 0);
        $this->assertSame(100, UserToken::getBalance($user->id));
    }

    public function test_other_users_asset_is_rejected(): void
    {
        $model = $this->model();
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        UserToken::topup($attacker->id, 100);
        $asset = $this->reference($owner);

        try {
            app(ImageGenerationService::class)->generate($attacker, 'kinovi-ai/gpt-image-2', 'steal', '1024x1024', 1, [
                'operation' => 'image_edit', 'reference_image' => $asset->id, 'expected_capability_hash' => $this->editHash($model),
            ]);
            $this->fail('expected another user\'s asset to be rejected');
        } catch (ImageGenerationException $e) {
            $this->assertSame(403, $e->responseStatus());
        }
        $this->assertDatabaseCount('image_jobs', 0);
        $this->assertSame(100, UserToken::getBalance($attacker->id));
    }

    public function test_unknown_asset_id_is_rejected(): void
    {
        $model = $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);

        try {
            app(ImageGenerationService::class)->generate($user, 'kinovi-ai/gpt-image-2', 'edit', '1024x1024', 1, [
                'operation' => 'image_edit', 'reference_image' => 'not-a-real-asset', 'expected_capability_hash' => $this->editHash($model),
            ]);
            $this->fail('expected an unknown asset id to be rejected');
        } catch (ImageGenerationException $e) {
            $this->assertSame(422, $e->responseStatus());
        }
        $this->assertDatabaseCount('image_jobs', 0);
    }

    public function test_provider_fetch_grant_is_cookie_independent_and_signature_enforced(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $asset = $this->reference($user);
        $grant = app(AssetService::class)->signedUrl($asset, 3600);

        // No auth cookie/session at all: a valid signature alone delivers the bytes.
        $this->assertGuest();
        $this->get($grant)->assertOk()->assertHeader('Content-Type', $asset->mime);

        // Tampered signature is rejected.
        $this->get($grant.'tamper')->assertForbidden();

        // A short-lived grant is rejected once its TTL lapses (time-limited, not just signed).
        $expiring = app(AssetService::class)->signedUrl($asset, 60);
        $this->travel(120)->seconds();
        $this->get($expiring)->assertForbidden();
    }

    public function test_image_edit_retry_with_same_key_reuses_job_and_rejects_a_swapped_reference(): void
    {
        Queue::fake();
        $model = $this->model();
        $user = User::factory()->create();
        UserToken::topup($user->id, 100);
        $original = $this->reference($user);
        $swapped = $this->reference($user);
        $svc = app(ImageGenerationService::class);
        $opts = fn (MediaAsset $asset): array => [
            'operation' => 'image_edit', 'reference_image' => $asset->id,
            'expected_capability_hash' => $this->editHash($model), 'idempotency_key' => 'edit-act-1',
        ];

        $first = $svc->generate($user, 'kinovi-ai/gpt-image-2', 'watercolor', '1024x1024', 1, $opts($original));
        $retry = $svc->generate($user, 'kinovi-ai/gpt-image-2', 'watercolor', '1024x1024', 1, $opts($original));

        $this->assertSame($first->id, $retry->id, 'same key + same reference is one job');
        $this->assertSame(90, UserToken::getBalance($user->id), 'a retry never reserves twice');

        // Same key, but a swapped reference is a conflict — never a silent reuse of the old job.
        try {
            $svc->generate($user, 'kinovi-ai/gpt-image-2', 'watercolor', '1024x1024', 1, $opts($swapped));
            $this->fail('expected a swapped-reference retry to conflict');
        } catch (ImageGenerationException $e) {
            $this->assertSame(409, $e->responseStatus());
        }
        $this->assertDatabaseCount('image_jobs', 1);
    }
}
