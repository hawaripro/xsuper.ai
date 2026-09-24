<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    public function __construct(private readonly ChatWorkspaceService $workspaces) {}

    public function index(Request $request, string $conversationId): JsonResponse
    {
        return response()->json($this->workspaces->listAttachments($request->user(), $conversationId))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $conversationId): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        try {
            $attachment = $this->workspaces->storeAttachment($request->user(), $conversationId, $request->file('file'));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['attachment' => $this->workspaces->presentAttachment($attachment, $conversationId)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $conversationId, string $attachment): JsonResponse
    {
        $this->workspaces->detachAttachment($request->user(), $conversationId, $attachment);

        return response()->json(['success' => true])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $conversationId, string $attachment): StreamedResponse
    {
        $file = $this->workspaces->ownedAttachment($request->user(), $conversationId, $attachment);
        $mime = $this->workspaces->previewMime($file);
        abort_if($mime === null, 415, 'Jenis berkas ini hanya dapat diunduh.');
        $asset = $file->asset;

        return Storage::disk($asset->storage_disk)->response($asset->storage_path, $file->name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'self'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ], 'inline');
    }

    public function download(Request $request, string $conversationId, string $attachment): StreamedResponse
    {
        $file = $this->workspaces->ownedAttachment($request->user(), $conversationId, $attachment);
        $asset = $file->asset;

        return Storage::disk($asset->storage_disk)->download($asset->storage_path, $file->name, [
            'Content-Type' => $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
