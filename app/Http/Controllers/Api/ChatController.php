<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use App\Services\ChatCapabilityService;
use App\Services\ChatOperationService;
use App\Services\ChatWorkspaceService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatOperationService $operations,
        private readonly ChatCapabilityService $capabilities,
        private readonly ChatWorkspaceService $workspaces,
    ) {}

    public function models(Request $request)
    {
        $user = $request->user();
        $models = $this->capabilities->models($user);

        UsageLog::record($user->id, 'models', [
            'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'credit' => 0,
        ], 'web');

        return response()->json(['models' => $models]);
    }

    /**
     * Get ALL models across all categories (chat, image, video, audio)
     * Used by the full-page chat UI — respects ALL permissions
     */
    public function allModels(Request $request)
    {
        $user = $request->user();
        $permissions = $user->getPermissions();
        $models = $this->capabilities->models($user, true);

        // Filter by category permissions
        $models = array_values(array_filter($models, function ($model) use ($permissions) {
            $category = $model['category'] ?? 'chat';

            // Chat permission controls chat category
            if ($category === 'chat' && ! ($permissions['chat'] ?? true)) {
                return false;
            }
            // Video generator permission controls video category
            if ($category === 'video' && ! ($permissions['video_generator'] ?? false)) {
                return false;
            }

            // Image and audio are not gated here — visibility follows model availability.
            return true;
        }));

        return response()->json([
            'models' => $models,
            'permissions' => $permissions,
        ]);
    }

    public function send(Request $request)
    {
        $input = $request->validate([
            'messages' => 'required|array|min:1|max:1000',
            'messages.*.role' => 'required|string|in:user,assistant,system',
            'messages.*.content' => 'present',
            'model' => 'required|string|max:160',
            'conversation_id' => 'required_if:stream_protocol,workspace_v2|nullable|string|max:100',
            'client_request_id' => 'required_if:stream_protocol,workspace_v2|nullable|uuid',
            'stream_protocol' => 'nullable|in:workspace_v2',
            'workspace_id' => 'nullable|integer|min:1',
            'continuation' => 'sometimes|boolean',
            'retry_of' => 'nullable|integer|min:1',
            'continuation_of' => 'nullable|integer|min:1',
            'attachment_ids' => 'sometimes|array|max:8',
            'attachment_ids.*' => 'required|uuid|distinct',
            'tools' => 'sometimes|array',
            'tools.*' => 'boolean',
        ]);
        [$operation] = $this->operations->admit($request->user(), $input);

        return $this->operations->stream(
            $operation,
            ($input['stream_protocol'] ?? null) === 'workspace_v2',
        );
    }

    public function history(Request $request)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:120',
            'workspace_id' => 'nullable|integer|min:1',
            'cursor' => 'nullable|string|max:2048',
        ]);

        return response()->json($this->workspaces->history($request->user(), $filters));
    }

    public function conversation(Request $request, string $conversationId)
    {
        return response()->json($this->workspaces->conversation($request->user(), $conversationId));
    }

    public function deleteConversation(Request $request, string $conversationId)
    {
        $this->workspaces->deleteConversation($request->user(), $conversationId);

        return response()->json(['success' => true]);
    }

    public function capabilities(Request $request)
    {
        $input = $request->validate(['model' => 'required|string|max:160']);

        return response()->json($this->capabilities->resolve($request->user(), $input['model'])['metadata'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function operation(Request $request, string $operationId)
    {
        return response()->json(['operation' => $this->operations->operation($request->user(), $operationId)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function stop(Request $request, string $operationId)
    {
        return response()->json(['operation' => $this->operations->stop($request->user(), $operationId)])
            ->header('Cache-Control', 'private, no-store');
    }
}
