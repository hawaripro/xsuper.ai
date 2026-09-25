<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Media\AssetService;
use App\Models\AudioJob;
use App\Models\ChatArtifact;
use App\Models\ImageJob;
use App\Models\MediaAsset;
use App\Models\MediaToolJob;
use App\Models\RealtimeMediaSession;
use App\Models\ThreeDJob;
use App\Models\User;
use App\Models\VideoJob;
use App\Models\WorkspaceMediaJob;
use App\Services\GeneratedAudioStore;
use App\Services\GeneratedImageStore;
use App\Services\GeneratedModel3dStore;
use App\Services\GeneratedVideoStore;
use App\Services\StorageQuotaService;
use App\Services\VideoReferenceStore;
use App\Services\WorkspaceMediaService;
use App\Support\StudioLink;
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
    public const TYPES = ['image', 'video', 'avatar', 'audio', 'model3d', 'reference', 'document', 'file', 'artifact', 'download', 'convert', 'rembg'];

    private const SOURCE_LIMIT = 300;

    /** Studio kinds that can reuse an uploaded file; documents, generic files and realtime recordings have none. */
    private const ASSET_KINDS = ['image', 'video', 'audio', 'model3d'];

    public function index(Request $request, VideoReferenceStore $references, StorageQuotaService $storage): JsonResponse
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
            ...$this->models3d($user),
            ...$this->references($user),
            ...$this->tools($user),
            ...$this->workspaceOutputs($user, $storage),
            ...$this->artifacts($user),
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
            'storage' => $storage->summary($user),
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
                foreach (GeneratedImageStore::outputs($job) as $index => $asset) {
                    if (! $disk->exists($asset['path'])) {
                        continue;
                    }
                    $items[] = $this->item('image', $job->job_id.':'.$index, mb_substr(trim($job->prompt), 0, 120) ?: 'Gambar', [
                        'mime_type' => (string) ($asset['mime'] ?? 'image/png'),
                        'size_bytes' => $disk->size($asset['path']),
                        'preview_url' => '/api/images/'.$job->job_id.'/assets/'.$index,
                        'download_url' => '/api/images/'.$job->job_id.'/assets/'.$index,
                        'page_url' => StudioLink::to('image', ['job' => $job->job_id]),
                        'deletable' => true,
                        'delete_url' => '/api/images/'.$job->job_id,
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
                $avatar = $job->mode === 'avatar';
                $base = $avatar ? '/api/avatar/' : '/api/v/';
                $kind = $avatar ? 'avatar' : 'video';
                if ($job->status === 'completed' && $job->video_url === '/api/v/'.$job->job_id.'/asset' && $disk->exists($path)) {
                    $items[] = $this->item($kind, $job->job_id, mb_substr(trim($job->prompt), 0, 120) ?: ($avatar ? 'Avatar' : 'Video'), [
                        'mime_type' => 'video/mp4',
                        'size_bytes' => $disk->size($path),
                        'duration' => $job->duration,
                        'preview_url' => $base.$job->job_id.'/asset',
                        'download_url' => $base.$job->job_id.'/asset',
                        'poster_url' => is_string($job->thumbnail_url) && str_starts_with($job->thumbnail_url, '/api/') ? $job->thumbnail_url : null,
                        'page_url' => StudioLink::to($kind, ['job' => $job->job_id]),
                        'deletable' => true,
                        'delete_url' => $base.$job->job_id,
                        'model' => $job->model,
                        'created_at' => $job->completed_at ?? $job->created_at,
                    ]);
                }
                if ($job->has_reference && empty($job->reference_asset_ids)) {
                    $reference = $references->existingPath($job);
                    if ($reference !== null) {
                        $items[] = $this->item('reference', $job->job_id.':reference', 'Referensi video · '.(mb_substr(trim($job->prompt), 0, 80) ?: $job->job_id), [
                            'mime_type' => (string) $job->reference_mime_type,
                            'size_bytes' => $disk->size($reference),
                            'preview_url' => '/api/v/'.$job->job_id.'/reference',
                            'download_url' => '/api/v/'.$job->job_id.'/reference',
                            'page_url' => StudioLink::to($kind, ['job' => $job->job_id]),
                            'deletable' => true,
                            'delete_url' => '/api/v/'.$job->job_id.'/reference',
                            'model' => $job->model,
                            'created_at' => $job->created_at,
                        ]);
                    }
                }
            });

        return $items;
    }

    private function references(User $user): array
    {
        $service = app(AssetService::class);
        $referenced = app(StorageQuotaService::class)->referencedAssetIds($user);
        $recordings = [];
        foreach (RealtimeMediaSession::query()->where('user_id', $user->id)->pluck('recording_asset_ids') as $ids) {
            foreach ($ids ?? [] as $id) {
                $recordings[$id] = true;
            }
        }

        return MediaAsset::query()->where('user_id', $user->id)->where('retention_status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()->limit(self::SOURCE_LIMIT)->get()
            ->filter(fn (MediaAsset $asset): bool => Storage::disk($asset->storage_disk)->exists($asset->storage_path))
            ->map(function (MediaAsset $asset) use ($service, $referenced, $recordings): array {
                $dto = $service->present($asset);
                // A saved realtime recording is the member's generated video, not a reusable upload.
                $recording = isset($recordings[$asset->id]) && $asset->media_type === 'video';
                $type = $recording ? 'video' : (in_array($asset->media_type, ['document', 'file', 'model3d'], true) ? $asset->media_type : 'reference');

                return $this->item($type, $asset->id, $dto['original_name'], [
                    'mime_type' => $asset->mime, 'size_bytes' => $asset->size_bytes, 'kind' => $asset->media_type,
                    'previewable' => $dto['previewable'], 'preview_url' => $dto['preview_url'], 'download_url' => $dto['download_url'],
                    'page_url' => match (true) {
                        $recording => null,
                        in_array($asset->role, ['avatar_photo', 'speech_audio'], true) => StudioLink::to('avatar'),
                        in_array($asset->media_type, self::ASSET_KINDS, true) => StudioLink::to($asset->media_type),
                        default => null,
                    },
                    'deletable' => ! isset($referenced[$asset->id]), 'delete_url' => '/api/media/assets/'.$asset->id,
                    'created_at' => $asset->created_at,
                ]);
            })->values()->all();
    }

    private function audio(User $user): array
    {
        $disk = Storage::disk('local');
        $items = [];
        AudioJob::query()->where('user_id', $user->id)->where('status', 'completed')
            ->latest('id')->limit(self::SOURCE_LIMIT)->get()
            ->each(function (AudioJob $job) use (&$items, $disk): void {
                $outputs = $job->outputs ?? [];
                foreach ($outputs as $index => $output) {
                    $path = GeneratedAudioStore::outputPath($job, $index);
                    if ($path === null || ! $disk->exists($path)) {
                        continue;
                    }
                    $title = mb_substr(trim($job->prompt), 0, 120) ?: 'Audio';
                    if (count($outputs) > 1) {
                        $title .= ' · Track '.($index + 1);
                    }
                    $url = '/api/audio/'.$job->job_id.'/assets/'.$index;
                    $items[] = $this->item('audio', $job->job_id.':'.$index, $title, [
                        'mime_type' => (string) $output['mime_type'],
                        'size_bytes' => $disk->size($path),
                        'duration' => $job->duration,
                        'preview_url' => $url, 'download_url' => $url,
                        'page_url' => StudioLink::to('audio', ['job' => $job->job_id, 'track' => $index]),
                        'deletable' => true, 'delete_url' => '/api/audio/'.$job->job_id,
                        'model' => $job->model, 'kind' => $job->mode,
                        'created_at' => $job->completed_at ?? $job->created_at,
                    ]);
                }
            });

        return $items;
    }

    private function models3d(User $user): array
    {
        $disk = Storage::disk('local');

        return ThreeDJob::query()->where('user_id', $user->id)->where('status', 'completed')
            ->latest('id')->limit(self::SOURCE_LIMIT)->get()
            ->filter(fn (ThreeDJob $job): bool => $job->model_path === GeneratedModel3dStore::path($job->job_id) && $disk->exists($job->model_path))
            ->map(fn (ThreeDJob $job): array => $this->item('model3d', $job->job_id, $job->model, [
                'mime_type' => 'model/gltf-binary', 'size_bytes' => $job->size_bytes,
                'format' => 'glb', 'previewable' => $job->previewable,
                'preview_url' => '/api/3d/'.$job->job_id.'/asset',
                'download_url' => '/api/3d/'.$job->job_id.'/asset',
                'page_url' => StudioLink::to('model3d', ['job' => $job->job_id]),
                'deletable' => true, 'delete_url' => '/api/3d/'.$job->job_id,
                'model' => $job->model, 'created_at' => $job->completed_at ?? $job->created_at,
            ]))->values()->all();
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
                'page_url' => (match ($job->kind) {
                    'download' => '/downloads', 'rembg' => '/remove-background', default => '/converter'
                }).'?job='.$job->job_id,
                'format' => $job->format,
                'deletable' => true,
                'delete_url' => '/api/media-tools/'.$job->job_id,
                'created_at' => $job->completed_at ?? $job->created_at,
            ]))->all();
    }

    private function workspaceOutputs(User $user, StorageQuotaService $storage): array
    {
        $items = [];
        $service = app(WorkspaceMediaService::class);
        WorkspaceMediaJob::query()->where('user_id', $user->id)->whereNotNull('asset_paths')
            ->latest('id')->limit(self::SOURCE_LIMIT)->get()->each(function (WorkspaceMediaJob $job) use ($user, $storage, $service, &$items): void {
                $dto = $service->payload($job);
                $canDelete = in_array($job->status, ['completed', 'failed', 'cancelled'], true)
                    && ! $storage->jobIsReferenced($user, $dto['id']);
                foreach ($dto['outputs'] ?? [] as $output) {
                    $type = match (true) {
                        $output['kind'] === 'video' && $job->operation === 'talking_avatar' => 'avatar',
                        in_array($output['kind'], ['image', 'video', 'audio', 'model3d', 'document'], true) => $output['kind'],
                        default => 'file',
                    };
                    $items[] = $this->item($type, 'workspace:'.$dto['id'].':'.$output['id'], $output['name'], [
                        'mime_type' => $output['mime'], 'size_bytes' => $output['bytes'] ?? null, 'kind' => $output['kind'],
                        'previewable' => $output['previewable'], 'preview_url' => $output['url'] ?? null,
                        'download_url' => $output['download_url'], 'page_url' => StudioLink::to(null, ['job' => $dto['id']]),
                        'deletable' => $canDelete, 'delete_url' => '/api/media/workspace/jobs/'.rawurlencode($dto['id']),
                        'model' => $job->model, 'created_at' => $job->completed_at ?? $job->created_at,
                    ]);
                }
            });

        return $items;
    }

    private function artifacts(User $user): array
    {
        return ChatArtifact::query()->where('user_id', $user->id)->with('currentRevision')
            ->latest()->limit(self::SOURCE_LIMIT)->get()
            ->filter(fn (ChatArtifact $artifact): bool => $artifact->currentRevision !== null
                && ($artifact->currentRevision->generated_job_id !== null || ($artifact->currentRevision->storage_path !== null
                    && Storage::disk($artifact->currentRevision->storage_disk)->exists($artifact->currentRevision->storage_path))))
            ->map(fn (ChatArtifact $artifact): array => $this->item('artifact', $artifact->id, $artifact->title, [
                'mime_type' => $artifact->currentRevision->mime, 'size_bytes' => $artifact->currentRevision->content_bytes,
                'kind' => $artifact->kind, 'previewable' => false,
                'download_url' => '/api/c/artifacts/'.$artifact->id.'/download?revision='.$artifact->current_revision_id,
                'page_url' => '/chat?conversation='.rawurlencode($artifact->conversation_id).'&artifact='.$artifact->id,
                'created_at' => $artifact->updated_at,
            ]))->values()->all();
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
