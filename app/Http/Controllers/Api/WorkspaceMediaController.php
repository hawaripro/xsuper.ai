<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImageGenerationException;
use App\Http\Controllers\Concerns\ReadsSchemaInputs;
use App\Http\Controllers\Controller;
use App\Services\WorkspaceMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class WorkspaceMediaController extends Controller
{
    use ReadsSchemaInputs;

    public function __construct(private readonly WorkspaceMediaService $media) {}

    public function models(Request $request): JsonResponse
    {
        $filters = $request->validate(['kind' => ['nullable', 'string', 'max:30'], 'q' => ['nullable', 'string', 'max:200'],
            'cursor' => ['nullable', 'string', 'max:100']]);

        return response()->json($this->media->models($request->user(), $filters));
    }

    public function capabilities(Request $request): JsonResponse
    {
        $data = $request->validate(['model' => ['required', 'string', 'max:160']]);

        return response()->json($this->media->capabilities($request->user(), $data['model']));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['kind' => ['nullable', 'string', 'max:30'], 'cursor' => ['nullable', 'string', 'max:100']]);

        return response()->json($this->media->history($request->user(), $filters));
    }

    /** Clears one studio's finished history; active or still-referenced originals are retained and counted. */
    public function clear(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'in:image,video,audio,avatar,model3d']]);

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
        return $this->file($request, $id, $outputId, false);
    }

    public function preview(Request $request, string $id, string $outputId): BinaryFileResponse
    {
        return $this->file($request, $id, $outputId, true);
    }

    private function file(Request $request, string $id, string $outputId, bool $preview): BinaryFileResponse
    {
        $output = $this->media->resolveOwnedOutput($request->user(), $id, $outputId);
        $safePreview = in_array($output['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif',
            'video/mp4', 'video/webm', 'audio/mp4', 'audio/webm', 'audio/wav', 'audio/mpeg', 'audio/flac', 'audio/ogg', 'model/gltf-binary'], true);
        abort_if($preview && (! $output['previewable'] || ! $safePreview), 415, 'This original is available as a download only.');
        $response = response()->file(Storage::disk($output['disk'])->path($output['path']), [
            'Content-Type' => $output['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Cross-Origin-Resource-Policy' => 'same-origin', 'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
        [$name, $fallback] = self::dispositionNames((string) $output['name']);
        $response->setContentDisposition($preview ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, $fallback);

        return $response;
    }

    /** @return array{0: string, 1: string} The UTF-8 name sent as filename* and a printable-ASCII filename fallback. */
    private static function dispositionNames(string $name): array
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', '-', mb_scrub($name, 'UTF-8')), ' .');
        $name = mb_substr($name, 0, 180) ?: 'output';
        // Byte-level split is UTF-8 safe: "." never occurs inside a multibyte sequence.
        $dot = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);
        $extension = $dot === false ? '' : (string) preg_replace('/[^A-Za-z0-9]+/', '', Str::ascii(substr($name, $dot + 1)));
        $fallback = trim((string) preg_replace('/[^\x20-\x7e]|[%"\\\\\/]/', '_', Str::ascii($stem)), ' .');
        if (preg_match('/[A-Za-z0-9]/', $fallback) !== 1) {
            $fallback = 'output';
        }

        return [$name, $fallback.($extension === '' ? '' : '.'.$extension)];
    }
}
