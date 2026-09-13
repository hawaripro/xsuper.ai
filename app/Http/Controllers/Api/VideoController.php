<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsageRate;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Models\Wallet;
use App\Services\UsageBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VideoController extends Controller
{
    // Model pricing
    const MODEL_TOKENS = [
        'sora-2' => ['tokens' => 35, 'duration' => [10, 15]],
        'veo-3.1-fast' => ['tokens' => 60, 'duration' => [8]],
        'veo-3.1-quality' => ['tokens' => 250, 'duration' => [8]],
    ];

    public function generate(Request $request, UsageBillingService $billing)
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

        $user = $request->user();
        $modelInfo = self::MODEL_TOKENS[$validated['model']] ?? null;
        if (! $modelInfo) {
            return response()->json(['message' => 'Model tidak valid'], 422);
        }

        $paygEnabled = UsageRate::forMeter('video', 'unit', $validated['model']) !== null;
        $tokensPerVideo = $paygEnabled ? 0 : $modelInfo['tokens'];
        $totalTokens = $tokensPerVideo * $validated['count'];
        if (! $paygEnabled) {
            $balance = UserToken::getBalance($user->id);
            if ($balance < $totalTokens) {
                return response()->json([
                    'message' => 'Token tidak cukup. Butuh '.$totalTokens.' token, saldo Anda '.$balance.' token.',
                    'insufficient' => true,
                    'required' => $totalTokens,
                    'balance' => $balance,
                ], 402);
            }
        }

        $jobs = DB::transaction(function () use ($billing, $modelInfo, $paygEnabled, $tokensPerVideo, $totalTokens, $user, $validated): array {
            $jobs = [];
            for ($i = 0; $i < $validated['count']; $i++) {
                $prompt = $validated['prompt'];
                if ($validated['ugc_variation'] ?? false) {
                    $prompt = $this->applyUgcVariation($prompt, $i);
                }

                $jobId = 'vj_'.Str::random(16);
                $billingReference = 'video:'.$jobId;
                $billingReservation = $billing->reserveUnit($user->id, 'video', $validated['model'], 1, $billingReference);
                $jobs[] = VideoJob::create([
                    'user_id' => $user->id,
                    'job_id' => $jobId,
                    'mode' => $validated['mode'],
                    'prompt' => $prompt,
                    'model' => $validated['model'],
                    'aspect_ratio' => $validated['aspect_ratio'],
                    'duration' => $modelInfo['duration'][0],
                    'tokens_used' => $tokensPerVideo,
                    'billing_reserved_microusd' => $billingReservation['amount_microusd'],
                    'billing_reference_id' => $billingReference,
                    'billing_status' => $billingReservation['amount_microusd'] > 0 ? 'reserved' : 'none',
                    'settings' => [
                        'cta' => $validated['cta'] ?? null,
                        'ugc_variation' => $validated['ugc_variation'] ?? false,
                        'extra' => $validated['settings'] ?? [],
                    ],
                    'status' => 'processing',
                ]);
            }

            if (! $paygEnabled && ! UserToken::deduct($user->id, $totalTokens, "Generate {$validated['count']}x video ({$validated['model']})")) {
                throw ValidationException::withMessages(['tokens' => 'Token balance changed. Please retry.']);
            }

            return $jobs;
        });

        return response()->json([
            'message' => "Berhasil membuat {$validated['count']} video job",
            'jobs' => $jobs,
            'tokens_used' => $totalTokens,
            'balance' => UserToken::getBalance($user->id),
            'billing_mode' => $paygEnabled ? 'payg' : 'legacy_tokens',
            'cost_usd_reserved' => collect($jobs)->sum('billing_reserved_microusd') / 1_000_000,
            'balance_usd' => Wallet::balance($user->id) / 1_000_000,
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
        $job = DB::transaction(function () use ($jobId): VideoJob {
            $job = VideoJob::query()
                ->lockForUpdate()
                ->where('user_id', Auth::id())
                ->where('job_id', $jobId)
                ->firstOrFail();

            if ($job->status === 'completed' && $job->billing_status === 'reserved') {
                Wallet::settle($job->user_id, [
                    'reference_id' => $job->billing_reference_id,
                    'amount_microusd' => $job->billing_reserved_microusd,
                ], $job->billing_reserved_microusd, [
                    'service' => 'video',
                    'model' => $job->model,
                    'meter' => 'unit',
                    'quantity' => 1,
                    'description' => "Completed video: {$job->model}",
                ]);
                $job->update(['billing_status' => 'settled']);
            } elseif ($job->status === 'failed' && $job->billing_status === 'reserved') {
                Wallet::release($job->user_id, [
                    'reference_id' => $job->billing_reference_id,
                    'amount_microusd' => $job->billing_reserved_microusd,
                ], 'Failed video generation');
                $job->update(['billing_status' => 'released']);
            }

            return $job->fresh();
        });

        return response()->json(['job' => $job]);
    }

    public function models()
    {
        $rates = UsageRate::active()
            ->where('service', 'video')
            ->where('meter', 'unit')
            ->get()
            ->keyBy('model');
        $models = [
            ['id' => 'sora-2', 'name' => 'Sora 2', 'provider' => '[OI]', 'durations' => [10, 15], 'tokens' => 35, 'features' => ['Stable', 'High Quality'], 'badge' => null],
            ['id' => 'veo-3.1-fast', 'name' => 'Veo 3.1 Fast', 'provider' => 'Google', 'durations' => [8], 'tokens' => 60, 'features' => ['Fast Render', '8s Fixed'], 'badge' => 'BARU'],
            ['id' => 'veo-3.1-quality', 'name' => 'Veo 3.1 Quality', 'provider' => 'Google', 'durations' => [8], 'tokens' => 250, 'features' => ['1080p', 'Audio', 'HD'], 'badge' => 'HD + Audio'],
        ];

        return response()->json([
            'models' => array_map(function (array $model) use ($rates): array {
                $rate = $rates->get($model['id']);

                return [
                    ...$model,
                    'tokens' => $rate ? 0 : $model['tokens'],
                    'billing_mode' => $rate ? 'payg' : 'legacy_tokens',
                    'price_usd' => $rate ? (float) $rate->price_usd : null,
                    'price_idr' => $rate ? (float) $rate->price_idr : null,
                    'unit' => $rate?->unit,
                ];
            }, $models),
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
            $prompt .= ' [Variation: '.$variations[$index - 1].']';
        }

        return $prompt;
    }
}
