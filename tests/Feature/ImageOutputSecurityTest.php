<?php

namespace Tests\Feature;

use App\Exceptions\AiProxyException;
use App\Models\ImageJob;
use App\Models\User;
use App\Services\GeneratedImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImageOutputSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public static function invalidCollections(): array
    {
        $image = ['b64_json' => self::PNG];

        return [
            'path-like provider key' => [['../../../sentinel' => $image]],
            'sparse numeric collection' => [[1 => $image]],
            'empty collection' => [[]],
            'non-object item after valid image' => [[$image, 'invalid']],
            'excessive collection' => [array_fill(0, 11, $image)],
        ];
    }

    #[DataProvider('invalidCollections')]
    public function test_invalid_collections_cannot_write_or_remove_any_file(array $items): void
    {
        $job = ImageJob::create(['user_id' => User::factory()->create()->id, 'job_id' => (string) Str::uuid(),
            'model' => 'fixture', 'prompt' => 'fixture', 'status' => 'processing']);
        $prior = 'generated/images/'.$job->job_id.'/0.png';
        Storage::disk('local')->put('sentinel.png', 'outside-owned-job');
        Storage::disk('local')->put($prior, 'previous-attempt');
        $this->expectException(AiProxyException::class);
        try {
            app(GeneratedImageStore::class)->persist($job, $items);
        } finally {
            $this->assertSame('outside-owned-job', Storage::disk('local')->get('sentinel.png'));
            $this->assertSame('previous-attempt', Storage::disk('local')->get($prior));
        }
    }

    public function test_historical_foreign_path_cannot_be_read_or_deleted_through_owned_job(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreignId = (string) Str::uuid();
        $foreignPath = 'generated/images/'.$foreignId.'/0.png';
        $foreign = ImageJob::create(['user_id' => $other->id, 'job_id' => $foreignId, 'model' => 'fixture', 'prompt' => 'private',
            'status' => 'completed', 'asset_paths' => [['path' => $foreignPath, 'mime' => 'image/png']]]);
        Storage::disk('local')->put($foreignPath, base64_decode(self::PNG));
        $job = ImageJob::create(['user_id' => $owner->id, 'job_id' => (string) Str::uuid(), 'model' => 'fixture', 'prompt' => 'tampered',
            'status' => 'completed', 'asset_paths' => [['path' => $foreignPath, 'mime' => 'image/png']]]);

        $this->actingAs($owner)->get('/api/images/'.$job->job_id.'/assets/0')->assertNotFound();
        $this->deleteJson('/api/images/'.$job->job_id)->assertOk();
        $this->assertDatabaseHas('image_jobs', ['id' => $foreign->id]);
        $this->assertSame(base64_decode(self::PNG), Storage::disk('local')->get($foreignPath));
    }

    public function test_valid_outputs_keep_order_and_only_owned_files_are_deleted(): void
    {
        $owner = User::factory()->create();
        $job = ImageJob::create(['user_id' => $owner->id, 'job_id' => (string) Str::uuid(), 'model' => 'fixture', 'prompt' => 'ordered output',
            'status' => 'processing', 'quantity' => 2]);
        $urls = app(GeneratedImageStore::class)->persist($job, [['b64_json' => self::PNG], ['b64_json' => self::PNG]]);
        $job->forceFill(['status' => 'completed', 'result_urls' => $urls])->save();
        Storage::disk('local')->put('sentinel.png', 'unrelated');

        foreach ($urls as $index => $url) {
            $this->assertSame('generated/images/'.$job->job_id.'/'.$index.'.png', $job->asset_paths[$index]['path']);
            $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->assertSame(base64_decode(self::PNG), Storage::disk('local')->get($job->asset_paths[$index]['path']));
        }
        $this->deleteJson('/api/images/'.$job->job_id)->assertOk();
        $this->assertSame([], Storage::disk('local')->allFiles('generated/images/'.$job->job_id));
        $this->assertSame('unrelated', Storage::disk('local')->get('sentinel.png'));
    }
}
