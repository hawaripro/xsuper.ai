<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\MediaToolJob;
use App\Models\User;
use App\Models\VideoJob;
use App\Services\GeneratedAudioStore;
use App\Services\GeneratedVideoStore;
use App\Services\VideoReferenceStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The member library: every file the account owns, generated or uploaded, in one
 * list. Each source keeps its own private asset route; this endpoint only
 * aggregates metadata and never exposes storage paths.
 */
class LibraryController extends Controller
{
    public const TYPES = ['image', 'video', 'audio', 'reference', 'download', 'convert'];

    private const SOURCE_LIMIT = 300;

    public function index(Request $request, VideoReferenceStore $references): JsonResponse
    {
        $input = $request->validate([
            'type' => ['nullable', Rule::in(['all', ...self::TYPES])],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:6', 'max:60'],
        ]);
        $user = $request->user();
        $type = $input['type'] ?? 'all';
        $perPage = (int) ($input['per_page'] ?? 24);
        $page = (int) ($input['page'] ?? 1);

        $items = collect([
            ...$this->images($user),
            ...$this->videos($user, $references),
            ...$this->audio($user),
            ...$this->tools($user),
        ])->sortByDesc('created_at')->values();

        $counts = ['all' => $items->count()];
        foreach (self::TYPES as $name) {
            $counts[$name] = $items->where('type', $name)->count();
        }
        $filtered = $type === 'all' ? $items : $items->where('type', $type)->values();
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return response()->json([
            'items' => $filtered->slice(($page - 1) * $perPage, $perPage)->values(),
            'counts' => $counts,
            'pagination' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function images(User $user): array
    {
        $disk = Storage::disk('local');
        $items = [];
        ImageJob::query()->where('user_id', $user->id)->where('status', 'completed')
            ->latest('id')->limit(self::SOURCE_LIMIT)->get(['job_id', 'model', 'prompt', 'asset_paths', 'created_at'])
            ->each(function (ImageJob $job) use (&$items, $disk): void {
                foreach ((array) $job->asset_paths as $index => $asset) {
                    if (! is_array($asset) || ! is_string($asset['path'] ?? null) || ! $disk->exists($asset['path'])) {
                        continue;
                    }
                    $items[] = $this->item('image', $job->job_id.':'.$index, mb_substr(trim($job->prompt), 0, 120) ?: 'Gambar', [
                        'mime_type' => (string) ($asset['mime'] ?? 'image/png'),
                        'size_bytes' => $disk->size($asset['path']),
                        'preview_url' => '/api/images/'.$job->job_id.'/assets/'.$index,
                        'download_url' => '/api/images/'.$job->job_id.'/assets/'.$index,
                        'page_url' => '/generate-image?job='.$job->job_id,
                        'model' => $job->model,
                        'created_at' => $job->created_at,
                    ]);
                }
            });

        return $items;
    }

    private function videos(User $user, VideoReferenceStore $references): array
    {
        $disk = Storage::disk('local');
        $items = [];
        VideoJob::query()->where('user_id', $user->id)
            ->where(fn ($query) => $query->where('status', 'completed')->orWhere('has_reference', true))
            ->latest('id')->limit(self::SOURCE_LIMIT)->get()
            ->each(function (VideoJob $job) use (&$items, $disk, $references): void {
                $path = GeneratedVideoStore::path($job->job_id);
                if ($job->status === 'completed' && $job->video_url === '/api/v/'.$job->job_id.'/asset' && $disk->exists($path)) {
                    $items[] = $this->item('video', $job->job_id, mb_substr(trim($job->prompt), 0, 120) ?: 'Video', [
                        'mime_type' => 'video/mp4',
                        'size_bytes' => $disk->size($path),
                        'duration' => $job->duration,
                        'preview_url' => '/api/v/'.$job->job_id.'/asset',
                        'download_url' => '/api/v/'.$job->job_id.'/asset',
                        'poster_url' => is_string($job->thumbnail_url) && str_starts_with($job->thumbnail_url, '/api/') ? $job->thumbnail_url : null,
                        'page_url' => '/video?job='.$job->job_id,
                        'model' => $job->model,
                        'created_at' => $job->completed_at ?? $job->created_at,
                    ]);
                }
                if ($job->has_reference && ($reference = $references->existingPath($job)) !== null) {
                    $items[] = $this->item('reference', $job->job_id.':reference', 'Referensi video · '.(mb_substr(trim($job->prompt), 0, 80) ?: $job->job_id), [
                        'mime_type' => (string) $job->reference_mime_type,
                        'size_bytes' => $disk->size($reference),
                        'preview_url' => '/api/v/'.$job->job_id.'/reference',
                        'download_url' => '/api/v/'.$job->job_id.'/reference',
                        'page_url' => '/video?job='.$job->job_id,
                        'model' => $job->model,
                        'created_at' => $job->created_at,
                    ]);
                }
            });

        return $items;
    }

    private function audio(User $user): array
    {
        $disk = Storage::disk('local');
        $items = [];
        AudioJob::query()->where('user_id', $user->id)->where('status', 'completed')
            ->latest('id')->limit(self::SOURCE_LIMIT)->get()
            ->each(function (AudioJob $job) use (&$items, $disk): void {
                $path = GeneratedAudioStore::path($job->job_id);
                if ($job->audio_url !== '/api/audio/'.$job->job_id.'/asset' || $job->audio_path !== $path || ! $disk->exists($path)) {
                    return;
                }
                $items[] = $this->item('audio', $job->job_id, mb_substr(trim($job->prompt), 0, 120) ?: 'Audio', [
                    'mime_type' => (string) $job->mime_type,
                    'size_bytes' => $disk->size($path),
                    'duration' => $job->duration,
                    'preview_url' => '/api/audio/'.$job->job_id.'/asset',
                    'download_url' => '/api/audio/'.$job->job_id.'/asset',
                    'page_url' => '/audio?job='.$job->job_id,
                    'model' => $job->model,
                    'kind' => $job->mode,
                    'created_at' => $job->completed_at ?? $job->created_at,
                ]);
            });

        return $items;
    }

    private function tools(User $user): array
    {
        return MediaToolJob::query()->where('user_id', $user->id)->where('status', 'completed')
            ->latest('id')->limit(self::SOURCE_LIMIT)->get()
            ->map(fn (MediaToolJob $job): array => $this->item($job->kind, $job->job_id, $job->title ?: $job->input_name ?: strtoupper($job->format), [
                'mime_type' => (string) $job->mime_type,
                'size_bytes' => $job->size_bytes,
                'duration' => $job->duration,
                'preview_url' => '/api/media-tools/'.$job->job_id.'/asset',
                'download_url' => '/api/media-tools/'.$job->job_id.'/asset?download=1',
                'page_url' => ($job->kind === 'download' ? '/downloads' : '/converter').'?job='.$job->job_id,
                'format' => $job->format,
                'deletable' => true,
                'delete_url' => '/api/media-tools/'.$job->job_id,
                'created_at' => $job->completed_at ?? $job->created_at,
            ]))->all();
    }

    private function item(string $type, string $id, string $title, array $fields): array
    {
        $created = $fields['created_at'] ?? null;

        return [
            'id' => $type.':'.$id, 'type' => $type, 'title' => $title,
            'mime_type' => null, 'size_bytes' => null, 'duration' => null, 'preview_url' => null,
            'download_url' => null, 'poster_url' => null, 'page_url' => null, 'model' => null,
            'format' => null, 'kind' => null, 'deletable' => false, 'delete_url' => null,
            ...$fields,
            'created_at' => $created?->toISOString(),
        ];
    }
}
