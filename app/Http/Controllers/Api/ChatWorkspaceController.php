<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatWorkspaceController extends Controller
{
    public function __construct(private readonly ChatWorkspaceService $workspaces) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->workspaces->workspaces($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $workspace = $this->workspaces->createWorkspace($request->user(), $validated['name']);

        return response()->json(['workspace' => $this->workspaces->presentWorkspace($workspace)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $workspace): JsonResponse
    {
        $workspace = $this->workspaces->updateWorkspace($request->user(), $workspace, $request->only(['name', 'notes', 'version']));

        return response()->json(['workspace' => $this->workspaces->presentWorkspace($workspace)])->header('Cache-Control', 'private, no-store');
    }

    public function createConversation(Request $request): JsonResponse
    {
        $conversation = $this->workspaces->createConversation($request->user(), $request->only(['conversation_id', 'workspace_id', 'title']));

        return response()->json(['conversation' => $conversation], 201)->header('Cache-Control', 'private, no-store');
    }

    public function updateConversation(Request $request, string $conversationId): JsonResponse
    {
        $conversation = $this->workspaces->updateConversation($request->user(), $conversationId, $request->only(['title', 'pinned', 'workspace_id']));

        return response()->json(['conversation' => $conversation])->header('Cache-Control', 'private, no-store');
    }
}
