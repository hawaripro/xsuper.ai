<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatArtifactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatArtifactController extends Controller
{
    public function __construct(private readonly ChatArtifactService $artifacts) {}

    public function index(Request $request, string $key): JsonResponse
    {
        return response()->json(['artifacts' => $this->artifacts->index($request->user(), $key)]);
    }

    public function store(Request $request, string $key): JsonResponse
    {
        $data = Validator::make($this->payload($request), [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'filename' => ['required_without:generated_output', 'string', 'max:180'],
            'mime' => ['sometimes', 'nullable', 'string', 'max:100'],
            'kind' => ['sometimes', 'nullable', Rule::in(['text', 'code', 'html', 'svg', 'markdown', 'json', 'table'])],
            'content' => ['sometimes', 'string', 'max:'.ChatArtifactService::MAX_TEXT_BYTES],
            'source_message_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'source_operation_id' => ['sometimes', 'nullable', 'uuid'],
            'generated_output' => ['sometimes', 'array:job_id,output_id', 'required_array_keys:job_id,output_id'],
            'generated_output.job_id' => ['required_with:generated_output', 'string', 'max:160', 'regex:/^(?:(?:image|video|audio|model3d):)?[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i'],
            'generated_output.output_id' => ['required_with:generated_output', 'string', 'max:100'],
        ])->validate();

        return response()->json(['artifact' => $this->artifacts->create($request->user(), $key, $data)], 201);
    }

    public function show(Request $request, string $artifact): JsonResponse
    {
        return response()->json(['artifact' => $this->artifacts->show($request->user(), $artifact)]);
    }

    public function revise(Request $request, string $artifact): JsonResponse
    {
        $data = Validator::make($this->payload($request), [
            'base_version' => ['required', 'integer', 'min:1'],
            'content' => ['present', 'string', 'max:'.ChatArtifactService::MAX_TEXT_BYTES],
            'filename' => ['sometimes', 'string', 'max:180'],
        ])->validate();

        return response()->json(['artifact' => $this->artifacts->revise($request->user(), $artifact, $data)], 201);
    }

    public function revision(Request $request, string $artifact, string $revision): JsonResponse
    {
        return response()->json(['artifact' => $this->artifacts->show($request->user(), $artifact, $revision)]);
    }

    public function download(Request $request, string $artifact): StreamedResponse
    {
        $data = $request->validate(['revision' => ['nullable', 'uuid']]);

        return $this->artifacts->download($request->user(), $artifact, $data['revision'] ?? null);
    }

    public function preview(Request $request, string $artifact): StreamedResponse
    {
        $data = $request->validate(['revision' => ['nullable', 'uuid']]);

        return $this->artifacts->download($request->user(), $artifact, $data['revision'] ?? null, true);
    }

    private function payload(Request $request): array
    {
        abort_unless($request->isJson(), 415, 'Send an application/json request.');
        // TrimStrings / ConvertEmptyStringsToNull must not change the artifact's original bytes.
        try {
            $value = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['content' => 'The request is not valid JSON.']);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw ValidationException::withMessages(['content' => 'Send a JSON object.']);
        }

        return $value;
    }
}
