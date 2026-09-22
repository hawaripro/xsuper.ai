<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiProxyException;
use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Services\MediaCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaCatalogController extends Controller
{
    public function discover(Request $request, AiProviderProfile $provider, MediaCatalogService $catalog): JsonResponse
    {
        $input = $request->validate(['cursor' => ['nullable', 'string', 'max:2048', 'regex:/^[A-Za-z0-9+\/_=\-]+$/D'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:10']]);
        try {
            return response()->json($catalog->discover($provider, $request->user(), $input['cursor'] ?? null, $input['limit'] ?? 10));
        } catch (AiProxyException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->responseStatus());
        }
    }

    public function capabilities(AiModelProfile $model): JsonResponse
    {
        $revisions = MediaCapabilityRevision::where('ai_model_profile_id', $model->id)->orderBy('operation')->orderByDesc('revision')->get();

        return response()->json([
            'revisions' => $revisions->map(fn ($revision) => $revision->adminPayload())->all(),
            'active_revision_ids' => $revisions->where('status', 'published')->pluck('id')->values()->all(),
        ]);
    }

    public function review(Request $request, MediaCapabilityRevision $revision, MediaCatalogService $catalog): JsonResponse
    {
        $input = $request->validate(['reviewed' => ['required', 'accepted'], 'ui_metadata' => ['sometimes', 'array']]);

        return response()->json(['revision' => $catalog->transition($revision, $request->user(), 'review', true, $input['ui_metadata'] ?? null)->adminPayload()]);
    }

    public function publish(Request $request, MediaCapabilityRevision $revision, MediaCatalogService $catalog): JsonResponse
    {
        $request->validate(['reviewed' => ['sometimes', 'boolean']]);

        return response()->json(['revision' => $catalog->transition($revision, $request->user(), 'publish', $request->boolean('reviewed'))->adminPayload()]);
    }

    public function disable(Request $request, MediaCapabilityRevision $revision, MediaCatalogService $catalog): JsonResponse
    {
        return response()->json(['revision' => $catalog->transition($revision, $request->user(), 'disable')->adminPayload()]);
    }

    public function rollback(Request $request, MediaCapabilityRevision $revision, MediaCatalogService $catalog): JsonResponse
    {
        return response()->json(['revision' => $catalog->transition($revision, $request->user(), 'rollback')->adminPayload()]);
    }
}
