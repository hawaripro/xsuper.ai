<?php

namespace Tests\Feature\Media;

use App\Media\AssetService;
use App\Media\Enums\InputRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
}
