<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Media\AssetService;
use App\Media\Enums\InputRole;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetController extends Controller
{
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
