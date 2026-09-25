<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Exceptions\ImageGenerationException;
use App\Jobs\PollAudioJob;
use App\Jobs\PollImageJob;
use App\Jobs\PollThreeDJob;
use App\Jobs\PollVideoJob;
use App\Jobs\PollWorkspaceMediaJob;
use App\Jobs\ProcessWorkspaceMediaJob;
use App\Media\AssetService;
use App\Media\CapabilityPresenter;
use App\Media\CapabilityResolver;
use App\Media\CapabilityValidator;
use App\Media\Contracts\AssignsProviderTaskIds;
use App\Media\Contracts\StagesProviderReferences;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\MediaState;
use App\Media\Enums\SubmitOutcome;
use App\Media\Exceptions\CapabilityValidationException;
use App\Media\MediaActivation;
use App\Media\MediaAdapterRegistry;
use App\Media\MediaCapability;
use App\Media\MediaGenerationCoordinator;
use App\Media\MediaJsonSchema;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\MediaAsset;
use App\Models\MediaCapabilityRevision;
use App\Models\ThreeDJob;
use App\Models\User;
use App\Models\UserToken;
use App\Models\VideoJob;
use App\Models\WorkspaceMediaJob;
use App\Models\WorkspaceMediaSubmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** One member-facing facade; native jobs retain their deployed execution and billing semantics. */
class WorkspaceMediaService
{
    private const LEASE_SECONDS = 600;
    private const POLL_SECONDS = 8;
    private const RECOVERY_GRACE_SECONDS = 120;
    /** An accepted request whose final result cannot be obtained within this window goes to review, keeping its reservation. */
    private const MAX_RESULT_WAIT_SECONDS = 21600;
    /** Runware keeps task outcomes and output URLs for seven days; an unknown outcome is read back only within that window. */
    private const RECONCILE_WINDOW_SECONDS = 604800;
    /** Provider-reported progress is advisory and short-lived; it never outlives a few missed polls. */
    private const PROGRESS_SECONDS = 300;
    private const NATIVE = ['image' => ImageJob::class, 'video' => VideoJob::class, 'audio' => AudioJob::class, 'model3d' => ThreeDJob::class];

    public function __construct(
        private readonly CapabilityResolver $resolver,
        private readonly CapabilityPresenter $presenter,
        private readonly CapabilityValidator $validator,
        private readonly MediaGenerationCoordinator $coordinator,
        private readonly MediaActivation $activation,
        private readonly MediaAdapterRegistry $adapters,
        private readonly MediaTokenBillingService $tokens,
        private readonly AssetService $assets,
        private readonly WorkspaceMediaOutputStore $outputs,
        private readonly StorageQuotaService $quota,
    ) {}

