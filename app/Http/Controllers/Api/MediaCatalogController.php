<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiProxyException;
use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Services\MediaCatalogService;
use App\Services\MediaModelConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function bulk(Request $request, AiProviderProfile $provider, MediaCatalogService $catalog): JsonResponse
    {
        $input = $request->validate([
            'items' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:model_id,revision_id,token_cost,price_unit,max_session_seconds'],
            'items.*.model_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.revision_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.token_cost' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.price_unit' => ['required', Rule::in(['request'])],
            'items.*.max_session_seconds' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'expected_count' => ['required', 'integer', 'min:1', 'max:50'],
            'action' => ['required', Rule::in(['review', 'publish'])],
            'reviewed' => ['required', 'accepted'], 'confirm' => ['required', 'accepted'],
        ]);
        abort_if(count($input['items']) !== (int) $input['expected_count'], 409, 'The selected row count changed. Review the selection again.');
        $items = array_map(static fn ($item) => [...$item, 'token_cost' => (int) $item['token_cost']], $input['items']);

        return response()->json($catalog->bulk($provider, $request->user(), $items, $input['action']));
    }

    public function capabilities(AiModelProfile $model): JsonResponse
    {
        $revisions = MediaCapabilityRevision::where('ai_model_profile_id', $model->id)->orderBy('operation')->orderByDesc('revision')->get();

        return response()->json([
            'model' => ['id' => $model->id, 'token_cost' => $model->token_cost,
                'price_unit' => MediaModelConfig::catalogPriceUnit($model), 'category' => $model->category,
                'available' => $model->is_available, 'enabled' => $model->is_enabled],
            'account_verification' => [
                'authenticated' => $model->provider?->authenticated_at !== null,
                'healthy' => $model->provider?->status === 'healthy', 'enabled' => (bool) $model->provider?->is_enabled,
                'generation_verification' => 'not_recorded',
            ],
            'revisions' => $revisions->map(fn ($revision) => $revision->adminPayload())->all(),
            'active_revision_ids' => $revisions->where('status', 'published')->pluck('id')->values()->all(),
        ]);
    }

    public function review(Request $request, MediaCapabilityRevision $revision, MediaCatalogService $catalog): JsonResponse
    {
        $input = $request->validate(['reviewed' => ['required', 'accepted'], 'ui_metadata' => ['sometimes', 'array'], ...$this->priceReviewRules()]);

        return response()->json(['revision' => $catalog->transition($revision, $request->user(), 'review', true, $input['ui_metadata'] ?? null, $input['price_review'] ?? null)->adminPayload()]);
    }

    public function publish(Request $request, MediaCapabilityRevision $revision, MediaCatalogService $catalog): JsonResponse
    {
        $input = $request->validate(['reviewed' => ['sometimes', 'boolean'], ...$this->priceReviewRules()]);

        return response()->json(['revision' => $catalog->transition($revision, $request->user(), 'publish', $request->boolean('reviewed'), pricing: $input['price_review'] ?? null)->adminPayload()]);
    }

    public function disable(Request $request, MediaCapabilityRevision $revision, MediaCatalogService $catalog): JsonResponse
    {
        return response()->json(['revision' => $catalog->transition($revision, $request->user(), 'disable')->adminPayload()]);
    }

    public function rollback(Request $request, MediaCapabilityRevision $revision, MediaCatalogService $catalog): JsonResponse
    {
        return response()->json(['revision' => $catalog->transition($revision, $request->user(), 'rollback')->adminPayload()]);
    }

    private function priceReviewRules(): array
    {
        return [
            'price_review' => ['sometimes', 'array:token_cost,unit,variable_configuration,max_session_seconds'],
            // `accepted` is an implicit rule; without the exclusion it would reject every request lacking a price review.
            'price_review.token_cost' => ['exclude_without:price_review', 'required', 'integer', 'min:1', 'max:2147483647'],
            'price_review.unit' => ['exclude_without:price_review', 'required', Rule::in(['request', 'generation', 'second'])],
            'price_review.variable_configuration' => ['exclude_without:price_review', 'required', 'boolean', 'accepted'],
            'price_review.max_session_seconds' => ['exclude_without:price_review', 'sometimes', 'integer', 'min:1', 'max:60'],
        ];
    }
}
