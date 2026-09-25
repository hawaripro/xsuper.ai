<?php

namespace App\Http\Controllers\Api;

use App\Http\Api\ApiErrorResponse;
use App\Http\Controllers\Concerns\ReadsSchemaInputs;
use App\Http\Controllers\Controller;
use App\Http\MediaFileResponse;
use App\Media\AssetService;
use App\Media\Enums\InputRole;
use App\Media\MediaUrlPresenter;
use App\Media\OpenAiImageInputs;
use App\Models\User;
use App\Models\WorkspaceMediaSubmission;
use App\Services\WorkspaceMediaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaApiController extends Controller
{
    use ReadsSchemaInputs;

    public function __construct(private readonly WorkspaceMediaService $media) {}

    public function models(Request $request): JsonResponse
    {
        $filters = $request->validate(['kind' => ['nullable', 'string', 'max:30'], 'q' => ['nullable', 'string', 'max:200'],
            'cursor' => ['nullable', 'string', 'max:100']]);
        $allowed = $request->attributes->get('api_key')->allowed_models;
        $page = $this->media->models($this->user($request), [...$filters, 'queued_only' => true,
            ...($allowed ? ['allowed_models' => $allowed] : [])]);
        $models = array_map(static fn (array $model): array => [
            'id' => $model['model_id'], 'name' => $model['name'], 'kind' => $model['category'], 'description' => $model['description'],
            'operations' => array_map(static fn (array $operation): array => array_intersect_key($operation,
                array_flip(['operation', 'output_kind', 'price_tokens', 'price_unit'])), $model['operations']),
        ], $page['models']);

        return response()->json(['object' => 'list', 'data' => $models, 'next_cursor' => $page['next_cursor']]);
    }

    public function model(Request $request, string $model): JsonResponse
    {
        if (! $this->allowed($request, $model)) {
            return ApiErrorResponse::openAi('This model is unavailable.', 'invalid_request_error', 'not_found', 404);
        }
        $details = $this->media->capabilities($this->user($request), $model);
        $operations = [];
        foreach ($details['capabilities'] as $operation => $capability) {
            if (($capability['execution']['transport'] ?? null) === 'realtime') {
                continue;
            }
            $operations[$operation] = ['output_kind' => $capability['output_kind'], 'price_tokens' => $capability['price_tokens'],
                'price_unit' => $capability['price_unit'], 'billing' => $capability['billing'], 'inputs' => $capability['inputs'],
                'params' => $capability['params'], 'input_schema' => $capability['input_schema'] ?? null, 'examples' => $capability['examples'] ?? []];
        }
        abort_if($operations === [], 404);

        return response()->json(['id' => $model, 'name' => $details['model']['name'], 'kind' => $details['model']['category'], 'operations' => $operations]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model' => ['required', 'string', 'max:160'], 'operation' => ['required', 'string', 'max:100'], 'inputs' => ['present', 'array'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:100'], 'pro' => ['sometimes', 'boolean'],
            'billing_seconds' => ['sometimes', 'nullable', 'integer', 'min:1'], 'rights_confirmed' => ['sometimes', 'boolean'],
        ]);
        if (! $this->allowed($request, $data['model'])) {
            return $this->modelDenied();
        }
        $data['inputs'] = $this->schemaInputs($request, $data['inputs']);
        foreach (['count', 'billing_seconds'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        foreach (['pro', 'rights_confirmed'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (bool) $data[$field];
            }
        }
        $jobs = $this->submit($request, $data);
        $payload = $this->media->payload($jobs[0], new MediaUrlPresenter(true));
        $response = array_intersect_key($payload, array_flip(['id', 'model', 'operation', 'status', 'stage', 'price_tokens', 'created_at']));
        // Native batch jobs have separate lifecycle/ownership identities. None is hidden from the caller.
        if (count($jobs) > 1) {
            $response['generation_ids'] = array_map(fn (Model $job): string => $this->media->payload($job)['id'], $jobs);
            $response['total_tokens'] = array_sum(array_map(static fn (Model $job): int => (int) $job->price_tokens, $jobs));
        }

        return response()->json(['object' => 'media.generation', ...$response], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['kind' => ['nullable', 'string', 'max:30'], 'cursor' => ['nullable', 'string', 'max:100']]);
        $page = $this->media->history($this->user($request), $filters, new MediaUrlPresenter(true));

        return response()->json(['object' => 'list', 'data' => array_map($this->generation(...), $page['jobs']), 'next_cursor' => $page['next_cursor']]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->generation($this->media->payload($this->media->owned($this->user($request), $id), new MediaUrlPresenter(true))));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return response()->json($this->generation($this->media->payload($this->media->cancel($this->user($request), $id), new MediaUrlPresenter(true))));
    }

    public function download(Request $request, string $id, string $outputId): BinaryFileResponse
    {
        return MediaFileResponse::make($this->media->resolveOwnedOutput($this->user($request), $id, $outputId));
    }

    /** The signature binds owner, job, output and expiry; no key or session is required. */
    public function signedDownload(Request $request, string $id, string $outputId): BinaryFileResponse
    {
        $owner = User::query()->findOrFail($request->query('owner'));

        return MediaFileResponse::make($this->media->resolveOwnedOutput($owner, $id, $outputId));
    }

    public function upload(Request $request, AssetService $assets): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file'], 'role' => ['required', Rule::enum(InputRole::class)]]);
        $asset = $assets->store($this->user($request), $request->file('file'), InputRole::from($data['role']));

        return response()->json(['id' => $asset->id, 'object' => 'file', 'kind' => $asset->media_type,
            'mime' => $asset->mime, 'bytes' => $asset->size_bytes, 'role' => $asset->role], 201);
    }

    public function images(Request $request, OpenAiImageInputs $mapper): JsonResponse
    {
        $data = $request->validate(['model' => ['required', 'string', 'max:160'], 'prompt' => ['required', 'string', 'max:100000'],
            'n' => ['sometimes', 'integer', 'min:1', 'max:4'], 'size' => ['sometimes', 'string', 'max:40'],
            'response_format' => ['sometimes', 'string', 'in:url,b64_json']]);
        if (! $this->allowed($request, $data['model'])) {
            return $this->modelDenied();
        }
        $capability = $this->media->capabilities($this->user($request), $data['model'])['capabilities']['text_to_image'] ?? null;
        if ($capability === null || ($capability['execution']['transport'] ?? null) === 'realtime') {
            throw ValidationException::withMessages(['model' => 'This model has no queued text_to_image operation.']);
        }
        $count = (int) ($data['n'] ?? 1);
        $mapped = $mapper->map($capability, $data['prompt'], $count, $data['size'] ?? null);
        $jobs = $this->submit($request, ['model' => $data['model'], 'operation' => 'text_to_image', ...$mapped]);
        $deadline = microtime(true) + max(1, (int) config('media.api_sync_wait_seconds', 120));
        $urls = new MediaUrlPresenter(true);
        do {
            $payloads = array_map(fn (Model $job): array => $this->media->payload($job->refresh(), $urls), $jobs);
            foreach ($payloads as $payload) {
                if (in_array($payload['status'], ['failed', 'cancelled', 'save_failed', 'uncertain'], true)) {
                    return ApiErrorResponse::openAi($payload['error'] ?: 'The image request could not be completed.', 'server_error', 'generation_failed', 502);
                }
            }
            if (count(array_filter($payloads, static fn (array $payload): bool => $payload['status'] === 'completed')) === count($jobs)) {
                $outputs = [];
                foreach ($payloads as $payload) {
                    foreach ($payload['outputs'] as $output) {
                        if ($output['kind'] !== 'image') {
                            continue;
                        }
                        if (($data['response_format'] ?? 'url') === 'b64_json') {
                            $original = $this->media->resolveOwnedOutput($this->user($request), $payload['id'], $output['id']);
                            $outputs[] = ['b64_json' => base64_encode(Storage::disk($original['disk'])->get($original['path']))];
                        } else {
                            // OpenAI tools fetch without propagating Authorization. Give them an expiring capability URL.
                            $outputs[] = ['url' => $output['signed_url']];
                        }
                    }
                }
                if (count($outputs) !== $count) {
                    return ApiErrorResponse::openAi('The completed request did not retain the requested number of images. Check the generation status.',
                        'server_error', 'incomplete_outputs', 502);
                }

                return response()->json(['created' => $jobs[0]->created_at->getTimestamp(), 'data' => $outputs]);
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            Sleep::usleep((int) min(250000, max(1, ($deadline - microtime(true)) * 1000000)));
        } while (true);
        $id = $payloads[0]['id'];
        $poll = route('api.media.generations.show', ['id' => $id]);

        return ApiErrorResponse::openAi('Generation '.$id.' is still running. Poll '.$poll.' with your API key; do not resubmit with a new idempotency key.',
            'server_error', 'generation_timeout', 504);
    }

    private function submit(Request $request, array $data): array
    {
        $user = $this->user($request);
        $prefix = 'api:'.$request->attributes->get('api_key')->id.':';
        $supplied = $request->header('Idempotency-Key');
        if ($supplied !== null && (trim($supplied) === '' || strlen($prefix.$supplied) > 128)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Use a non-empty Idempotency-Key of at most '.(128 - strlen($prefix)).' bytes.']);
        }
        $data['idempotency_key'] = $prefix.($supplied === null ? Str::uuid() : trim($supplied));
        // Replay is validated by the service against the original schema, even after price/availability changes.
        $replay = WorkspaceMediaSubmission::query()->where('user_id', $user->id)
            ->where('request_key', hash('sha256', $data['idempotency_key']))->exists();
        if (! $replay) {
            $quote = $this->media->quote($user, $data);
            $data['expected_capability_hash'] = $quote['capability_hash'];
            $data['expected_price_tokens'] = intdiv($quote['total_tokens'], $quote['count']);
        }

        return $this->media->create($user, $data);
    }

    private function generation(array $payload): array
    {
        return ['object' => 'media.generation', ...array_intersect_key($payload,
            array_flip(['id', 'model', 'operation', 'status', 'stage', 'progress', 'error', 'price_tokens', 'billing_status', 'created_at'])),
            'outputs' => array_map(static fn (array $output): array => array_intersect_key($output,
                array_flip(['id', 'kind', 'mime', 'bytes', 'name', 'url', 'signed_url', 'signed_url_expires_at'])), $payload['outputs']),
            'result' => $payload['result_data']];
    }

    private function user(Request $request): User
    {
        return $request->attributes->get('api_user');
    }

    private function allowed(Request $request, string $model): bool
    {
        $allowed = $request->attributes->get('api_key')->allowed_models;

        return ! $allowed || in_array($model, $allowed, true);
    }

    private function modelDenied(): JsonResponse
    {
        return ApiErrorResponse::openAi('This model is not allowed for this API key.', 'permission_error', 'model_not_allowed', 403);
    }
}
