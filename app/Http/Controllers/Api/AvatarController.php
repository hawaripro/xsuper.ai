<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImageGenerationException;
use App\Http\Controllers\Controller;
use App\Media\CapabilityPresenter;
use App\Media\Enums\MediaOperation;
use App\Media\MediaActivation;
use App\Media\MediaGenerationCoordinator;
use App\Models\AiModelProfile;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Services\MediaModelConfig;
use App\Services\VideoGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AvatarController extends Controller
{
    public function models(Request $request, CapabilityPresenter $presenter, MediaActivation $activation): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->hasPermission('video_generator'), 403);
        $models = $activation->usesCoordinator($user)
            ? AiModelProfile::query()->with('provider')->where('category', 'avatar')
                ->where('is_enabled', true)->where('is_available', true)->orderBy('display_name')->get()
                ->filter(fn (AiModelProfile $model): bool => $model->token_cost > 0 && MediaModelConfig::allowedFor($user, $model))
                ->map(fn (AiModelProfile $model): array => [
                    ...MediaModelConfig::publicModel($model), 'capabilities' => $presenter->forModel($model),
                ])->filter(fn (array $model): bool => isset($model['capabilities']['talking_avatar']))->values()->all()
            : [];

        return response()->json(['models' => $models, 'balance' => UserToken::getBalance($user->id)]);
    }

    public function generate(Request $request, MediaGenerationCoordinator $coordinator, VideoGenerationService $videos): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->hasPermission('video_generator'), 403);
        $validated = $request->validate([
            'model' => ['required', 'string', 'max:160'],
            'operation' => ['required', Rule::in(['talking_avatar'])],
            'rights_confirmed' => ['required', 'accepted'],
            'prompt' => ['nullable', 'string', 'max:4000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'expected_price_tokens' => ['nullable', 'integer', 'min:1'],
            'expected_capability_hash' => ['nullable', 'string', 'size:64'],
        ]);
        $model = AiModelProfile::query()->with('provider')->where('model_id', $validated['model'])->first();
        if (! $model || $model->category !== 'avatar' || ! app(MediaActivation::class)->usesCoordinator($user)) {
            return response()->json(['message' => 'Model avatar tidak tersedia untuk akun Anda.'], 503);
        }
        $options = array_intersect_key($validated, array_flip(['idempotency_key', 'expected_price_tokens', 'expected_capability_hash']));
        $options['rights_confirmed'] = true;
        $raw = $request->except(['model', 'operation', 'rights_confirmed', 'idempotency_key', 'expected_price_tokens', 'expected_capability_hash']);
        if (($raw['prompt'] ?? null) === null) {
            unset($raw['prompt']);
        }
        try {
            $jobs = $coordinator->startVideo($user, $model, MediaOperation::TalkingAvatar, $raw, 'studio-avatar', $options);
        } catch (ImageGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'balance' => UserToken::getBalance($user->id)], $exception->responseStatus());
        }

        return response()->json([
            'jobs' => array_map(fn (VideoJob $job): array => $videos->payload($job), $jobs),
            'balance' => UserToken::getBalance($user->id), 'billing_mode' => 'tokens',
        ], 202);
    }

    public function history(Request $request, VideoGenerationService $videos): JsonResponse
    {
        $query = VideoJob::query()->where('user_id', $request->user()->id)->where('mode', 'avatar');
        $active = (clone $query)->whereIn('status', ['pending', 'processing'])->latest()->get();
        $recent = (clone $query)->whereIn('status', ['completed', 'failed'])->latest()->limit(50)->get();

        return response()->json([
            'jobs' => $active->concat($recent)->sortByDesc('created_at')->values()->map(fn (VideoJob $job): array => $videos->payload($job))->all(),
            'balance' => UserToken::getBalance($request->user()->id),
        ]);
    }
}
