<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Media\AssetService;
use App\Media\Enums\InputRole;
use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\MediaAsset;
use App\Models\ThreeDJob;
use App\Models\User;
use App\Models\VideoJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetController extends Controller
{
    /** Only owned, reusable uploads; pagination avoids loading the full asset library. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'media_type' => ['required', Rule::in(['image', 'audio', 'video'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $assets = MediaAsset::query()->where('user_id', $request->user()->id)
            ->where('media_type', $validated['media_type'])->where('retention_status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()->paginate(24);

        return response()->json([
            'assets' => $assets->getCollection()->map(fn (MediaAsset $asset): array => [
                'id' => $asset->id, 'media_type' => $asset->media_type, 'mime' => $asset->mime,
                'size_bytes' => $asset->size_bytes, 'created_at' => $asset->created_at?->toISOString(),
                'preview_url' => '/api/media/assets/'.$asset->id,
            ])->all(),
            'page' => $assets->currentPage(), 'last_page' => $assets->lastPage(),
        ]);
    }

    public function show(Request $request, MediaAsset $asset, AssetService $assets): StreamedResponse
    {
        abort_unless($asset->user_id === $request->user()->id, 404);

        return $assets->deliver($asset);
    }

    public function destroy(Request $request, MediaAsset $asset): JsonResponse
    {
        abort_unless($asset->user_id === $request->user()->id, 404);
        $deleted = DB::transaction(function () use ($request, $asset): int {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $locked = MediaAsset::query()->lockForUpdate()->findOrFail($asset->id);
            foreach ([ImageJob::class, VideoJob::class, AudioJob::class, ThreeDJob::class] as $jobs) {
                if ($jobs::query()->where('user_id', $locked->user_id)->whereIn('status', ['pending', 'processing'])
                    ->whereJsonContains('reference_asset_ids', $locked->id)->exists()) {
                    throw ValidationException::withMessages(['asset' => 'Referensi sedang digunakan oleh pekerjaan aktif. Tunggu atau batalkan pekerjaan sebelum menghapus.']);
                }
            }
            if ($locked->retention_status === 'deleted') {
                return 0;
            }
            $disk = Storage::disk($locked->storage_disk);
            abort_if($disk->exists($locked->storage_path) && ! $disk->delete($locked->storage_path), 503, 'Berkas belum dapat dihapus. Coba lagi.');
            // Preserve provenance for finished jobs, but revoke both owner and provider delivery.
            $locked->update(['retention_status' => 'deleted']);

            return 1;
        });

        return response()->json(['deleted_count' => $deleted]);
    }

    public function upload(Request $request, AssetService $assets): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'role' => ['required', 'string', Rule::in(array_map(static fn (InputRole $r): string => $r->value, InputRole::cases()))],
        ]);

        try {
            $asset = $assets->store($request->user(), $request->file('file'), InputRole::from($validated['role']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['asset' => [
            'id' => $asset->id, 'media_type' => $asset->media_type, 'role' => $asset->role,
            'size_bytes' => $asset->size_bytes, 'mime' => $asset->mime,
        ]], 201);
    }

    /** Signed, cookie-independent delivery for provider fetch (route uses the 'signed' middleware). */
    public function deliver(MediaAsset $asset, AssetService $assets): StreamedResponse
    {
        return $assets->deliver($asset);
    }
}
