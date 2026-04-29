<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserToken;
use App\Models\VideoJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class VideoController extends Controller
{
    // Model pricing
    const MODEL_TOKENS = [
        'sora-2' => ['tokens' => 35, 'duration' => [10, 15]],
        'veo-3.1-fast' => ['tokens' => 60, 'duration' => [8]],
        'veo-3.1-quality' => ['tokens' => 250, 'duration' => [8]],
    ];

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'prompt' => 'required|string|max:2000',
            'model' => 'required|string|in:sora-2,veo-3.1-fast,veo-3.1-quality',
            'aspect_ratio' => 'required|string|in:16:9,9:16',
            'count' => 'required|integer|min:1|max:10',
            'mode' => 'required|string|in:prompt,ab_testing',
            'ugc_variation' => 'nullable|boolean',
            'cta' => 'nullable|string|max:500',
            'settings' => 'nullable|array',
        ]);

        $user = Auth::user();
        $modelInfo = self::MODEL_TOKENS[$validated['model']] ?? null;

        if (!$modelInfo) {
            return response()->json(['message' => 'Model tidak valid'], 422);
        }

        $tokensPerVideo = $modelInfo['tokens'];
        $totalTokens = $tokensPerVideo * $validated['count'];
        $balance = UserToken::getBalance($user->id);

        if ($balance < $totalTokens) {
            return response()->json([
                'message' => 'Token tidak cukup. Butuh ' . $totalTokens . ' token, saldo Anda ' . $balance . ' token.',
                'insufficient' => true,
                'required' => $totalTokens,
                'balance' => $balance,
            ], 402);
        }

        // Deduct tokens
        UserToken::deduct($user->id, $totalTokens, "Generate {$validated['count']}x video ({$validated['model']})");

        // Create video jobs
        $jobs = [];
        for ($i = 0; $i < $validated['count']; $i++) {
            $prompt = $validated['prompt'];

            // UGC variation: modify prompt slightly for each video
            if ($validated['ugc_variation'] ?? false) {
                $prompt = $this->applyUgcVariation($prompt, $i);
            }

            $job = VideoJob::create([
                'user_id' => $user->id,
                'job_id' => 'vj_' . Str::random(16),
                'mode' => $validated['mode'],
                'prompt' => $prompt,
                'model' => $validated['model'],
                'aspect_ratio' => $validated['aspect_ratio'],
                'duration' => $modelInfo['duration'][0],
                'tokens_used' => $tokensPerVideo,
                'settings' => [
                    'cta' => $validated['cta'] ?? null,
                    'ugc_variation' => $validated['ugc_variation'] ?? false,
                    'extra' => $validated['settings'] ?? [],
                ],
                'status' => 'pending',
            ]);

            $jobs[] = $job;
        }

        // TODO: Dispatch actual video generation job to queue
        // For now, mark as processing
        VideoJob::whereIn('id', collect($jobs)->pluck('id'))->update(['status' => 'processing']);

        return response()->json([
            'message' => "Berhasil membuat {$validated['count']} video job",
            'jobs' => $jobs,
            'tokens_used' => $totalTokens,
            'balance' => UserToken::getBalance($user->id),
        ]);
    }

    public function history()
    {
        $jobs = VideoJob::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['jobs' => $jobs]);
    }

    public function status(string $jobId)
    {
        $job = VideoJob::where('user_id', Auth::id())
            ->where('job_id', $jobId)
            ->firstOrFail();

        return response()->json(['job' => $job]);
    }

    public function models()
    {
        return response()->json([
            'models' => [
                [
                    'id' => 'sora-2',
                    'name' => 'Sora 2',
                    'provider' => 'OpenAI',
                    'durations' => [10, 15],
                    'tokens' => 35,
                    'features' => ['Stable', 'High Quality'],
                    'badge' => null,
                ],
                [
                    'id' => 'veo-3.1-fast',
                    'name' => 'Veo 3.1 Fast',
                    'provider' => 'Google',
                    'durations' => [8],
                    'tokens' => 60,
                    'features' => ['Fast Render', '8s Fixed'],
                    'badge' => 'BARU',
                ],
                [
                    'id' => 'veo-3.1-quality',
                    'name' => 'Veo 3.1 Quality',
                    'provider' => 'Google',
                    'durations' => [8],
                    'tokens' => 250,
                    'features' => ['1080p', 'Audio', 'HD'],
                    'badge' => 'HD + Audio',
                ],
            ],
        ]);
    }

    private function applyUgcVariation(string $prompt, int $index): string
    {
        $variations = [
            'Shot from a different angle, with a unique presenter style.',
            'Alternative talent with casual, friendly delivery.',
            'Dynamic camera movement, energetic presentation.',
            'Calm and professional tone, close-up focus.',
            'Lifestyle setting, natural lighting, authentic feel.',
            'Studio setup, clean background, polished look.',
            'Outdoor setting, vibrant colors, enthusiastic delivery.',
            'Minimalist approach, soft lighting, gentle narration.',
            'Fast-paced editing, modern transitions, upbeat mood.',
            'Slow reveal, dramatic lighting, premium feel.',
        ];

        if ($index > 0 && isset($variations[$index - 1])) {
            $prompt .= ' [Variation: ' . $variations[$index - 1] . ']';
        }

        return $prompt;
    }
}
