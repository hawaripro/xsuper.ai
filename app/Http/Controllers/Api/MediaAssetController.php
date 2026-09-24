<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Media\AssetService;
use App\Media\Enums\InputRole;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\StorageQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetController extends Controller
{
    public function policy(AssetService $assets): JsonResponse
    {
        return response()->json($assets->policy())->header('Cache-Control', 'private, no-store');
    }

    /** Only owned, reusable uploads; pagination avoids loading the full asset library. */
    public function index(Request $request, AssetService $service): JsonResponse
    {
        $validated = $request->validate([
            'media_type' => ['sometimes', Rule::in(['image', 'audio', 'video', 'model3d', 'document', 'file'])],
            'role' => ['sometimes', Rule::in(array_keys($service->policy()['roles']))],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $assets = MediaAsset::query()->where('user_id', $request->user()->id)
            ->when(isset($validated['media_type']), fn ($query) => $query->where('media_type', $validated['media_type']))
            ->when(isset($validated['role']), fn ($query) => $query->where('role', $validated['role']))
            ->where('retention_status', 'active')->where('signature_ok', true)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()->paginate(24);

        return response()->json([
            'assets' => $assets->getCollection()
                ->filter(fn (MediaAsset $asset): bool => Storage::disk($asset->storage_disk)->exists($asset->storage_path))
                ->map(fn (MediaAsset $asset): array => $service->present($asset))->values()->all(),
            'page' => $assets->currentPage(), 'last_page' => $assets->lastPage(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, MediaAsset $asset, AssetService $assets): StreamedResponse
    {
        abort_unless($asset->user_id === $request->user()->id, 404);

        return $assets->deliver($asset, $request->boolean('download'));
    }

    public function destroy(Request $request, MediaAsset $asset, StorageQuotaService $storage): JsonResponse
    {
        abort_unless($asset->user_id === $request->user()->id, 404);
        $deleted = DB::transaction(function () use ($request, $asset, $storage): int {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $locked = MediaAsset::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($asset->id);
            $storage->assertAssetUnreferenced($locked);
            if ($locked->retention_status === 'deleted') {
                return 0;
            }
            if (! $storage->pathIsShared($user, $locked->storage_disk, $locked->storage_path, $locked->id)) {
                $disk = Storage::disk($locked->storage_disk);
                abort_if($disk->exists($locked->storage_path) && ! $disk->delete($locked->storage_path), 503, 'The file could not be deleted. Try again.');
            }
            // Retain immutable provenance while revoking delivery from this registry entry.
            $locked->update(['retention_status' => 'deleted']);

            return 1;
        });

        return response()->json(['deleted_count' => $deleted]);
    }

    public function upload(Request $request, AssetService $assets): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required_without:files', 'prohibits:files', 'file'],
            'files' => ['required_without:file', 'prohibits:file', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file'],
            'role' => ['required', 'string', Rule::in(array_keys($assets->policy()['roles']))],
        ]);
        try {
            $role = InputRole::from($validated['role']);
            if ($request->hasFile('file')) {
                $asset = $assets->store($request->user(), $request->file('file'), $role);

                return response()->json(['asset' => $assets->present($asset)], 201);
            }
            $stored = $assets->storeMany($request->user(), array_values($request->file('files')), $role);

            return response()->json(['assets' => array_map(fn (MediaAsset $asset): array => $assets->present($asset), $stored)], 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    /** Signed, cookie-independent delivery for provider fetch (route uses the signed middleware). */
    public function deliver(MediaAsset $asset, AssetService $assets): StreamedResponse
    {
        return $assets->deliver($asset);
    }
}
