<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImageGenerationException;
use App\Http\Controllers\Concerns\ReadsSchemaInputs;
use App\Http\Controllers\Controller;
use App\Http\MediaFileResponse;
use App\Services\WorkspaceMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkspaceMediaController extends Controller
{
    use ReadsSchemaInputs;

    public function __construct(private readonly WorkspaceMediaService $media) {}

    public function models(Request $request): JsonResponse
    {
        $filters = $request->validate(['kind' => ['nullable', 'string', 'max:30'], 'q' => ['nullable', 'string', 'max:200'],
            'cursor' => ['nullable', 'string', 'max:100'], 'locale' => ['nullable', 'string', 'in:id,en']]);

        return response()->json($this->media->models($request->user(), $filters));
    }

    public function capabilities(Request $request): JsonResponse
    {
        $data = $request->validate(['model' => ['required', 'string', 'max:160'], 'locale' => ['nullable', 'string', 'in:id,en']]);

        return response()->json($this->media->capabilities($request->user(), $data['model'], $data['locale'] ?? null));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['kind' => ['nullable', 'string', 'max:30'], 'cursor' => ['nullable', 'string', 'max:100']]);

        return response()->json($this->media->history($request->user(), $filters));
    }

    /** Clears one studio's finished history; active or still-referenced originals are retained and counted. */
    public function clear(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'in:image,video,audio,avatar,model3d,other']]);

        return response()->json($this->media->clear($request->user(), $data['kind']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model' => ['required', 'string', 'max:160'], 'operation' => ['required', 'string', 'max:100'],
            'inputs' => ['present', 'array'], 'expected_capability_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'expected_price_tokens' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'idempotency_key' => ['required', 'string', 'max:128'], 'conversation_id' => ['nullable', 'string', 'max:160'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:100'], 'pro' => ['sometimes', 'boolean'],
            'rights_confirmed' => ['sometimes', 'boolean'], 'billing_seconds' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Native video authoring: product/UGC composition, call to action and per-video variation.
            'mode' => ['sometimes', 'nullable', 'string', 'in:prompt,ab_testing'], 'cta' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ugc_variation' => ['sometimes', 'boolean'],
        ]);
        $data['inputs'] = $this->schemaInputs($request, $data['inputs']);
        // Laravel's scalar validators accept numeric strings; the API uses a canonical typed request for replay.
        $data['expected_price_tokens'] = (int) $data['expected_price_tokens'];
        foreach (['count', 'billing_seconds'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        foreach (['pro', 'rights_confirmed', 'ugc_variation'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (bool) $data[$field];
            }
        }
        try {
            $jobs = array_map(fn ($job): array => $this->media->payload($job), $this->media->create($request->user(), $data));

            return response()->json(['job' => $jobs[0], 'jobs' => $jobs], 202);
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->responseStatus());
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['job' => $this->media->payload($this->media->owned($request->user(), $id))]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        try {
            return response()->json(['job' => $this->media->payload($this->media->cancel($request->user(), $id))]);
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->responseStatus());
        }
    }

    public function retrySave(Request $request, string $id): JsonResponse
    {
        try {
            return response()->json(['job' => $this->media->payload($this->media->retrySave($request->user(), $id))], 202);
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->responseStatus());
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $this->media->delete($request->user(), $id);

            return response()->json(['deleted' => true]);
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->responseStatus());
        }
    }

    public function download(Request $request, string $id, string $outputId): BinaryFileResponse
    {
        return MediaFileResponse::make($this->media->resolveOwnedOutput($request->user(), $id, $outputId));
    }

    public function preview(Request $request, string $id, string $outputId): BinaryFileResponse
    {
        return MediaFileResponse::make($this->media->resolveOwnedOutput($request->user(), $id, $outputId), true);
    }
}
