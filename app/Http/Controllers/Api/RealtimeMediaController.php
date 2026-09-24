<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiProxyException;
use App\Exceptions\ImageGenerationException;
use App\Http\Controllers\Concerns\ReadsSchemaInputs;
use App\Http\Controllers\Controller;
use App\Services\RealtimeMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner-scoped WMA sessions; the browser owns media, the server owns credentials, lease and billing. */
class RealtimeMediaController extends Controller
{
    use ReadsSchemaInputs;

    public function __construct(private readonly RealtimeMediaService $realtime) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['cursor' => ['nullable', 'string', 'max:100']]);

        return response()->json($this->realtime->history($request->user(), $filters));
    }

    public function show(Request $request, string $session): JsonResponse
    {
        return response()->json(['session' => $this->realtime->payload($this->realtime->show($request->user(), $session))]);
    }

    public function ice(Request $request): JsonResponse
    {
        $data = $request->validate(['model' => ['required', 'string', 'max:160'], 'operation' => ['required', 'string', 'max:100']]);

        return $this->guarded(fn (): JsonResponse => response()->json($this->realtime->iceServers($request->user(), $data)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model' => ['required', 'string', 'max:160'], 'operation' => ['required', 'string', 'max:100'],
            'inputs' => ['present', 'array'], 'expected_capability_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'expected_price_tokens' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'idempotency_key' => ['required', 'string', 'max:128'], 'sdp' => ['required', 'string', 'max:262144'],
        ]);
        $data['inputs'] = $this->schemaInputs($request, $data['inputs']);
        // Laravel's integer rule accepts numeric strings; replay compares the canonical typed request.
        $data['expected_price_tokens'] = (int) $data['expected_price_tokens'];

        return $this->guarded(function () use ($request, $data): JsonResponse {
            $result = $this->realtime->start($request->user(), $data);
            $session = $result['session'];
            $payload = ['session' => $this->realtime->payload($session)];
            if (isset($result['answer'])) {
                return response()->json([...$payload, 'answer' => $result['answer'], 'configure' => $result['configure'],
                    'session_token' => $result['session_token'], 'protocol' => $result['protocol']], 201);
            }

            return match ($session->status) {
                'preparing', 'negotiating' => response()->json([...$payload, 'pending' => true], 202),
                'uncertain' => response()->json(['message' => $session->error_message, 'uncertain' => true, ...$payload], 504),
                'failed' => response()->json(['message' => $session->error_message, ...$payload], 502),
                default => response()->json(['message' => $session->billing_status === 'charged'
                    ? 'This session was accepted and has already ended. Start a new session to continue.'
                    : 'This session ended before it started. Start a new session to continue.', ...$payload], 409),
            };
        });
    }

    public function heartbeat(Request $request, string $session): JsonResponse
    {
        $data = $request->validate(['session_token' => ['required', 'string', 'max:128'], 'configured' => ['sometimes', 'boolean']]);

        return $this->guarded(function () use ($request, $session, $data): JsonResponse {
            $result = $this->realtime->heartbeat($request->user(), $session,
                ['session_token' => $data['session_token'], 'configured' => (bool) ($data['configured'] ?? false)]);

            return response()->json(['alive' => $result['alive'], 'degraded' => $result['degraded'] ?? false,
                'session' => $this->realtime->payload($result['session'])]);
        });
    }

    public function input(Request $request, string $session): JsonResponse
    {
        $data = $request->validate(['session_token' => ['required', 'string', 'max:128'], 'inputs' => ['present', 'array']]);
        $data['inputs'] = $this->schemaInputs($request, $data['inputs']);

        return $this->guarded(function () use ($request, $session, $data): JsonResponse {
            $result = $this->realtime->prepareInput($request->user(), $session, $data);

            return response()->json(['message' => $result['message'], 'prompt_version' => $result['prompt_version'],
                'session' => $this->realtime->payload($result['session'])]);
        });
    }

    public function close(Request $request, string $session): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'string', 'in:stopped,left,exhausted,failed,expired'],
            'detail' => ['sometimes', 'nullable', 'string', 'max:48'],
        ]);

        return response()->json(['session' => $this->realtime->payload($this->realtime->close($request->user(), $session, $data))]);
    }

    public function recording(Request $request, string $session): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        return $this->guarded(function () use ($request, $session): JsonResponse {
            $result = $this->realtime->attachRecording($request->user(), $session, $request->file('file'));

            return response()->json(['recording' => $result['recording'], 'session' => $this->realtime->payload($result['session'])], 201);
        });
    }

    /** Domain refusals keep their HTTP meaning; validation, ownership and quota errors use framework rendering. */
    private function guarded(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->responseStatus());
        } catch (AiProxyException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->responseStatus());
        }
    }
}
