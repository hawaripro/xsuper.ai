<?php

namespace Tests\Feature\Media;

use App\Media\AssetService;
use App\Media\Enums\InputRole;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Models\RealtimeMediaSession;
use App\Models\User;
use App\Services\StorageQuotaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AssetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_valid_image_reference_upload(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $asset = app(AssetService::class)->store($user, UploadedFile::fake()->image('ref.png', 64, 64), InputRole::ImageRef);

        $this->assertTrue($asset->signature_ok);
        $this->assertSame('image', $asset->media_type);
        $this->assertSame('image_ref', $asset->role);
        $this->assertGreaterThan(0, $asset->size_bytes);
        Storage::disk('local')->assertExists($asset->storage_path);
    }

    public function test_rejects_file_whose_bytes_are_not_an_image(): void
    {
        Storage::fake('local');
        $this->expectException(\InvalidArgumentException::class);
        app(AssetService::class)->store(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent('ref.png', 'this is plainly not an image'),
            InputRole::ImageRef,
        );
    }

    public function test_rejects_oversize_upload(): void
    {
        Storage::fake('local');
        $this->expectException(\InvalidArgumentException::class);
        app(AssetService::class)->store(
            User::factory()->create(),
            UploadedFile::fake()->image('big.png')->size(20 * 1024),
            InputRole::ImageRef,
        );
    }

    public function test_assert_owner_rejects_non_owner(): void
    {
        Storage::fake('local');
        $asset = app(AssetService::class)->store(User::factory()->create(), UploadedFile::fake()->image('ref.png'), InputRole::ImageRef);
        $this->expectException(AuthorizationException::class);
        app(AssetService::class)->assertOwner(User::factory()->create(), $asset);
    }

    public function test_signed_delivery_is_cookie_independent_and_rejects_tampered_or_expired(): void
    {
        Storage::fake('local');
        $service = app(AssetService::class);
        $asset = $service->store(User::factory()->create(), UploadedFile::fake()->image('ref.png', 32, 32), InputRole::ImageRef);
        $url = $service->signedUrl($asset, 600);

        // Anonymous (provider-style) fetch succeeds purely on the signature.
        $this->get($url)->assertOk()->assertHeader('Content-Type', $asset->mime);
        $this->get($url.'tampered')->assertForbidden();

        $this->travel(700)->seconds();
        $this->get($url)->assertForbidden();
    }

    public function test_generic_svg_keeps_original_bytes_but_cannot_be_delivered_inline(): void
    {
        Storage::fake('local');
        $service = app(AssetService::class);
        $bytes = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $asset = $service->store(User::factory()->create(), UploadedFile::fake()->createWithContent('diagram.svg', $bytes), InputRole::GenericFile);
        $response = $service->deliver($asset);

        $this->assertSame($bytes, Storage::disk('local')->get($asset->storage_path));
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertFalse($service->present($asset)['previewable']);
        $this->assertNull($service->present($asset)['preview_url']);
    }

    public function test_ascii_pdf_cannot_be_relabelled_as_chat_text(): void
    {
        Storage::fake('local');
        $this->expectException(\InvalidArgumentException::class);
        app(AssetService::class)->store(User::factory()->create(), UploadedFile::fake()->createWithContent(
            'notes.txt', "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
        ), InputRole::Document);
    }

    public function test_multi_upload_admits_total_bytes_not_each_file_individually(): void
    {
        Storage::fake('local');
        config(['storage_quota.base_bytes' => 100]);
        $user = User::factory()->create();
        try {
            app(AssetService::class)->storeMany($user, [
                UploadedFile::fake()->createWithContent('one.txt', str_repeat('a', 60)),
                UploadedFile::fake()->createWithContent('two.txt', str_repeat('b', 60)),
            ], InputRole::Document);
            $this->fail('A multi-upload larger than remaining storage must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(413, $exception->getStatusCode());
        }

        $this->assertSame(0, app(StorageQuotaService::class)->usedBytes($user));
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_live_realtime_recording_survives_retention_and_reserved_tokens_block_cleanup(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $quota = app(StorageQuotaService::class);
        $webm = hex2bin('1A45DFA39F4286810142F7810142F2810442F381084282847765626D4287810442858102').hex2bin('1853806701FFFFFFFFFFFFFF').str_repeat("\0", 64);
        $recording = app(AssetService::class)->store($user, UploadedFile::fake()->createWithContent('session.webm', $webm), InputRole::ReferenceVideo);
        $recording->forceFill(['created_at' => now()->subDays(10)])->save();
        $session = $this->realtimeSession($user, [$recording->id]);

        $quota->purge(now()->subDays(7));
        Storage::disk('local')->assertExists($recording->storage_path);
        $this->assertSame(strlen($webm), $quota->usedBytes($user));

        $session->update(['status' => 'closed', 'billing_status' => 'reserved']);
        $counts = $quota->purge(now()->subDays(7));
        $this->assertSame([1, 0], [$counts['media_asset'], $counts['realtime_session']]);
        Storage::disk('local')->assertMissing($recording->storage_path);
        try {
            $quota->deleteAccount($user);
            $this->fail('An account holding reserved realtime tokens must not be deleted.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }

        $session->update(['billing_status' => 'charged']);
        $this->assertSame(1, $quota->purge(now()->subDays(7))['realtime_session']);
        $this->assertDatabaseMissing('realtime_media_sessions', ['id' => $session->id]);
    }

    private function realtimeSession(User $user, array $recordingIds): RealtimeMediaSession
    {
        $provider = AiProviderProfile::create(['name' => 'fal', 'slug' => 'fal', 'protocol' => 'fal', 'base_url' => 'https://fal.run', 'is_enabled' => true]);
        $model = AiModelProfile::create(['provider_id' => $provider->id, 'model_id' => 'minimax/h3-max/director', 'display_name' => 'Director', 'category' => 'video']);
        $revision = MediaCapabilityRevision::create([
            'ai_model_profile_id' => $model->id, 'operation' => 'realtime_video', 'contract_version' => 2,
            'revision' => 1, 'status' => 'published', 'definition' => ['operation' => 'realtime_video'], 'source_hash' => str_repeat('a', 64),
        ]);
        $session = RealtimeMediaSession::create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'capability_revision_id' => $revision->id,
            'model' => $model->model_id, 'model_label' => 'Director', 'request_key' => str_repeat('1', 64),
            'payload_fingerprint' => str_repeat('2', 64), 'connection_fingerprint' => str_repeat('3', 64),
            'session_token_hash' => str_repeat('4', 64), 'capability_hash' => str_repeat('5', 64),
            'capability_snapshot' => [], 'provider_bindings' => [], 'normalized_inputs' => [], 'asset_ids' => [],
            'recording_asset_ids' => $recordingIds, 'control_updates' => [], 'billing_reservation' => [],
            'price_tokens' => 10, 'billing_status' => 'charged', 'status' => 'connected', 'max_session_seconds' => 60,
        ]);
        $session->forceFill(['created_at' => now()->subDays(10)])->save();

        return $session;
    }
}
