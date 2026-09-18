<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaToolJob;
use App\Services\MediaToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class MediaToolController extends Controller
{
    public function capabilities(MediaToolService $tools): JsonResponse
    {
        return response()->json($tools->capabilities());
    }

    public function history(Request $request, MediaToolService $tools): JsonResponse
    {
        $input = $request->validate(['kind' => ['nullable', Rule::in(['download', 'convert'])]]);
        $query = MediaToolJob::query()->where('user_id', $request->user()->id);
        if (! empty($input['kind'])) {
            $query->where('kind', $input['kind']);
        }
        $active = (clone $query)->whereIn('status', ['pending', 'processing'])->latest()->get();
        $recent = (clone $query)->whereIn('status', ['completed', 'failed', 'cancelled'])->latest()->limit(50)->get();

        return response()->json(['jobs' => $active->concat($recent)->sortByDesc('created_at')->values()->map(fn (MediaToolJob $job) => $tools->payload($job))]);
    }

    public function download(Request $request, MediaToolService $tools): JsonResponse
    {
        $input = $request->validate(['url' => ['required', 'string', 'max:2048'], 'format' => ['required', Rule::in(['mp4', 'webm', 'mp3'])]]);

        return response()->json(['job' => $tools->payload($tools->download($request->user(), $input))], 202);
    }

    public function convert(Request $request, MediaToolService $tools): JsonResponse
    {
        $input = $request->validate([
            'file' => ['required', 'file', 'max:'.intdiv(min(128 * 1024 * 1024, (int) config('media_tools.max_upload_bytes', 128 * 1024 * 1024)), 1024)],
            'format' => ['required', Rule::in(array_keys(MediaToolService::MIME_TYPES))],
        ]);

        return response()->json(['job' => $tools->payload($tools->convert($request->user(), $request->file('file'), $input['format']))], 202);
    }

    public function show(Request $request, string $jobId, MediaToolService $tools): JsonResponse
    {
        return response()->json(['job' => $tools->payload($this->owned($request, $jobId))]);
    }

    public function cancel(Request $request, string $jobId, MediaToolService $tools): JsonResponse
    {
        return response()->json(['job' => $tools->payload($tools->cancel($request->user(), $jobId))]);
    }

    public function asset(Request $request, string $jobId, MediaToolService $tools): BinaryFileResponse
    {
        $job = $this->owned($request, $jobId);
        $response = response()->file($tools->assetPath($job), [
            'Content-Type' => MediaToolService::MIME_TYPES[$job->format],
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
        $response->setContentDisposition($request->boolean('download') ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE, 'media-'.$job->job_id.'.'.$job->format);

        return $response;
    }

    private function owned(Request $request, string $jobId): MediaToolJob
    {
        return MediaToolJob::query()->where('user_id', $request->user()->id)->where('job_id', $jobId)->firstOrFail();
    }
}