    /**
     * Keyset pages evaluate eligibility only for the rows needed to fill one page, so the picker
     * stays bounded when hundreds of catalog models are priced and published.
     */
    public function models(User $user, array $filters = []): array
    {
        $balance = UserToken::getBalance($user->id);
        if (! $this->activation->usesCoordinator($user)) {
            // Limited activation keeps the unified studio to its pilot member; say so instead of "no models".
            return ['models' => [], 'next_cursor' => null, 'total' => 0, 'balance_tokens' => $balance,
                'availability' => ['state' => 'restricted', 'reason' => 'The media studio is in a limited pilot for another account. Contact an administrator.']];
        }
        $after = $this->offset($filters['cursor'] ?? null);
        $page = [];
        $next = null;
        $lastId = $after;
        $query = AiModelProfile::query()->with('provider')->where('is_enabled', true)->where('is_available', true)
            ->where('token_cost', '>', 0)->where('id', '>', $after)->whereHas('provider', fn ($query) => $query->where('is_enabled', true));
        if (($search = trim((string) ($filters['q'] ?? ''))) !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('display_name', 'like', '%'.$search.'%')->orWhere('model_id', 'like', '%'.$search.'%')
                    ->orWhereHas('provider', fn ($provider) => $provider->where('name', 'like', '%'.$search.'%'));
            });
        }
        foreach ($query->lazyById(100) as $model) {
            $capabilities = $this->eligibleCapabilities($user, $model);
            if (isset($filters['kind']) && $filters['kind'] !== '') {
                $kind = $filters['kind'];
                $capabilities = array_filter($capabilities, static fn (array $capability): bool => match ($kind) {
                    'avatar' => $model->category === 'avatar',
                    // Data, captions and other files: everything the studio's media categories do not cover.
                    'other' => ! in_array($capability['output_kind'], ['image', 'video', 'audio', 'model3d'], true),
                    default => $capability['output_kind'] === $kind && ($kind !== 'video' || $model->category !== 'avatar'),
                });
            }
            if ($capabilities === []) {
                continue;
            }
            if (count($page) === 30) {
                $next = $this->cursor($lastId);
                break;
            }
            $page[] = $this->modelSummary($model, $capabilities, $filters['locale'] ?? null);
            $lastId = $model->id;
        }

        // An exact total would require evaluating every model; it is known only for one complete first page.
        return ['models' => $page, 'next_cursor' => $next, 'total' => $after === 0 && $next === null ? count($page) : null,
            'balance_tokens' => $balance, 'availability' => ['state' => 'available']];
    }

    /** @return list<string> output kinds a member can currently run for this model through the workspace */
    public function eligibleOutputKinds(User $user, AiModelProfile $model): array
    {
        return array_values(array_unique(array_column($this->eligibleCapabilities($user, $model), 'output_kind')));
    }

    public function capabilities(User $user, string $modelId, ?string $locale = null): array
    {
        $model = AiModelProfile::query()->with('provider')->where('model_id', $modelId)->first();
        abort_if($model === null, 404, 'This model is unavailable for your account.');
        $capabilities = $this->eligibleCapabilities($user, $model);
        abort_if($capabilities === [], 404, 'This model has no available operations for your account.');

        $summary = $this->modelSummary($model, $capabilities, $locale);
        if (in_array(1, array_column($capabilities, 'contract_version'), true)) {
            $summary['native'] = $this->nativeFacts($model);
        }

        return ['model' => $summary, 'capabilities' => $capabilities, 'balance_tokens' => UserToken::getBalance($user->id)];
    }

    /** Facts the fixed studios showed for native operations (voices, limits, Pro, references); never paths or bindings. */
    private function nativeFacts(AiModelProfile $model): array
    {
        try {
            $public = MediaModelConfig::publicModel($model);
        } catch (Throwable) {
            return [];
        }

        return array_intersect_key($public, array_flip(['pro', 'reference_image', 'audio', 'avatar_audio_mode']));
    }

    private function eligibleCapabilities(User $user, AiModelProfile $model): array
    {
        if ($user->is_active === false || ! $this->activation->usesCoordinator($user)
            || ! is_int($model->token_cost) || $model->token_cost < 1 || $model->token_cost > 2_147_483_647
            || ($allowed = MediaModelConfig::workspaceOperations($user, $model)) === []) {
            return [];
        }
        try {
            $definitions = $this->presenter->forModel($model);
        } catch (Throwable) {
            return [];
        }
        $result = [];
        foreach ($definitions as $operation => $definition) {
            if (! in_array($operation, $allowed, true) || ! $user->hasPermission($this->permission($model->category, $definition))) {
                continue;
            }
            // The adapter registry is authoritative for v2 and normalized native images.
            if (($definition['contract_version'] >= 2 || $definition['output_kind'] === 'image') && ! $this->adapters->has($model->provider->protocol)) {
                continue;
            }
            // Runware executes reviewed schema contracts only; a derived native operation would be a dead control.
            if ($model->provider->protocol === 'runware' && $definition['contract_version'] < 2) {
                continue;
            }
            try {
                $definition['billing'] = $this->billing($model, $definition);
            } catch (Throwable) {
                // An operation without a usable billing configuration is not offered; others stay listed.
                continue;
            }
            $definition['price_unit'] = $definition['billing']['price_unit'];
            $result[$operation] = $definition;
        }

        return $result;
    }

    private function modelSummary(AiModelProfile $model, array $capabilities, ?string $locale = null): array
    {
        $description = $locale === 'en' ? ($model->description_en ?: $model->description_id) : ($model->description_id ?: $model->description_en);
        $description = is_string($description) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags($description))) : '';
        $logo = is_string($model->logo_url) ? trim($model->logo_url) : '';

        return ['model_id' => $model->model_id, 'name' => $model->display_name, 'category' => $model->category,
            'provider_name' => mb_substr(strip_tags((string) ($model->provider?->name ?? $model->provider_name)), 0, 120),
            'description' => $description === '' ? null : (mb_strlen($description) > 240 ? rtrim(mb_substr($description, 0, 239)).'…' : $description),
            'logo_url' => str_starts_with($logo, 'https://') && strlen($logo) <= 2048 && filter_var($logo, FILTER_VALIDATE_URL) !== false
                && preg_match('/[\x00-\x20\x7f\\\\"<>]/', $logo) !== 1 ? $logo : null,
            'operations' => array_values(array_map(static fn (array $capability): array => [
                'operation' => $capability['operation'], 'output_kind' => $capability['output_kind'],
                'price_tokens' => $capability['price_tokens'], 'price_unit' => $capability['price_unit'],
                'contract_version' => $capability['contract_version'],
            ], $capabilities))];
    }

    private function billing(AiModelProfile $model, array $definition): array
    {
        $config = MediaModelConfig::forOperation($model, $definition['operation']);
        $v2 = $definition['contract_version'] >= 2;
        $unit = $config['price_unit'] ?? ($model->category === 'avatar' ? 'second' : 'generation');
        $count = ! $v2 && in_array($definition['output_kind'], ['image', 'video'], true) ? max(1, (int) ($config['max_quantity'] ?? 1)) : 1;
        $second = $unit === 'second';
        // Billed seconds are the duration actually sent upstream: the typed v1 param or a v2 root `duration` input.
        $durationInput = ! $v2 || array_key_exists('duration', $definition['input_schema']['properties'] ?? []);
        // A v2 result-count input (e.g. Runware numberResults) multiplies per-generation and per-second tariffs.
        $quantity = $v2 && in_array($unit, ['generation', 'second'], true) && is_string($config['quantity_input'] ?? null)
            && is_array($definition['input_schema']['properties'][$config['quantity_input']] ?? null) ? $config['quantity_input'] : null;
        $maximum = $quantity === null ? null : ($definition['input_schema']['properties'][$quantity]['maximum'] ?? null);

        return ['mode' => $second ? 'per_second' : ($count > 1 || $quantity !== null ? 'per_output' : 'per_invocation'),
            'price_unit' => $unit, 'count_field' => $count > 1 ? 'count' : null, 'max_count' => $count,
            'quantity_input' => $quantity, 'max_quantity' => is_int($maximum) && $maximum >= 1 ? $maximum : null,
            'pro_field' => ! $v2 && ($config['supports_pro'] ?? false) ? 'pro' : null,
            'pro_multiplier' => ! $v2 && ($config['supports_pro'] ?? false) ? 2 : 1,
            'duration_field' => $second ? ($durationInput ? 'duration' : 'billing_seconds') : null,
            'durations' => $second ? ($config['durations'] ?? []) : [],
            'variable_configuration' => $v2];
    }

    /** @return list<Model> */
    public function create(User $user, array $request): array
    {
        $key = $request['idempotency_key'] ?? null;
        if (! is_string($key) || trim($key) === '' || strlen($key) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'A stable request key is required.']);
        }
        if (! is_array($request['inputs'] ?? null)) {
            throw ValidationException::withMessages(['inputs' => 'An input object is required.']);
        }
        $requestKey = hash('sha256', trim($key));

        return DB::transaction(function () use ($user, $request, $requestKey): array {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = WorkspaceMediaSubmission::query()->where('user_id', $owner->id)->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                return $this->replay($owner, $existing, $request);
            }
            $operation = MediaOperation::tryFrom((string) ($request['operation'] ?? ''));
            if ($operation === null) {
                throw ValidationException::withMessages(['operation' => 'Select an available media operation.']);
            }
            $model = AiModelProfile::query()->with('provider')->where('model_id', $request['model'] ?? '')->lockForUpdate()->first();
            abort_if($model === null, 404, 'This model is unavailable for your account.');
            $this->activation->assertNotPaused();
            $capabilities = $this->eligibleCapabilities($owner, $model);
            if (! isset($capabilities[$operation->value])) {
                throw new ImageGenerationException('This operation is unavailable or not permitted for your account.', 403);
            }
            $resolved = $this->resolver->resolve($model, $operation, schemaContracts: true);
            $capability = $resolved->capability;
            $hash = $request['expected_capability_hash'] ?? null;
            if (($capability->providerBindings['adapter'] ?? null) === 'fal_wma_v1'
                || ($capability->providerBindings['transport'] ?? null) === 'realtime') {
                throw ValidationException::withMessages(['operation' => 'Start this operation through the realtime session controls, not a queued media job.']);
            }
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw ValidationException::withMessages(['expected_capability_hash' => 'A current capability quote is required.']);
            }
            if (! hash_equals($resolved->sourceHash, $hash)) {
                throw new ImageGenerationException('This model changed. Review the current options before submitting.', 409);
            }
            $conversation = $request['conversation_id'] ?? null;
            if ($conversation !== null) {
                app(ChatWorkspaceService::class)->resolveConversation($owner, $conversation, false);
            }
            $execution = $this->execution($request, $capability);
            $billing = $capabilities[$operation->value]['billing'];
            if ($execution['count'] > $billing['max_count'] || $execution['count'] < 1
                || ($execution['pro'] && $billing['pro_field'] === null)) {
                throw ValidationException::withMessages(['count' => 'These execution options are not supported by this model.']);
            }
            if ($model->category === 'avatar' && ! $execution['rights_confirmed']) {
                throw ValidationException::withMessages(['rights_confirmed' => 'Confirm permission to use this photo and voice.']);
            }
            if (array_intersect_key($execution, array_flip(['mode', 'cta', 'ugc_variation'])) !== []
                && ($capability->contractVersion !== 1 || ! in_array($operation, [MediaOperation::TextToVideo, MediaOperation::ImageToVideo], true))) {
                throw ValidationException::withMessages(['mode' => 'Product, UGC, call-to-action and variation options apply only to native video generation.']);
            }
            $validated = $this->normalize($capability, $request['inputs'], $execution);
            $price = (int) $model->token_cost;
            if ($billing['mode'] === 'per_second') {
                $fromInputs = $billing['duration_field'] === 'duration';
                $duration = $fromInputs ? self::wholeSeconds($capability->contractVersion >= 2
                    ? ($validated['inputs']['duration'] ?? null) : ($validated['params']['duration'] ?? null)) : $execution['billing_seconds'];
                if (! is_int($duration) || $duration < 1
                    || ($fromInputs && $execution['billing_seconds'] !== null && $execution['billing_seconds'] !== $duration)
                    || (! $fromInputs && $billing['durations'] === [])
                    || ($billing['durations'] !== [] && ! in_array($duration, $billing['durations'], true))) {
                    throw ValidationException::withMessages([$fromInputs ? 'inputs.duration' : 'billing_seconds'
                        => 'Select an explicit whole-second duration allowed by this per-second price.']);
                }
                $price = $this->multiply($price, $duration);
            } elseif ($execution['pro']) {
                $price = $this->multiply($price, $billing['pro_multiplier']);
            }
            // Every requested result is billed (e.g. Runware numberResults): the reviewed binding names that input.
            if (($quantityInput = self::quantityInput($capability->providerBindings, $billing['price_unit'])) !== null) {
                $price = $this->multiply($price, self::quantity($validated['inputs'] ?? [], $quantityInput));
            }
            $expectedPrice = $request['expected_price_tokens'] ?? null;
            if (! is_int($expectedPrice) || $expectedPrice < 1) {
                throw ValidationException::withMessages(['expected_price_tokens' => 'A current token price is required.']);
            }
            if ($expectedPrice !== $price) {
                throw new ImageGenerationException('The price changed. Review the current quote before submitting.', 409);
            }
            $this->multiply($price, $execution['count']);
            if ($this->quota->exceeded($owner)) {
                throw ValidationException::withMessages(['storage' => 'Your storage is full. Remove a Library item before generating.']);
            }
            $snapshot = $capability->toArray();
            $fingerprint = $this->requestFingerprint($model->model_id, $operation->value, $validated, $execution, $conversation);
            $options = ['idempotency_key' => $requestKey, 'expected_capability_hash' => $hash,
                'expected_price_tokens' => $price, 'rights_confirmed' => $execution['rights_confirmed']];
            if ($capability->contractVersion >= 2) {
                try {
                    $references = MediaJsonSchema::assetReferences($snapshot['input_schema'] ?? [], $validated['inputs']);
                } catch (CapabilityValidationException $exception) {
                    // A contract fault in the member's input is a validation error, never an unknown (5xx) paid request.
                    throw ValidationException::withMessages($exception->errors());
                }
                $this->assertAssets($owner, $references);
                $revision = $this->resolver->ensureRevision($model, $operation, $resolved);
                $id = (string) Str::uuid();
                $reservation = $this->tokens->reserve($owner, 'media', $model->model_id, 1, 'workspace:'.$id, $price);
                $jobs = [WorkspaceMediaJob::create([
                    'job_id' => $id, 'user_id' => $owner->id, 'model' => $model->model_id, 'model_label' => $model->display_name,
                    'operation' => $operation->value, 'output_kind' => $capability->outputKind->value, 'conversation_id' => $conversation,
                    'provider_id' => $model->provider_id, 'upstream_model_id' => $model->upstream_model_id ?: $model->model_id,
                    'connection_fingerprint' => self::fingerprint($model->provider), 'capability_revision_id' => $revision->id,
                    'capability_hash' => $hash, 'capability_snapshot' => $snapshot, 'provider_bindings' => $capability->providerBindings,
                    'normalized_inputs' => $validated['inputs'], 'input_assets' => $references,
                    'reference_asset_ids' => array_values(array_unique(array_column($references, 'asset_id'))),
                    'payload_fingerprint' => $fingerprint, 'price_tokens' => $price, 'price_unit' => $billing['price_unit'],
                    'billing_mode' => $reservation['billing_mode'], 'billing_status' => 'reserved',
                    'billing_reference_id' => $reservation['reference_id'], 'tokens_reserved' => $reservation['amount_tokens'],
                    'status' => 'pending', 'stage' => 'queued', 'next_poll_at' => now(),
                ])];
                DB::afterCommit(fn () => $this->queueSubmission($jobs[0]->id));
            } else {
                $fields = $this->nativeFields($capability, $request['inputs'], $execution);
                $jobs = match ($capability->outputKind->value) {
                    'image' => $this->nativeImages($owner, $model, $operation, $fields, $options, $execution['count']),
                    'video' => $this->coordinator->startVideo($owner, $model, $operation, $fields, 'workspace', [...$options,
                        'mode' => $execution['mode'] ?? 'prompt', 'cta' => $execution['cta'] ?? '', 'ugc_variation' => $execution['ugc_variation'] ?? false]),
                    'audio' => [$this->coordinator->startAudio($owner, $model, $operation, $fields, 'workspace', $options)],
                    'model3d' => $this->coordinator->startModel3d($owner, $model, $operation, $fields, 'workspace', $options),
                    default => throw new ImageGenerationException('This legacy operation has no executor.', 422),
                };
                // One generation per image job: several images from this request share its key and read as one set.
                $batch = count($jobs) > 1 && $capability->outputKind->value === 'image' ? $requestKey : null;
                foreach ($jobs as $job) {
                    $job->forceFill(['conversation_id' => $conversation, ...($batch !== null ? ['batch_key' => $batch] : [])])->save();
                }
            }
            WorkspaceMediaSubmission::create(['user_id' => $owner->id, 'request_key' => $requestKey,
                'model' => $model->model_id, 'operation' => $operation->value, 'conversation_id' => $conversation,
                'capability_snapshot' => $snapshot, 'execution' => $execution, 'payload_fingerprint' => $fingerprint,
                'job_ids' => array_map(fn (Model $job): string => $this->publicId($job), $jobs)]);

            return $jobs;
        });
    }

    private function replay(User $owner, WorkspaceMediaSubmission $submission, array $request): array
    {
        $capability = MediaCapability::fromArray($submission->capability_snapshot);
        try {
            $execution = $this->execution($request, $capability);
            $validated = $this->normalize($capability, $request['inputs'], $execution);
            $fingerprint = $this->requestFingerprint((string) ($request['model'] ?? ''), (string) ($request['operation'] ?? ''),
                $validated, $execution, $request['conversation_id'] ?? null);
        } catch (Throwable) {
            throw new ImageGenerationException('This request key was already used with different input.', 409);
        }
        if (! hash_equals($submission->payload_fingerprint, $fingerprint)) {
            throw new ImageGenerationException('This request key was already used with different input.', 409);
        }
        $jobs = [];
        foreach ($submission->job_ids as $id) {
            [$class, $uuid] = $this->identity($id);
            $job = $class::query()->where('user_id', $owner->id)->where('job_id', $uuid)->first();
            if ($job === null) {
                throw new ImageGenerationException('This request was already processed, but its retained results have expired or been deleted. Use a new key for a new generation.', 410);
            }
            $jobs[] = $job;
        }

        return $jobs;
    }

    private function execution(array $request, MediaCapability $capability): array
    {
        $inputs = $request['inputs'] ?? [];
        $native = $capability->contractVersion === 1;
        $count = $request['count'] ?? ($native ? ($inputs['count'] ?? 1) : 1);
        $pro = $request['pro'] ?? ($native ? ($inputs['pro'] ?? false) : false);
        if (! is_int($count) || ! is_bool($pro)) {
            throw ValidationException::withMessages(['count' => 'Count must be an integer and Pro must be a boolean.']);
        }

        $mode = $request['mode'] ?? 'prompt';
        $cta = is_string($request['cta'] ?? null) ? trim($request['cta']) : '';
        $variation = $request['ugc_variation'] ?? false;
        if (! in_array($mode, ['prompt', 'ab_testing'], true) || ! is_bool($variation) || mb_strlen($cta) > 500) {
            throw ValidationException::withMessages(['mode' => 'These video authoring options are invalid.']);
        }
        $execution = ['count' => $count, 'pro' => $pro, 'rights_confirmed' => ($request['rights_confirmed'] ?? $inputs['rights_confirmed'] ?? false) === true,
            'billing_seconds' => $request['billing_seconds'] ?? null];
        // Authoring options join the replay identity only when used: plain prompts and submissions
        // recorded before these options existed keep their original fingerprint.
        if ($mode === 'ab_testing') {
            $execution['mode'] = $mode;
        }
        if ($cta !== '') {
            $execution['cta'] = $cta;
        }
        if ($variation) {
            $execution['ugc_variation'] = true;
        }

        return $execution;
    }

    private function normalize(MediaCapability $capability, array $inputs, array $execution): array
    {
        try {
            return $this->validator->validate($capability, $capability->contractVersion >= 2 ? $inputs : $this->nativeFields($capability, $inputs, $execution));
        } catch (CapabilityValidationException $exception) {
            throw ValidationException::withMessages($exception->errors());
        }
    }

    private function nativeFields(MediaCapability $capability, array $inputs, array $execution): array
    {
        unset($inputs['rights_confirmed']);
        if ($capability->param('count') !== null) {
            $inputs['count'] = $execution['count'];
        }
        if ($capability->param('pro') !== null) {
            $inputs['pro'] = $execution['pro'];
        }

        return $inputs;
    }

    private function nativeImages(User $user, AiModelProfile $model, MediaOperation $operation, array $fields, array $options, int $count): array
    {
        $jobs = [];
        for ($index = 0; $index < $count; $index++) {
            $jobs[] = $this->coordinator->startImage($user, $model, $operation, $fields, 'workspace',
                [...$options, 'idempotency_key' => $options['idempotency_key'].':'.$index]);
        }

        return $jobs;
    }

    private function requestFingerprint(string $model, string $operation, array $validated, array $execution, ?string $conversation): string
    {
        return hash('sha256', json_encode($this->canonical([$model, $operation, $validated, $execution, $conversation]), JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonical($child);
        }

        return $value;
    }

    private function multiply(int $price, int $quantity): int
    {
        if ($price < 1 || $quantity < 1 || $price > intdiv(2_147_483_647, $quantity)) {
            throw ValidationException::withMessages(['price' => 'The quoted token reservation exceeds the supported limit.']);
        }

        return $price * $quantity;
    }

    /** Whole seconds from a schema value such as 5, 5.0, "5" or "5s"; "auto" and fractions are not billable. */
    private static function wholeSeconds(mixed $value): ?int
    {
        if (is_float($value) && $value >= 1 && $value <= 999_999 && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^([1-9][0-9]{0,5})s?$/D', $value, $match) === 1) {
            return (int) $match[1];
        }

        return is_int($value) && $value >= 1 ? $value : null;
    }

    /** The reviewed v2 result-count input, when it multiplies this tariff (per generation or per second). */
    private static function quantityInput(array $bindings, ?string $unit = null): ?string
    {
        $field = $bindings['quantity_input'] ?? null;

        return is_string($field) && $field !== '' && ($unit === null || in_array($unit, ['generation', 'second'], true)) ? $field : null;
    }

    /** Requested results: max(1, the validated count). Normalized inputs already carry the schema default. */
    private static function quantity(array $inputs, string $field): int
    {
        $value = $inputs[$field] ?? 1;

        return is_numeric($value) ? max(1, (int) $value) : 1;
    }

    private function assertAssets(User $owner, array $references): void
    {
        $assets = MediaAsset::query()->whereIn('id', array_values(array_unique(array_column($references, 'asset_id'))))->get()->keyBy('id');
        foreach ($references as $reference) {
            $asset = $assets->get($reference['asset_id']);
            if ($asset === null || (int) $asset->user_id !== (int) $owner->id) {
                throw new AuthorizationException('This input asset is not available to your account.');
            }
            $this->assets->assertOwner($owner, $asset);
            if (($reference['kind'] ?? 'file') !== 'file' && $asset->media_type !== $reference['kind']) {
                throw ValidationException::withMessages(['inputs' => 'An input file does not match the required media kind.']);
            }
            try {
                $this->assets->assertSourceConstraints($asset, $reference);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['inputs.'.implode('.', $reference['path']) => $exception->getMessage()]);
            }
        }
    }

    public function process(int $id): void
    {
        $job = DB::transaction(function () use ($id): ?WorkspaceMediaJob {
            $job = WorkspaceMediaJob::query()->lockForUpdate()->find($id);
            if ($job === null || $job->status !== 'pending' || $job->stage !== 'queued' || $job->submitted_at !== null || $job->upstream_job_id !== null) {
                return null;
            }
            $job->update(['status' => 'processing', 'stage' => 'preparing', 'processing_started_at' => now(),
                'processing_token' => (string) Str::uuid(), 'next_poll_at' => null]);

            return $job;
        });
        if ($job === null) {
            return;
        }
        try {
            $provider = $this->provider($job);
            $owner = User::query()->findOrFail($job->user_id);
            $this->assertAssets($owner, $job->input_assets);
            $capability = MediaCapability::fromArray([...$job->capability_snapshot, 'provider_bindings' => $job->provider_bindings]);
            $adapter = $this->adapters->for($provider->protocol);
            $request = $adapter->buildRequest($capability, ['inputs' => $job->normalized_inputs, 'params' => [], 'owner_id' => $job->user_id], $job->upstream_model_id);
        } catch (Throwable) {
            $this->failClaim($job, 'The saved provider connection or input files are no longer available. No generation was submitted.');

            return;
        }
        if ($adapter instanceof StagesProviderReferences) {
            try {
                // Uploading owned references is not a paid submission, so it happens before the durable marker:
                // a worker that dies here leaves a recoverable preparing job, not an unknown acceptance.
                $request = $adapter->stageReferences($provider, $request);
            } catch (Throwable) {
                $this->failClaim($job, 'The reference file could not be staged. No generation was submitted. Reserved tokens have been returned.');

                return;
            }
        }
        // A client-generated provider task identity is durable before the paid request, so an unknown
        // outcome can later be read back with that identity instead of staying unknown.
        $marker = ['stage' => 'submitting', 'submitted_at' => now(), 'processing_started_at' => now()];
        if ($adapter instanceof AssignsProviderTaskIds) {
            try {
                $marker['upstream_job_id'] = $adapter->taskId($request);
            } catch (Throwable) {
                $this->failClaim($job, 'The saved provider connection or input files are no longer available. No generation was submitted.');

                return;
            }
        }
        $sending = $this->updateClaim($job, $marker);
        if ($sending === null) {
            return;
        }
        $job = $sending;
        try {
            $result = $adapter->submit($provider, $request);
        } catch (Throwable) {
            $this->uncertain($job);

            return;
        }
        if ($result->outcome === SubmitOutcome::Rejected) {
            $this->failClaim($job, 'The provider rejected this request. Reserved tokens have been returned.');
        } elseif ($result->outcome === SubmitOutcome::Uncertain) {
            $this->uncertain($job);
        } elseif ($result->outcome === SubmitOutcome::Immediate) {
            $saving = $this->recordResult($job, $result->resultData, $result->resultUrls ?? []);
            if ($saving !== null) {
                $this->save($saving);
            }
        } elseif (is_string($result->taskId) && $result->taskId !== '') {
            if ($this->updateClaim($job, ['status' => 'processing', 'stage' => 'rendering', 'upstream_job_id' => $result->taskId,
                'processing_started_at' => null, 'processing_token' => null, 'error_message' => null,
                'next_poll_at' => now()->addSeconds(self::POLL_SECONDS)]) !== null) {
                $this->queuePoll($job->id);
            }
        } else {
            $this->uncertain($job);
        }
    }

    public function poll(int $id): void
    {
        $job = DB::transaction(function () use ($id): ?WorkspaceMediaJob {
            $job = WorkspaceMediaJob::query()->lockForUpdate()->find($id);
            if ($job === null || ! (($job->status === 'processing' && in_array($job->stage, ['rendering', 'saving'], true)) || self::reconcilable($job))
                || ($job->next_poll_at !== null && $job->next_poll_at->isFuture())
                || ($job->processing_started_at !== null && $job->processing_started_at->gt(now()->subSeconds(self::LEASE_SECONDS)))) {
                return null;
            }
            $job->update(['processing_token' => (string) Str::uuid(), 'processing_started_at' => now(),
                'next_poll_at' => now()->addSeconds(self::LEASE_SECONDS), 'poll_attempts' => $job->poll_attempts + 1]);

            return $job;
        });
        if ($job === null) {
            return;
        }
        if ($job->status === 'uncertain') {
            $this->reconcile($job);

            return;
        }
        if ($job->result_received_at !== null) {
            $saving = $this->updateClaim($job, ['stage' => 'saving']);
            if ($saving !== null) {
                $this->save($saving);
            }

            return;
        }
        if (! $job->upstream_job_id) {
            $this->uncertain($job);

            return;
        }
        $saved = AiProviderProfile::query()->find($job->provider_id);
        if ($saved === null || ! hash_equals($job->connection_fingerprint, self::fingerprint($saved))) {
            // Another account or endpoint must never be asked about this request, and waiting cannot restore it.
            $this->review($job, 'The saved provider connection changed, so this result can no longer be checked. Tokens remain reserved for review; no new generation is submitted.');

            return;
        }
        try {
            $provider = $this->provider($job);
            $result = $this->adapters->for($provider->protocol)->pollStatus($provider, $job->upstream_job_id, $this->pollContext($job));
        } catch (Throwable) {
            $this->reschedule($job, 'Status could not be checked. The original request will be checked again; no new generation is submitted.');

            return;
        }
        if ($result->state === MediaState::Failed) {
            $this->failClaim($job, 'The provider could not complete this request. Reserved tokens have been returned.');
        } elseif ($result->state === MediaState::Completed) {
            $saving = $this->recordResult($job, $result->resultData, $result->resultUrls ?? []);
            if ($saving !== null) {
                $this->save($saving);
            }
        } else {
            $this->rememberProgress($job, $result->progress);
            $this->reschedule($job);
        }
    }

    /**
     * Reads back a Runware task whose acceptance or result is unknown, using the task identity stored before
     * its only POST: a final result is saved and settled, an explicit failure is refunded, and a confirmed
     * running task resumes normal polling. Anything inconclusive (taskNotFound, a changed connection, a
     * transport failure) keeps the reservation under review. The request is never submitted again.
     */
    private function reconcile(WorkspaceMediaJob $job): void
    {
        try {
            $provider = $this->provider($job);
            $result = $this->adapters->for($provider->protocol)->pollStatus($provider, $job->upstream_job_id, $this->pollContext($job));
        } catch (Throwable) {
            $this->deferReconciliation($job);

            return;
        }
        if ($result->state === MediaState::Failed) {
            $this->failClaim($job, 'The provider could not complete this request. Reserved tokens have been returned.');
        } elseif ($result->state === MediaState::Completed && ($result->resultData !== null || ($result->resultUrls ?? []) !== [])) {
            $saving = $this->recordResult($job, $result->resultData, $result->resultUrls ?? []);
            if ($saving !== null) {
                $this->save($saving);
            }
        } elseif ($result->state === MediaState::Processing && $job->submitted_at->gt(now()->subSeconds(self::MAX_RESULT_WAIT_SECONDS))) {
            if ($this->updateClaim($job, ['status' => 'processing', 'stage' => 'rendering', 'processing_token' => null,
                'processing_started_at' => null, 'error_message' => null, 'next_poll_at' => now()->addSeconds(self::POLL_SECONDS)]) !== null) {
                $this->rememberProgress($job, $result->progress);
                $this->queuePoll($job->id);
            }
        } else {
            // Still running past the result window: it stays under review and is checked again less often.
            $this->deferReconciliation($job);
        }
    }

    private function deferReconciliation(WorkspaceMediaJob $job): void
    {
        $age = max(0, now()->getTimestamp() - $job->submitted_at->getTimestamp());
        $this->updateClaim($job, ['processing_token' => null, 'processing_started_at' => null,
            'next_poll_at' => now()->addSeconds(min(3600, max(60, intdiv($age, 10))))]);
    }

    /** An unknown Runware outcome with its pre-submission task identity, within the provider's retention window. */
    private static function reconcilable(WorkspaceMediaJob $job): bool
    {
        return $job->status === 'uncertain' && in_array($job->stage, ['submission_uncertain', 'result_uncertain'], true)
            && self::isRunware($job) && Str::isUuid((string) $job->upstream_job_id) && $job->result_received_at === null
            && $job->submitted_at !== null && $job->submitted_at->gt(now()->subSeconds(self::RECONCILE_WINDOW_SECONDS));
    }

    private static function isRunware(WorkspaceMediaJob $job): bool
    {
        return ($job->provider_bindings['adapter'] ?? null) === 'runware_v1';
    }

    /** The job's immutable reviewed binding plus its requested result count, so a multi-result task never completes early. */
    private function pollContext(WorkspaceMediaJob $job): array
    {
        $bindings = $job->provider_bindings ?? [];
        $field = self::quantityInput($bindings);

        return [...$bindings, 'quantity' => $field === null ? 1 : self::quantity($job->normalized_inputs ?? [], $field)];
    }

    /** Provider-reported progress for the owner's running job: a short-lived integer 0–100, never provider fields. */
    private function rememberProgress(WorkspaceMediaJob $job, ?int $progress): void
    {
        if ($progress === null) {
            return;
        }
        try {
            Cache::put(self::progressKey($job), max(0, min(100, $progress)), self::PROGRESS_SECONDS);
        } catch (Throwable) {
            // Progress is advisory; an unavailable cache never affects the request.
        }
    }

    private function progress(WorkspaceMediaJob $job): ?int
    {
        if ($job->status !== 'processing' || $job->stage !== 'rendering') {
            return null;
        }
        try {
            $progress = Cache::get(self::progressKey($job));
        } catch (Throwable) {
            return null;
        }

        return is_int($progress) && $progress >= 0 && $progress <= 100 ? $progress : null;
    }

    private static function progressKey(WorkspaceMediaJob $job): string
    {
        return 'workspace-media-progress:'.$job->job_id;
    }

    private function recordResult(WorkspaceMediaJob $job, mixed $data, array $urls): ?WorkspaceMediaJob
    {
        if ($data === null && $urls === []) {
            $this->reschedule($job, 'The provider reported completion without a usable result. The original result will be checked again.');

            return null;
        }

        return $this->updateClaim($job, ['status' => 'processing', 'stage' => 'saving', 'provider_result' => $data,
            'provider_result_urls' => $urls, 'result_received_at' => now(), 'processing_started_at' => now(),
            'next_poll_at' => null, 'error_message' => null]);
    }

    private function save(WorkspaceMediaJob $job): void
    {
        try {
            $data = $this->outputs->persist($job);
            DB::transaction(function () use ($job, $data): void {
                User::query()->whereKey($job->user_id)->lockForUpdate()->firstOrFail();
                $locked = WorkspaceMediaJob::query()->lockForUpdate()->find($job->id);
                if (! $this->ownsClaim($locked, $job)) {
                    return;
                }
                $this->tokens->settle($locked->user_id, $this->reservation($locked),
                    ['service' => 'media', 'model' => $locked->model, 'operation' => $locked->operation, 'price_unit' => $locked->price_unit]);
                $locked->update(['status' => 'completed', 'stage' => 'completed', 'result_data' => $data,
                    'billing_status' => 'settled', 'error_message' => null, 'completed_at' => now(),
                    // Runware's actual USD cost per result stays only in this private (hidden) provider result.
                    'provider_result' => self::isRunware($locked) ? $locked->provider_result : null, 'provider_result_urls' => null,
                    'processing_token' => null, 'processing_started_at' => null, 'next_poll_at' => null]);
            });
        } catch (Throwable $exception) {
            $message = $exception instanceof HttpException && $exception->getStatusCode() === 413
                ? 'The provider completed, but your storage is full. Free storage and retry saving; this does not generate again.'
                : 'The provider completed, but its outputs could not all be saved. Retry saving the original result; this does not generate again.';
            $this->updateClaim($job, ['status' => 'save_failed', 'stage' => 'save_failed', 'error_message' => $message,
                'processing_token' => null, 'processing_started_at' => null, 'next_poll_at' => null]);
        }
    }

    private function provider(WorkspaceMediaJob $job): AiProviderProfile
    {
        $provider = AiProviderProfile::query()->find($job->provider_id);
        if ($provider === null || ! $provider->is_enabled || ! $this->adapters->has($provider->protocol)
            || ! hash_equals($job->connection_fingerprint, self::fingerprint($provider))) {
            throw new AiProxyException('The saved provider connection changed. No alternate provider will be used.', 503);
        }

        return $provider;
    }

    private static function fingerprint(AiProviderProfile $provider): string
    {
        return hash('sha256', json_encode(array_intersect_key($provider->getRawOriginal(),
            array_flip(['base_url', 'api_key', 'protocol', 'api_version'])), JSON_THROW_ON_ERROR));
    }

    private function ownsClaim(?WorkspaceMediaJob $locked, WorkspaceMediaJob $job): bool
    {
        return $locked !== null && in_array($locked->status, ['processing', 'uncertain'], true)
            && ($locked->stage === $job->stage || ($job->stage === 'submitting' && $locked->status === 'uncertain' && $locked->stage === 'submission_uncertain'))
            && is_string($locked->processing_token) && is_string($job->processing_token)
            && hash_equals($locked->processing_token, $job->processing_token);
    }

    private function updateClaim(WorkspaceMediaJob $job, array $changes): ?WorkspaceMediaJob
    {
        return DB::transaction(function () use ($job, $changes): ?WorkspaceMediaJob {
            $locked = WorkspaceMediaJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return null;
            }
            $locked->update($changes);

            return $locked;
        });
    }

    private function reservation(WorkspaceMediaJob $job): array
    {
        return ['reference_id' => $job->billing_reference_id, 'amount_tokens' => $job->tokens_reserved];
    }

    private function failClaim(WorkspaceMediaJob $job, string $message): void
    {
        DB::transaction(function () use ($job, $message): void {
            User::query()->whereKey($job->user_id)->lockForUpdate()->firstOrFail();
            $locked = WorkspaceMediaJob::query()->lockForUpdate()->find($job->id);
            if (! $this->ownsClaim($locked, $job)) {
                return;
            }
            $this->tokens->release($locked->user_id, $this->reservation($locked), 'Workspace media generation did not complete');
            $locked->update(['status' => 'failed', 'stage' => 'failed', 'billing_status' => 'released',
                'error_message' => $message, 'completed_at' => now(), 'processing_token' => null,
                'processing_started_at' => null, 'next_poll_at' => null]);
        });
    }

    private function uncertain(WorkspaceMediaJob $job): void
    {
        $this->updateClaim($job, ['status' => 'uncertain', 'stage' => 'submission_uncertain',
            'error_message' => 'Provider acceptance could not be confirmed. Tokens remain reserved for reconciliation. This request will not be submitted again.',
            // A Runware task identity was stored before the POST, so its outcome is read back shortly (never resubmitted).
            'processing_started_at' => null, 'next_poll_at' => self::isRunware($job) && Str::isUuid((string) $job->upstream_job_id) ? now()->addMinute() : null]);
    }

    private function review(WorkspaceMediaJob $job, string $message): void
    {
        $this->updateClaim($job, ['status' => 'uncertain', 'stage' => 'result_uncertain', 'error_message' => $message,
            'processing_token' => null, 'processing_started_at' => null, 'next_poll_at' => null]);
    }

    private function reschedule(WorkspaceMediaJob $job, ?string $error = null): void
    {
        // A provider that never finishes, or whose status or result can never be read, must not be polled forever.
        if ($job->submitted_at !== null && $job->submitted_at->lt(now()->subSeconds(self::MAX_RESULT_WAIT_SECONDS))) {
            $this->review($job, 'The provider did not deliver a final result in time. Tokens remain reserved for review; no new generation is submitted.');

            return;
        }
        $delay = $job->poll_attempts > 100 ? 60 : self::POLL_SECONDS;
        if ($this->updateClaim($job, ['stage' => 'rendering', 'processing_token' => null, 'processing_started_at' => null,
            'error_message' => $error, 'next_poll_at' => now()->addSeconds($delay)]) !== null) {
            $this->queuePoll($job->id, $delay);
        }
    }

    public function submissionInterrupted(int $id): void
    {
        $job = WorkspaceMediaJob::query()->find($id);
        if ($job !== null && $job->status === 'processing' && $job->stage === 'submitting') {
            $this->uncertain($job);
        }
    }

    /**
     * Recovery never changes a submitting/uncertain request back into a submit-ready request. Unknown Runware
     * outcomes are read back with their stored task identity instead of waiting for manual review.
     */
    public function recover(): array
    {
        $counts = ['queued' => 0, 'polling' => 0, 'uncertain' => 0, 'reconciling' => 0];
        WorkspaceMediaJob::query()->whereIn('status', ['pending', 'processing'])->orderBy('id')->chunkById(100, function ($jobs) use (&$counts): void {
            foreach ($jobs as $job) {
                if ($job->processing_started_at !== null && $job->processing_started_at->gt(now()->subSeconds(self::LEASE_SECONDS))) {
                    continue;
                }
                if ($job->status === 'pending' && $job->stage === 'queued' && $job->submitted_at === null) {
                    // The original dispatch gets a grace period, so the minutely sweep does not flood the queue.
                    if ($job->updated_at->gt(now()->subSeconds(self::RECOVERY_GRACE_SECONDS))) {
                        continue;
                    }
                    $this->queueSubmission($job->id);
                    $counts['queued']++;
                } elseif ($job->stage === 'preparing' && $job->submitted_at === null) {
                    // Preparation has no paid side effect; the durable submission marker is still absent.
                    $reset = $this->updateClaim($job, ['status' => 'pending', 'stage' => 'queued', 'processing_token' => null, 'processing_started_at' => null]);
                    if ($reset !== null) {
                        $this->queueSubmission($job->id);
                        $counts['queued']++;
                    }
                } elseif ($job->stage === 'submitting') {
                    $this->uncertain($job);
                    $counts['uncertain']++;
                } elseif (in_array($job->stage, ['rendering', 'saving'], true)
                    && ($job->next_poll_at === null || $job->next_poll_at->lt(now()->subSeconds(self::RECOVERY_GRACE_SECONDS)))) {
                    $this->queuePoll($job->id, 0);
                    $counts['polling']++;
                }
            }
        });
        WorkspaceMediaJob::query()->where('status', 'uncertain')->whereIn('stage', ['submission_uncertain', 'result_uncertain'])
            ->where('provider_bindings->adapter', 'runware_v1')->whereNotNull('upstream_job_id')->whereNull('result_received_at')
            ->where('submitted_at', '>', now()->subSeconds(self::RECONCILE_WINDOW_SECONDS))
            ->where(fn (Builder $query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
            ->orderBy('id')->chunkById(100, function ($jobs) use (&$counts): void {
                foreach ($jobs as $job) {
                    if (self::reconcilable($job) && ($job->processing_started_at === null
                        || $job->processing_started_at->lte(now()->subSeconds(self::LEASE_SECONDS)))) {
                        $this->queuePoll($job->id, 0);
                        $counts['reconciling']++;
                    }
                }
            });

        return $counts;
    }

    private function queueSubmission(int $id): void
    {
        try {
            ProcessWorkspaceMediaJob::dispatch($id)->onConnection('media')->onQueue('media')->afterCommit();
        } catch (Throwable) {
            // The durable queued row is redispatched by recovery; no paid request has occurred.
        }
    }

    private function queuePoll(int $id, int $seconds = self::POLL_SECONDS): void
    {
        try {
            PollWorkspaceMediaJob::dispatch($id)->onConnection('media')->onQueue('media')->delay(now()->addSeconds($seconds))->afterCommit();
        } catch (Throwable) {
            // Durable next_poll_at is the recovery cursor, independent of broker availability.
        }
    }

    public function owned(User $user, string $id): Model
    {
        [$class, $uuid] = $this->identity($id);

        $job = $class::query()->where('user_id', $user->id)->where('job_id', $uuid)->first();
        abort_if($job === null, 404, 'This media request is unavailable.');

        return $job;
    }

    private function identity(string $id): array
    {
        // Workspace, image, audio and 3D ids are uuid columns: PostgreSQL rejects any other literal with an error,
        // so a malformed link is simply an unknown request. Legacy video ids are free-form strings.
        if (! str_contains($id, ':')) {
            abort_unless(Str::isUuid($id), 404, 'This media request is unavailable.');

            return [WorkspaceMediaJob::class, $id];
        }
        [$kind, $uuid] = explode(':', $id, 2);
        abort_unless(isset(self::NATIVE[$kind]) && $uuid !== '' && ($kind === 'video' || Str::isUuid($uuid)), 404, 'This media request is unavailable.');

        return [self::NATIVE[$kind], $uuid];
    }

    private function publicId(Model $job): string
    {
        if ($job instanceof WorkspaceMediaJob) {
            return $job->job_id;
        }

        return array_search($job::class, self::NATIVE, true).':'.$job->job_id;
    }

    public function history(User $user, array $filters = []): array
    {
        $offset = $this->offset($filters['cursor'] ?? null);
        $total = 0;
        $rows = collect();
        foreach ($this->historyQueries($user, $filters['kind'] ?? null) as $query) {
            $total += (clone $query)->count();
            $rows = $rows->concat($query->orderByDesc('created_at')->orderByDesc('id')->limit($offset + 31)->get());
        }
        $page = $rows->sort(function (Model $a, Model $b): int {
            return strcmp($b->created_at->format('Y-m-d H:i:s.u'), $a->created_at->format('Y-m-d H:i:s.u')) ?: strcmp($this->publicId($b), $this->publicId($a));
        })->slice($offset, 30)->values();

        return ['jobs' => $page->map(fn (Model $job): array => $this->payload($job))->all(),
            'next_cursor' => $offset + $page->count() < $total ? $this->cursor($offset + $page->count()) : null, 'total' => $total];
    }

    /**
     * Clears one studio's history: finished jobs are deleted exactly like a single delete; running,
     * uncertain or unsaved jobs and originals still referenced by an artifact or reusable asset stay.
     *
     * @return array{deleted_count: int, retained_count: int}
     */
    public function clear(User $user, string $kind): array
    {
        $deleted = 0;
        $retained = 0;
        foreach ($this->historyQueries($user, $kind) as $query) {
            foreach ($query->whereIn('status', ['completed', 'failed', 'cancelled'])->lazyById(100) as $job) {
                try {
                    $this->delete($user, $this->publicId($job));
                    $deleted++;
                } catch (ValidationException|ImageGenerationException) {
                    $retained++;
                } catch (HttpException $exception) {
                    // Deleted concurrently: nothing left to clear or retain.
                    if ($exception->getStatusCode() !== 404) {
                        throw $exception;
                    }
                }
            }
        }

        return ['deleted_count' => $deleted, 'retained_count' => $retained];
    }

    /** @return list<Builder> one owner-scoped query per job table, narrowed to a studio kind when given */
    private function historyQueries(User $user, ?string $kind): array
    {
        $queries = [];
        $workspace = WorkspaceMediaJob::query()->where('user_id', $user->id);
        if ($kind) {
            // Same rule as models() for "other": data, captions and files, which no native studio produces.
            $kind === 'other' ? $workspace->whereNotIn('output_kind', ['image', 'video', 'audio', 'model3d'])
                : $workspace->where('output_kind', $kind === 'avatar' ? 'video' : $kind);
            // Same rule as models(): an avatar-category model belongs to the avatar studio, whatever its operation.
            $avatarModel = static fn ($query) => $query->selectRaw('1')->from('ai_model_profiles')
                ->whereColumn('ai_model_profiles.model_id', 'workspace_media_jobs.model')->where('ai_model_profiles.category', 'avatar');
            if ($kind === 'avatar') {
                $workspace->where(fn (Builder $query) => $query->whereExists($avatarModel)->orWhere('operation', 'talking_avatar'));
            } elseif ($kind === 'video') {
                $workspace->whereNotExists($avatarModel)->where('operation', '!=', 'talking_avatar');
            }
        }
        $queries[] = $workspace;
        foreach (self::NATIVE as $nativeKind => $class) {
            if ($kind && $nativeKind !== ($kind === 'avatar' ? 'video' : $kind)) {
                continue;
            }
            $query = $class::query()->where('user_id', $user->id);
            if ($nativeKind === 'video' && $kind) {
                $query->where('mode', $kind === 'avatar' ? '=' : '!=', 'avatar');
            }
            $queries[] = $query;
        }

        return $queries;
    }

    public function payload(Model $job): array
    {
        $kind = match (true) {
            $job instanceof WorkspaceMediaJob => $job->output_kind, $job instanceof ImageJob => 'image',
            $job instanceof VideoJob => 'video', $job instanceof AudioJob => 'audio', default => 'model3d',
        };
        $operation = $job->operation;
        if (! is_string($operation) || $operation === '') {
            $operation = $job->capability_revision_id ? MediaCapabilityRevision::query()->whereKey($job->capability_revision_id)->value('operation') : null;
            $operation ??= match ($kind) {
                'image' => 'text_to_image', 'video' => $job->mode === 'avatar' ? 'talking_avatar' : ($job->has_reference ? 'image_to_video' : 'text_to_video'),
                'audio' => $job->mode === 'speech' ? 'text_to_speech' : 'music', default => 'image_to_3d',
            };
        }
        $id = $this->publicId($job);
        $cancel = $job instanceof ImageJob ? ['can_cancel' => false, 'cancel_reason' => 'This native image request cannot be cancelled.']
            : ($job instanceof VideoJob ? VideoGenerationService::cancellation($job)
                : ($job instanceof AudioJob ? AudioGenerationService::cancellation($job)
                    : ($job instanceof ThreeDJob ? ThreeDGenerationService::cancellation($job) : $this->cancellation($job))));
        $outputs = [];
        foreach ($this->storedOutputs($job) as $asset) {
            if (! Storage::disk('local')->exists($asset['path'])) {
                continue;
            }
            $previewable = (bool) ($asset['previewable'] ?? false);
            $outputs[] = ['id' => (string) $asset['id'], 'name' => $asset['name'], 'kind' => $asset['kind'],
                'mime' => $asset['mime'], 'bytes' => $asset['bytes'] ?? null, 'previewable' => $previewable,
                'url' => $previewable ? '/api/media/workspace/jobs/'.rawurlencode($id).'/outputs/'.rawurlencode((string) $asset['id']).'/preview' : null,
                'download_url' => WorkspaceMediaOutputStore::downloadUrl($id, (string) $asset['id'])];
        }

        // A native image whose provider acceptance is unknown keeps its reservation and active row; present it truthfully.
        $status = $job instanceof ImageJob && $job->status === 'processing' && $job->stage === 'submission_uncertain' ? 'uncertain' : $job->status;
        if (! $job instanceof WorkspaceMediaJob && $job->status === 'processing' && $job->stage === 'save_failed') {
            $status = 'save_failed';
        }

        return ['id' => $id, 'model' => $job->model, 'operation' => $operation, 'output_kind' => $kind,
            'status' => $status, 'stage' => $job->stage, 'error' => $job->error_message,
            'can_cancel' => (bool) $cancel['can_cancel'], 'cancel_reason' => $cancel['cancel_reason'] ?? $cancel['cancel_unavailable_reason'] ?? null,
            'can_retry_save' => $job instanceof WorkspaceMediaJob
                ? $job->status === 'save_failed' && $job->result_received_at !== null
                : $status === 'save_failed' && $this->hasNativeResult($job),
            'can_delete' => in_array($job->status, ['completed', 'failed', 'cancelled'], true),
            'outputs' => $outputs, 'result_data' => $job instanceof WorkspaceMediaJob
                ? MediaJsonSchema::dataObject(WorkspaceMediaOutputStore::resultSchema($job), $job->result_data) : null,
            // The owner's own normalized request, for "load into form"; never bindings or provider-side fields.
            'request' => $job instanceof WorkspaceMediaJob ? ['model_id' => $job->model, 'operation' => $job->operation,
                'inputs' => MediaJsonSchema::dataObject($job->capability_snapshot['input_schema'] ?? [], $job->normalized_inputs ?? [])] : null,
            'progress' => $job instanceof WorkspaceMediaJob ? $this->progress($job) : null,
            'price_tokens' => $job->price_tokens, 'billing_status' => $job->billing_status, 'details' => $this->details($job),
            'conversation_id' => $job->conversation_id, 'created_at' => $job->created_at?->toISOString(),
            'batch' => $job instanceof ImageJob && $job->batch_key !== null ? $this->batch($job) : null];
    }

    /**
     * The image jobs of one multi-image request, in request order, with this job's position.
     *
     * @return array{jobs: list<string>, index: int}|null
     */
    private function batch(ImageJob $job): ?array
    {
        $ids = ImageJob::query()->where('user_id', $job->user_id)->where('batch_key', $job->batch_key)
            ->orderBy('id')->pluck('job_id')->map(fn (string $id): string => 'image:'.$id)->all();

        // Deleting variations one by one can leave a single image, which is no longer a set.
        return count($ids) > 1 ? ['jobs' => $ids, 'index' => (int) array_search('image:'.$job->job_id, $ids, true)] : null;
    }

    /**
     * Request facts the fixed studios showed (prompt, native settings, legacy billing, the owned
     * reference route). Only the member's own inputs and owner-gated URLs; no provider data.
     */
    private function details(Model $job): array
    {
        if ($job instanceof WorkspaceMediaJob) {
            // Runware tasks name the prompt positivePrompt.
            $prompt = $job->normalized_inputs['prompt'] ?? $job->normalized_inputs['positivePrompt'] ?? null;

            return ['prompt' => is_string($prompt) && trim($prompt) !== '' ? $prompt : null, 'model_label' => $job->model_label,
                'billing_mode' => $job->billing_mode, 'tokens_reserved' => $job->tokens_reserved];
        }
        $prompt = $job->getAttribute('prompt');
        $details = ['prompt' => is_string($prompt) && trim($prompt) !== '' ? $prompt : null, 'model_label' => $job->getAttribute('model_label'),
            'billing_mode' => $job->billing_mode, 'tokens_reserved' => $job->tokens_reserved,
            'cost_microusd' => $job->getAttribute('billing_reserved_microusd')];
        if ($job instanceof ImageJob) {
            return [...$details, 'size' => $job->size];
        }
        if ($job instanceof VideoJob) {
            $referenced = $job->reference_path !== null || (is_array($job->reference_asset_ids) && $job->reference_asset_ids !== []);
            $speech = $job->mode === 'avatar' ? ($job->settings['speech_audio'] ?? null) : null;

            return [...$details, 'mode' => $job->mode, 'aspect_ratio' => $job->aspect_ratio, 'duration' => $job->duration,
                'pro_mode' => (bool) $job->pro_mode, 'improved_prompt' => $job->improved_prompt,
                'cta' => $job->settings['cta'] ?? null, 'ugc_variation' => (bool) ($job->settings['ugc_variation'] ?? false),
                'has_reference' => (bool) $job->has_reference, 'reference_url' => $referenced ? '/api/v/'.$job->job_id.'/reference' : null,
                'speech_audio_url' => is_string($speech) ? '/api/media/assets/'.$speech : null];
        }
        if ($job instanceof AudioJob) {
            return [...$details, 'mode' => $job->mode, 'voice' => $job->voice, 'speed' => $job->speed, 'duration' => $job->duration,
                'tempo' => $job->tempo, 'instrumental' => $job->settings['instrumental'] ?? null, 'custom' => $job->settings['custom'] ?? null];
        }

        return [...$details, 'format' => 'glb', 'preview_unavailable_reason' => $job->getAttribute('preview_unavailable_reason')];
    }

    private function storedOutputs(Model $job): array
    {
        if ($job instanceof WorkspaceMediaJob) {
            return $job->asset_paths ?? [];
        }
        if ($job instanceof ImageJob) {
            $out = [];
            foreach (GeneratedImageStore::outputs($job) as $index => $asset) {
                $mime = $asset['mime'] ?? 'application/octet-stream';
                $out[] = [...$asset, 'id' => (string) $index, 'name' => 'image-'.((int) $index + 1).'.'.pathinfo($asset['path'], PATHINFO_EXTENSION),
                    'mime' => $mime, 'kind' => 'image', 'previewable' => in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)];
            }

            return $out;
        }
        if ($job instanceof VideoJob) {
            return $job->video_url ? [['id' => '0', 'path' => GeneratedVideoStore::path($job->job_id), 'name' => 'video.mp4',
                'mime' => 'video/mp4', 'kind' => 'video', 'previewable' => true]] : [];
        }
        if ($job instanceof AudioJob) {
            $out = [];
            foreach ($job->outputs ?? [] as $index => $asset) {
                if (($path = GeneratedAudioStore::outputPath($job, (int) $index)) !== null) {
                    // GeneratedAudioStore records mime_type/size_bytes for every native track.
                    $mime = $asset['mime_type'] ?? $asset['mime'] ?? 'application/octet-stream';
                    $out[] = [...$asset, 'path' => $path, 'id' => (string) $index, 'name' => 'audio-'.((int) $index + 1).'.'.(GeneratedAudioStore::extensionForMime($mime) ?? 'bin'),
                        'mime' => $mime, 'bytes' => $asset['size_bytes'] ?? null, 'kind' => 'audio', 'previewable' => str_starts_with($mime, 'audio/')];
                }
            }

            return $out;
        }

        return $job->model_path ? [['id' => '0', 'path' => $job->model_path, 'name' => 'model.glb',
            'mime' => $job->mime_type, 'kind' => 'model3d', 'bytes' => $job->size_bytes, 'previewable' => (bool) $job->previewable]] : [];
    }

    public function resolveOwnedOutput(User $user, string $jobId, string $outputId): array
    {
        $job = $this->owned($user, $jobId);
        foreach ($this->storedOutputs($job) as $asset) {
            if ((string) $asset['id'] !== $outputId) {
                continue;
            }
            $path = $asset['path'];
            abort_unless(is_string($path) && ! str_contains($path, '..') && str_starts_with($path, 'generated/') && Storage::disk('local')->exists($path), 404);

            return [...$asset, 'disk' => 'local', 'size' => $asset['bytes'] ?? Storage::disk('local')->size($path),
                'bytes' => $asset['bytes'] ?? Storage::disk('local')->size($path), 'conversation_id' => $job->conversation_id,
                'job_id' => $this->publicId($job), 'output_id' => $outputId];
        }
        abort(404);
    }

    private function cancellation(WorkspaceMediaJob $job): array
    {
        $canCancel = $job->status === 'pending' && $job->stage === 'queued' && $job->submitted_at === null && $job->upstream_job_id === null;

        return ['can_cancel' => $canCancel, 'cancel_reason' => $canCancel ? null :
            'Submission has started or the request is finished. Upstream cancellation is not confirmed; stopping this page does not cancel or refund it.'];
    }

    public function cancel(User $user, string $id): Model
    {
        $job = $this->owned($user, $id);
        if ($job instanceof VideoJob) {
            return app(VideoGenerationService::class)->cancel($user, $job->job_id);
        }
        if ($job instanceof AudioJob) {
            return app(AudioGenerationService::class)->cancel($user, $job->job_id);
        }
        if ($job instanceof ThreeDJob) {
            return app(ThreeDGenerationService::class)->cancel($user, $job->job_id);
        }
        if (! $job instanceof WorkspaceMediaJob) {
            throw new ImageGenerationException('This request cannot be cancelled.', 409);
        }

        return DB::transaction(function () use ($user, $job): WorkspaceMediaJob {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked = WorkspaceMediaJob::query()->lockForUpdate()->findOrFail($job->id);
            if (! $this->cancellation($locked)['can_cancel']) {
                throw new ImageGenerationException('Submission has started. Cancellation is not confirmed and tokens have not been refunded.', 409);
            }
            $this->tokens->release($locked->user_id, $this->reservation($locked), 'Cancelled queued workspace media request');
            $locked->update(['status' => 'cancelled', 'stage' => 'cancelled', 'billing_status' => 'released', 'completed_at' => now(), 'next_poll_at' => null]);

            return $locked;
        });
    }

    public function retrySave(User $user, string $id): Model
    {
        $job = $this->owned($user, $id);
        if (! $job instanceof WorkspaceMediaJob) {
            return DB::transaction(function () use ($user, $job): Model {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $locked = $job->newQuery()->lockForUpdate()->findOrFail($job->id);
                if ($locked->status !== 'processing' || $locked->stage !== 'save_failed' || ! $this->hasNativeResult($locked)) {
                    throw new ImageGenerationException('This request has no failed output save to retry.', 409);
                }
                $changes = ['stage' => 'saving', 'error_message' => null, 'processing_started_at' => null, 'next_poll_at' => now()];
                if (! $locked instanceof VideoJob) {
                    $changes['processing_token'] = null;
                }
                $locked->update($changes);
                DB::afterCommit(function () use ($locked): void {
                    $poll = match (true) {
                        $locked instanceof ImageJob => new PollImageJob($locked->id),
                        $locked instanceof VideoJob => new PollVideoJob($locked->id),
                        $locked instanceof AudioJob => new PollAudioJob($locked->id),
                        $locked instanceof ThreeDJob => new PollThreeDJob($locked->id),
                    };
                    try {
                        dispatch($poll->onConnection('media')->onQueue('media'));
                    } catch (Throwable) {
                        $locked->newQuery()->whereKey($locked->id)->where('status', 'processing')->where('stage', 'saving')
                            ->whereNull('processing_started_at')->update(['stage' => 'save_failed', 'next_poll_at' => null,
                                'error_message' => 'Saving could not be queued. Retry saving the original result.']);
                        $locked->refresh();
                    }
                });

                return $locked;
            });
        }
        $saved = DB::transaction(function () use ($job): WorkspaceMediaJob {
            $locked = WorkspaceMediaJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($locked->status !== 'save_failed' || $locked->result_received_at === null) {
                throw new ImageGenerationException('This request has no failed output save to retry.', 409);
            }
            $locked->update(['status' => 'processing', 'stage' => 'saving', 'error_message' => null,
                'processing_token' => null, 'processing_started_at' => null, 'next_poll_at' => now()]);
            DB::afterCommit(fn () => $this->queuePoll($locked->id, 0));

            return $locked;
        });

        return $saved;
    }

    private function hasNativeResult(Model $job): bool
    {
        return match (true) {
            $job instanceof ImageJob => ! empty($job->provider_result_urls) || ! empty($job->provider_result_data['data']),
            $job instanceof VideoJob, $job instanceof ThreeDJob => is_string($job->provider_result_url) && $job->provider_result_url !== '',
            $job instanceof AudioJob => ! empty($job->provider_result_urls),
            default => false,
        };
    }

    public function delete(User $user, string $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            [$class, $uuid] = $this->identity($id);
            $job = $class::query()->where('user_id', $user->id)->where('job_id', $uuid)->lockForUpdate()->first();
            abort_if($job === null, 404, 'This media request is unavailable.');
            if (! in_array($job->status, ['completed', 'failed', 'cancelled'], true)) {
                throw new ImageGenerationException('Active, uncertain, or unsaved generation results cannot be deleted.', 409);
            }
            $this->quota->assertJobUnreferenced($user, $this->publicId($job));
            foreach ($this->storedOutputs($job) as $asset) {
                Storage::disk('local')->delete($asset['path']);
            }
            if ($job instanceof VideoJob && empty($job->reference_asset_ids)) {
                $references = app(VideoReferenceStore::class);
                if (($path = $references->existingPath($job)) !== null) {
                    $references->delete($path);
                }
            }
            // Private originals are removed exactly as each native studio deletes its own job.
            if ($job instanceof WorkspaceMediaJob) {
                Storage::disk('local')->deleteDirectory(WorkspaceMediaOutputStore::directory($job->job_id));
            } elseif ($job instanceof AudioJob) {
                Storage::disk('local')->deleteDirectory(GeneratedAudioStore::directory($job->job_id));
            } elseif ($job instanceof VideoJob) {
                Storage::disk('local')->delete(GeneratedVideoStore::path($job->job_id));
            } elseif ($job instanceof ThreeDJob) {
                Storage::disk('local')->deleteDirectory(dirname(GeneratedModel3dStore::path($job->job_id)));
            }
            $job->delete();
        });
    }

    private function permission(string $category, array $definition): string
    {
        $kind = $definition['output_kind'] ?? '';
        if (in_array($category, ['video', 'avatar'], true) || $kind === 'video') {
            return 'video_generator';
        }
        if ($category === 'audio' || $kind === 'audio') {
            return 'audio_generator';
        }
        if (in_array($category, ['image', 'model3d', 'vision', 'training'], true) || in_array($kind, ['image', 'model3d'], true)) {
            return 'image_generator';
        }
        $operation = $definition['operation'] ?? '';
        if (str_contains($operation, 'audio') || str_contains($operation, 'speech') || str_contains($operation, 'transcri')) {
            return 'audio_generator';
        }
        if (str_contains($operation, 'video')) {
            return 'video_generator';
        }
        if (str_contains($operation, 'image') || str_contains($operation, 'vision') || str_contains($operation, '3d')) {
            return 'image_generator';
        }

        return 'chat';
    }

    private function offset(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $decoded = base64_decode($cursor, true);
        if (! is_string($decoded) || ! ctype_digit($decoded) || (int) $decoded > 1000000) {
            throw ValidationException::withMessages(['cursor' => 'This pagination cursor is invalid.']);
        }

        return (int) $decoded;
    }

    private function cursor(int $offset): string
    {
        return base64_encode((string) $offset);
    }
}
