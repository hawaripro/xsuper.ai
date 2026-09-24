<?php

namespace App\Services;

use App\Exceptions\AiProviderRequestRejected;
use App\Exceptions\AiProxyException;
use App\Exceptions\ImageGenerationException;
use App\Media\AssetService;
use App\Media\CapabilityResolver;
use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Exceptions\CapabilityConfigException;
use App\Media\Exceptions\CapabilityValidationException;
use App\Media\MediaActivation;
use App\Media\MediaCapability;
use App\Media\MediaJsonSchema;
use App\Media\MediaReferenceStager;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaAsset;
use App\Models\MediaCapabilityRevision;
use App\Models\RealtimeMediaSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/**
 * Finite WMA/WebRTC sessions. The browser owns the peer connection and the control data channel;
 * this service alone talks to the authenticated bridge, holds the heartbeat lease, prepares
 * versioned control messages and bills one reviewed per-session tariff on authenticated upstream
 * acceptance. Nothing here renews, reconnects, replays or refunds an accepted or uncertain session.
 */
class RealtimeMediaService
{
    /** Chrome's SCTP message ceiling; a larger control message cannot cross the data channel. */
    public const MAX_CONTROL_BYTES = 262_144;

    private const MAX_SESSION_CEILING = 60;
    private const MAX_SDP_BYTES = 262_144;
    private const HEARTBEAT_MIN_INTERVAL_SECONDS = 2;
    private const HEARTBEAT_MAX_STRIKES = 3;
    private const PREPARING_STALE_SECONDS = 600;
    private const NEGOTIATING_STALE_SECONDS = 150;
    private const MAX_CONTROL_LOG = 256;
    private const MAX_SAFE_INTEGER = 9_007_199_254_740_991;
    private const DEFAULT_STUN = 'stun:stun.l.google.com:19302';
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly CapabilityResolver $resolver,
        private readonly MediaActivation $activation,
        private readonly MediaTokenBillingService $tokens,
        private readonly AssetService $assets,
        private readonly MediaReferenceStager $stager,
        private readonly AiProviderTransport $transport,
    ) {}

    /** Effective bound: the reviewed source-normalized bound, only ever reduced by deployment config. */
    public static function maxSessionSeconds(array $bindings = []): int
    {
        $clamp = static fn (mixed $value): int => max(1, min(self::MAX_SESSION_CEILING,
            is_numeric($value) ? (int) $value : self::MAX_SESSION_CEILING));

        return min($clamp($bindings['max_session_seconds'] ?? self::MAX_SESSION_CEILING),
            $clamp(config('realtime_media.max_session_seconds', self::MAX_SESSION_CEILING)));
    }

    /**
     * Short-lived ICE credentials must exist before the browser creates its one non-trickle offer.
     * Mirrors the official client: bridge first, then the app-managed route, then truthful STUN only.
     *
     * @return array{ice_servers: list<array<string, mixed>>, source: string}
     */
    public function iceServers(User $user, array $request): array
    {
        $admitted = $this->admit($user, $request, false);
        $provider = $admitted['model']->provider;
        $endpoint = $admitted['bindings']['endpoint'];
        try {
            $servers = $this->iceList($this->transport->postFalRealtime($provider, '/ice', ['app_id' => $endpoint])['ice_servers'] ?? null);
            if ($servers !== []) {
                return ['ice_servers' => $servers, 'source' => 'bridge'];
            }
        } catch (AiProxyException) {
            // An unavailable or app-managed bridge vendor falls through to the app route, as in the official client.
        }
        try {
            $data = $this->transport->runFalDirect($provider, $endpoint.'/ice', []);
            $servers = $this->iceList(is_array($data) ? ($data['ice_servers'] ?? null) : null);
            if ($servers !== []) {
                return ['ice_servers' => $servers, 'source' => 'app'];
            }
        } catch (AiProxyException) {
            // Report the STUN-only path truthfully; restrictive networks may not connect without a relay.
        }

        return ['ice_servers' => [['urls' => [self::DEFAULT_STUN]]], 'source' => 'stun'];
    }

    /**
     * Reserve once, stage owned files, then make exactly one authenticated bridge offer.
     *
     * @return array{session: RealtimeMediaSession, answer?: array, configure?: array, session_token?: string, protocol?: array}
     */
    public function start(User $user, array $request): array
    {
        $key = trim((string) ($request['idempotency_key'] ?? ''));
        if ($key === '' || strlen($key) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'A stable request key is required.']);
        }
        if (! is_array($request['inputs'] ?? null)) {
            throw ValidationException::withMessages(['inputs' => 'An input object is required.']);
        }
        $sdp = $this->offer($request['sdp'] ?? null);
        $requestKey = hash('sha256', $key);
        $offerFingerprint = hash('sha256', $sdp);
        $this->reconcile((int) $user->id);

        $admission = DB::transaction(function () use ($user, $request, $requestKey, $offerFingerprint): array {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = RealtimeMediaSession::query()->where('user_id', $owner->id)->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return ['replay' => $this->replay($existing, $request, $offerFingerprint)];
            }
            $admitted = $this->admit($owner, $request, true);
            ['model' => $model, 'resolved' => $resolved, 'bindings' => $bindings, 'price' => $price] = $admitted;
            $hash = $request['expected_capability_hash'] ?? null;
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw ValidationException::withMessages(['expected_capability_hash' => 'A current capability quote is required.']);
            }
            if (! hash_equals($resolved->sourceHash, $hash)) {
                throw new ImageGenerationException('This model changed. Review the current options before starting a session.', 409);
            }
            $expected = $request['expected_price_tokens'] ?? null;
            if (! is_int($expected) || $expected < 1) {
                throw ValidationException::withMessages(['expected_price_tokens' => 'A current session price is required.']);
            }
            if ($expected !== $price) {
                throw new ImageGenerationException('The session price changed. Review the current price before starting.', 409);
            }
            if (RealtimeMediaSession::query()->where('user_id', $owner->id)->whereIn('status', RealtimeMediaSession::ACTIVE_STATUSES)->exists()) {
                throw new ImageGenerationException('Another realtime session is still running. Stop it before starting a new one.', 409);
            }
            $capability = $resolved->capability;
            $inputs = $this->setupInputs($capability, $bindings, $request['inputs']);
            $references = $this->references($capability->inputSchema, $inputs);
            $this->assertAssets($owner, $references);
            $this->assertControlSize(['type' => 'configure', 'protocol_version' => 1, 'prompt_version' => 1, ...$inputs], count($references));
            $id = (string) Str::uuid();
            $token = Str::random(64);
            $reservation = $this->tokens->reserve($owner, 'media', $model->model_id, 1, 'realtime:'.$id, $price);
            $session = new RealtimeMediaSession([
                'user_id' => $owner->id, 'provider_id' => $model->provider_id, 'capability_revision_id' => $resolved->revisionId,
                'model' => $model->model_id, 'model_label' => mb_substr((string) ($model->display_name ?: $model->model_id), 0, 255),
                'request_key' => $requestKey, 'payload_fingerprint' => $this->fingerprint($model->model_id, $inputs, $hash, $price),
                'connection_fingerprint' => self::providerFingerprint($model->provider), 'offer_fingerprint' => $offerFingerprint,
                'session_token_hash' => hash('sha256', $token), 'capability_hash' => $hash,
                'capability_snapshot' => $capability->toArray(), 'provider_bindings' => $bindings, 'normalized_inputs' => $inputs,
                'asset_ids' => array_values(array_unique(array_column($references, 'asset_id'))), 'recording_asset_ids' => [],
                'control_updates' => [], 'billing_reservation' => $reservation, 'price_tokens' => $price,
                'billing_status' => 'reserved', 'status' => 'preparing', 'max_session_seconds' => $admitted['seconds'],
                'next_prompt_version' => 2, 'heartbeat_failures' => 0,
            ]);
            $session->id = $id;
            $session->save();

            return ['session' => $session, 'token' => $token, 'provider' => $model->provider];
        });

        return $admission['replay']
            ?? $this->negotiate($admission['session'], $admission['provider'], $sdp, $admission['token']);
    }

    /** The owner's tab keeps the lease; the backend refuses to extend it past the reviewed bound. */
    public function heartbeat(User $user, string $id, array $request): array
    {
        $session = $this->expireIfDue($this->controlled($user, $id, $request['session_token'] ?? null));
        if ($session->status !== 'connected') {
            return ['alive' => false, 'session' => $session];
        }
        if (($request['configured'] ?? false) === true && $session->configured_observed_at === null) {
            // Browser-observed protocol state: it gates update preparation, never billing.
            RealtimeMediaSession::query()->whereKey($session->id)->whereNull('configured_observed_at')->update(['configured_observed_at' => now()]);
            $session->refresh();
        }
        $claimed = RealtimeMediaSession::query()->whereKey($session->id)->where('status', 'connected')
            ->where(fn ($query) => $query->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<=', now()->subSeconds(self::HEARTBEAT_MIN_INTERVAL_SECONDS)))
            ->update(['last_heartbeat_at' => now()]);
        if ($claimed !== 1) {
            // Another beat was forwarded within the minimum interval; report the lease without fanning out upstream.
            $session->refresh();

            return ['alive' => $session->status === 'connected', 'session' => $session];
        }
        try {
            $provider = $this->provider($session);
        } catch (AiProxyException $exception) {
            return ['alive' => false, 'session' => $this->end($session, 'failed', 'provider_changed', $exception->getMessage())];
        }
        try {
            $data = $this->transport->postFalRealtime($provider, '/session/heartbeat', ['session_id' => (string) $session->upstream_session_id]);
        } catch (AiProviderRequestRejected) {
            return $this->strike($session);
        } catch (AiProxyException $exception) {
            // An unreadable success is as dead as a rejection; a timeout or unavailable bridge says nothing.
            return $exception->responseStatus() === 502 ? $this->strike($session) : ['alive' => true, 'degraded' => true, 'session' => $session->refresh()];
        }
        if (! is_bool($data['alive'] ?? null)) {
            return $this->strike($session);
        }
        if ($data['alive'] === false) {
            return ['alive' => false, 'session' => $this->end($session, 'closed', 'provider_ended', 'The provider reports that this session is no longer alive.')];
        }
        RealtimeMediaSession::query()->whereKey($session->id)->update(['heartbeat_failures' => 0]);

        return ['alive' => true, 'session' => $session->refresh()];
    }

    /**
     * Validate, stage and version one control update. The browser sends the returned message on its
     * data channel exactly once; a version is never reissued, so gaps mean uncertain delivery.
     *
     * @return array{message: array, prompt_version: int, session: RealtimeMediaSession}
     */
    public function prepareInput(User $user, string $id, array $request): array
    {
        $session = $this->expireIfDue($this->controlled($user, $id, $request['session_token'] ?? null));
        if ($session->status !== 'connected') {
            throw new ImageGenerationException('This session is no longer running. Updates are never sent to an ended session.', 409);
        }
        if ($session->configured_observed_at === null) {
            throw new ImageGenerationException('Wait until the model confirms the session setup before sending updates.', 409);
        }
        $bindings = $session->provider_bindings ?? [];
        $publicSchema = is_array($bindings['public_update_schema'] ?? null) ? $bindings['public_update_schema'] : [];
        $inputs = $this->updateInputs($bindings, $publicSchema, is_array($request['inputs'] ?? null) ? $request['inputs'] : []);
        $references = $this->references($publicSchema, $inputs);
        $this->assertAssets($user, $references);
        $this->assertControlSize(['type' => 'prompt', 'prompt_version' => self::MAX_SAFE_INTEGER, ...$inputs], count($references));
        $provider = $this->provider($session);
        try {
            $body = $this->controlMessage($bindings['update_schema'] ?? [], $publicSchema, ['type' => 'prompt', 'prompt_version' => 1],
                $inputs, $provider, (int) $user->id);
        } catch (AiProxyException $exception) {
            throw new ImageGenerationException('The update files could not be prepared for the provider. Nothing was sent.', $exception->responseStatus() === 422 ? 422 : 502);
        } catch (Throwable) {
            throw new ImageGenerationException('This update cannot be sent over the realtime control channel.', 422);
        }
        $assetIds = array_values(array_unique(array_column($references, 'asset_id')));
        $version = DB::transaction(function () use ($session, $body, $assetIds): int {
            $locked = RealtimeMediaSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== 'connected' || $locked->expires_at === null || ! $locked->expires_at->isFuture()) {
                throw new ImageGenerationException('This session is no longer running. Updates are never sent to an ended session.', 409);
            }
            $version = (int) $locked->next_prompt_version;
            if ($version < 2 || $version >= self::MAX_SAFE_INTEGER) {
                throw new ImageGenerationException('This session has no remaining update versions.', 409);
            }
            $fields = array_values(array_diff(array_keys($body), ['type', 'prompt_version']));
            $log = [...($locked->control_updates ?? []), ['version' => $version, 'issued_at' => now()->toISOString(),
                'fields' => $fields, 'asset_ids' => $assetIds]];
            $locked->update(['next_prompt_version' => $version + 1, 'control_updates' => array_slice($log, -self::MAX_CONTROL_LOG),
                'asset_ids' => array_values(array_unique([...($locked->asset_ids ?? []), ...$assetIds]))]);

            return $version;
        });

        return ['message' => [...$body, 'prompt_version' => $version], 'prompt_version' => $version, 'session' => $session->refresh()];
    }

    /** Stopping ends the lease. It never refunds an accepted session; only an unsent offer is refundable. */
    public function close(User $user, string $id, array $request = []): RealtimeMediaSession
    {
        $detail = $request['detail'] ?? null;
        $detail = is_string($detail) && preg_match('/^[a-z0-9_]{1,48}$/D', $detail) === 1 ? $detail : null;
        [$status, $reason] = match ($request['reason'] ?? 'stopped') {
            'exhausted' => ['exhausted', $detail ?? 'stream_exhausted'],
            'failed' => ['failed', $detail ?? 'connection_failed'],
            'expired' => ['expired', 'session_limit'],
            'left' => ['closed', 'left'],
            default => ['closed', 'stopped'],
        };

        return DB::transaction(function () use ($user, $id, $status, $reason): RealtimeMediaSession {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $session = RealtimeMediaSession::query()->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($session->status === 'preparing' && $session->billing_status === 'reserved') {
                // The bridge has not been contacted: this is the only stop that returns tokens.
                $this->tokens->release($session->user_id, $session->billing_reservation, 'Realtime session stopped before it started');
                $session->update(['status' => 'closed', 'billing_status' => 'released', 'terminal_reason' => 'stopped_before_start',
                    'closed_at' => now(), 'answer_sdp' => null, 'configure_message' => null]);
            } elseif ($session->status === 'negotiating') {
                // The offer is already in flight; an accepted session is still charged and immediately ended.
                $session->update(['close_requested_at' => $session->close_requested_at ?? now()]);
            } elseif ($session->status === 'connected') {
                $expired = $session->expires_at !== null && ! $session->expires_at->isFuture();
                $session->update([
                    'status' => $expired ? 'expired' : $status, 'terminal_reason' => $expired ? 'session_limit' : $reason,
                    'error_message' => ! $expired && $status === 'failed'
                        ? 'The browser reported that the live session failed. An accepted session is not refunded.' : null,
                    'closed_at' => now(), 'answer_sdp' => null, 'configure_message' => null,
                ]);
            }

            return $session;
        });
    }

    /** Persist real browser-recorded bytes of the received stream as an ordinary owned video asset. */
    public function attachRecording(User $user, string $id, UploadedFile $file): array
    {
        $session = $this->owned($user, $id);
        $limit = $this->recordingLimit();
        if ($session->accepted_at === null) {
            throw new ImageGenerationException('Only a session that connected can have a recording.', 409);
        }
        if (count($session->recording_asset_ids ?? []) >= $limit['max_files']) {
            throw new ImageGenerationException('This session already has the maximum number of saved recordings.', 409);
        }
        if ((int) $file->getSize() < 1 || (int) $file->getSize() > $limit['max_bytes']) {
            throw ValidationException::withMessages(['file' => 'The recording is empty or exceeds the saved-recording size limit.']);
        }
        try {
            $asset = $this->assets->store($user, $file, InputRole::ReferenceVideo);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }
        $attached = DB::transaction(function () use ($user, $session, $asset, $limit): ?RealtimeMediaSession {
            $locked = RealtimeMediaSession::query()->where('user_id', $user->id)->lockForUpdate()->find($session->id);
            if ($locked === null || count($locked->recording_asset_ids ?? []) >= $limit['max_files']) {
                return null;
            }
            $locked->update(['recording_asset_ids' => [...($locked->recording_asset_ids ?? []), $asset->id]]);

            return $locked;
        });
        if ($attached === null) {
            Storage::disk($asset->storage_disk)->delete($asset->storage_path);
            $asset->forceFill(['retention_status' => 'deleted'])->save();
            throw new ImageGenerationException('This session already has the maximum number of saved recordings.', 409);
        }

        return ['recording' => ['available' => true, ...$this->assets->present($asset)], 'session' => $attached];
    }

    public function history(User $user, array $filters = []): array
    {
        $this->reconcile((int) $user->id);
        $offset = $this->offset($filters['cursor'] ?? null);
        $rows = RealtimeMediaSession::query()->where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')
            ->skip($offset)->take(self::PAGE_SIZE + 1)->get();
        $page = $rows->take(self::PAGE_SIZE);
        $assets = $this->recordingAssets((int) $user->id, $page->all());

        return ['sessions' => $page->map(fn (RealtimeMediaSession $session): array => $this->payload($session, $assets))->values()->all(),
            'next_cursor' => $rows->count() > self::PAGE_SIZE ? base64_encode((string) ($offset + self::PAGE_SIZE)) : null];
    }

    public function show(User $user, string $id): RealtimeMediaSession
    {
        $this->reconcile((int) $user->id);

        return $this->owned($user, $id);
    }

    public function owned(User $user, string $id): RealtimeMediaSession
    {
        return RealtimeMediaSession::query()->where('user_id', $user->id)->whereKey($id)->firstOrFail();
    }

    /** Member-facing session state; provider identity, bridge ids, offers and staged URLs never leave the server. */
    public function payload(RealtimeMediaSession $session, ?array $assets = null): array
    {
        $assets ??= $this->recordingAssets((int) $session->user_id, [$session]);
        $recordings = [];
        foreach ($session->recording_asset_ids ?? [] as $assetId) {
            $asset = is_string($assetId) ? ($assets[$assetId] ?? null) : null;
            $recordings[] = $asset !== null && $asset->signature_ok && $asset->retention_status === 'active'
                && ($asset->expires_at === null || $asset->expires_at->isFuture())
                ? ['available' => true, ...$this->assets->present($asset)]
                : ['id' => is_string($assetId) ? $assetId : null, 'available' => false];
        }
        $remaining = $session->status === 'connected' && $session->expires_at !== null
            ? max(0, $session->expires_at->getTimestampMs() - now()->getTimestampMs()) : null;

        return [
            'id' => $session->id, 'model' => $session->model, 'model_label' => $session->model_label,
            'operation' => MediaOperation::RealtimeVideo->value, 'output_kind' => 'video', 'transport' => 'realtime',
            'status' => $session->status, 'active' => in_array($session->status, RealtimeMediaSession::ACTIVE_STATUSES, true),
            'stop_requested' => $session->close_requested_at !== null,
            'billing_mode' => 'tokens', 'billing_status' => $session->billing_status, 'price_tokens' => $session->price_tokens,
            'price_unit' => 'session', 'max_session_seconds' => $session->max_session_seconds, 'remaining_ms' => $remaining,
            'configured' => $session->configured_observed_at !== null, 'updates_issued' => count($session->control_updates ?? []),
            'terminal_reason' => $session->terminal_reason, 'error_message' => $session->error_message,
            'settings' => $this->settings($session->normalized_inputs ?? []),
            'recordings' => $recordings, 'recording_limit' => $this->recordingLimit(),
            'created_at' => $session->created_at?->toISOString(), 'accepted_at' => $session->accepted_at?->toISOString(),
            'expires_at' => $session->expires_at?->toISOString(), 'closed_at' => $session->closed_at?->toISOString(),
        ];
    }

    /** Status-only recovery. It never re-submits, renews, or refunds an accepted or uncertain session. */
    public function reconcile(?int $userId = null): array
    {
        $counts = ['expired' => 0, 'abandoned' => 0, 'uncertain' => 0];
        $scoped = static fn ($query) => $userId === null ? $query : $query->where('user_id', $userId);
        $scoped(RealtimeMediaSession::query()->where('status', 'connected')->where('expires_at', '<=', now()))
            ->chunkById(100, function ($sessions) use (&$counts): void {
                foreach ($sessions as $session) {
                    $counts['expired'] += $this->end($session, 'expired', 'session_limit')->status === 'expired' ? 1 : 0;
                }
            });
        $scoped(RealtimeMediaSession::query()->where('status', 'preparing')->where('created_at', '<=', now()->subSeconds(self::PREPARING_STALE_SECONDS)))
            ->chunkById(100, function ($sessions) use (&$counts): void {
                foreach ($sessions as $session) {
                    // A preparing row has no durable offer marker, so the bridge was never contacted.
                    $counts['abandoned'] += $this->release($session, 'preparing', 'preparation_interrupted',
                        'Preparation was interrupted before the provider was contacted. The tokens were returned.') ? 1 : 0;
                }
            });
        $scoped(RealtimeMediaSession::query()->where('status', 'negotiating')->where('offered_at', '<=', now()->subSeconds(self::NEGOTIATING_STALE_SECONDS)))
            ->chunkById(100, function ($sessions) use (&$counts): void {
                foreach ($sessions as $session) {
                    $counts['uncertain'] += $this->uncertain($session) ? 1 : 0;
                }
            });

        return $counts;
    }

    /** Eligibility shared by ICE discovery and paid admission; never reserves or contacts the bridge. */
    private function admit(User $user, array $request, bool $lock): array
    {
        if (($request['operation'] ?? null) !== MediaOperation::RealtimeVideo->value) {
            throw ValidationException::withMessages(['operation' => 'Select the realtime video operation.']);
        }
        $query = AiModelProfile::query()->with('provider')->where('model_id', (string) ($request['model'] ?? ''));
        $model = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($model === null) {
            throw new ImageGenerationException('This realtime model is not available.', 404);
        }
        $this->activation->assertNotPaused();
        $price = $model->token_cost;
        if ($user->is_active === false || $user->isExpired() || ! $this->activation->usesCoordinator($user)
            || ! $user->hasPermission('video_generator') || ! is_int($price) || $price < 1 || $price > 2_147_483_647
            || ! in_array(MediaOperation::RealtimeVideo->value, MediaModelConfig::workspaceOperations($user, $model), true)) {
            throw new ImageGenerationException('This operation is unavailable or not permitted for your account.', 403);
        }
        try {
            $resolved = $this->resolver->resolve($model, MediaOperation::RealtimeVideo, schemaContracts: true);
        } catch (CapabilityConfigException) {
            throw new ImageGenerationException('This realtime model is not currently available.', 409);
        }
        $bindings = $resolved->capability->providerBindings;
        $provider = $model->provider;
        if ($resolved->revisionId === null || ($bindings['adapter'] ?? null) !== 'fal_wma_v1'
            || ($bindings['transport'] ?? null) !== 'realtime' || ! is_string($bindings['endpoint'] ?? null)
            || ! is_array($bindings['configure_schema'] ?? null) || ! is_array($bindings['update_schema'] ?? null)
            || ! is_array($bindings['public_update_schema'] ?? null) || $provider === null || ! $provider->is_enabled
            || $provider->protocol !== 'fal' || trim((string) $provider->base_url) === '' || trim((string) $provider->api_key) === '') {
            throw new ImageGenerationException('This realtime model is not currently available.', 409);
        }
        $revision = MediaCapabilityRevision::query()->find($resolved->revisionId);
        $pricing = $revision?->curation_overrides['pricing'] ?? [];
        $seconds = self::maxSessionSeconds($bindings);
        // A per-session price covers one bounded session; config can never extend the reviewed bound.
        if ($revision === null || ! $revision->hasReviewedPrice($price) || ! in_array($pricing['unit'] ?? null, ['request', 'generation'], true)
            || (int) ($pricing['max_session_seconds'] ?? 0) < $seconds) {
            throw new ImageGenerationException('This realtime session price is waiting for administrator review.', 409);
        }

        return ['model' => $model, 'resolved' => $resolved, 'bindings' => $bindings, 'price' => $price, 'seconds' => $seconds];
    }

    private function replay(RealtimeMediaSession $session, array $request, string $offerFingerprint): array
    {
        try {
            $capability = MediaCapability::fromArray($session->capability_snapshot);
            $inputs = $this->setupInputs($capability, $session->provider_bindings ?? [], is_array($request['inputs'] ?? null) ? $request['inputs'] : []);
            $fingerprint = $this->fingerprint((string) ($request['model'] ?? ''), $inputs,
                (string) ($request['expected_capability_hash'] ?? ''), (int) ($request['expected_price_tokens'] ?? 0));
        } catch (Throwable) {
            throw new ImageGenerationException('This request key was already used with different input.', 409);
        }
        if (($request['operation'] ?? null) !== MediaOperation::RealtimeVideo->value || ! hash_equals($session->payload_fingerprint, $fingerprint)
            || ! is_string($session->offer_fingerprint) || ! hash_equals($session->offer_fingerprint, $offerFingerprint)) {
            throw new ImageGenerationException('This request key was already used with different input.', 409);
        }
        if (! $this->usable($session)) {
            return $this->result($session);
        }
        // The same tab lost the response; a fresh token supersedes the undelivered one.
        $token = Str::random(64);
        $session->update(['session_token_hash' => hash('sha256', $token)]);

        return $this->result($session, $token);
    }

    private function negotiate(RealtimeMediaSession $session, AiProviderProfile $provider, string $sdp, string $token): array
    {
        // An accepted, billable session must be recorded even if the browser disconnects meanwhile.
        ignore_user_abort(true);
        $bindings = $session->provider_bindings;
        try {
            $configure = $this->controlMessage($bindings['configure_schema'], $session->capability_snapshot['input_schema'] ?? [],
                ['type' => 'configure', 'protocol_version' => 1, 'prompt_version' => 1], $session->normalized_inputs ?? [], $provider, (int) $session->user_id);
        } catch (Throwable) {
            $this->release($session, 'preparing', 'preparation_failed',
                'The session inputs could not be prepared for the provider. No session was started and the tokens were returned.');

            return $this->result($session->refresh());
        }
        $claimed = DB::transaction(function () use ($session): bool {
            $locked = RealtimeMediaSession::query()->lockForUpdate()->find($session->id);
            if ($locked === null || $locked->status !== 'preparing') {
                return false;
            }
            // Durable marker before the one paid POST: recovery can never treat this offer as unsent.
            $locked->update(['status' => 'negotiating', 'offered_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return $this->result($session->refresh());
        }
        try {
            $data = $this->transport->postFalRealtime($provider, '/session', ['app_id' => $bindings['endpoint'], 'sdp' => $sdp, 'type' => 'offer']);
        } catch (AiProviderRequestRejected $exception) {
            // A stable message stays translatable; the upstream status remains visible in the terminal reason.
            $this->release($session, 'negotiating', 'rejected_'.$exception->upstreamStatus,
                'The provider rejected this session before it started. The tokens were returned.');

            return $this->result($session->refresh());
        } catch (Throwable) {
            $this->uncertain($session);

            return $this->result($session->refresh());
        }
        $answer = $this->answer($data);
        if ($answer === null) {
            $this->uncertain($session);

            return $this->result($session->refresh());
        }
        $accepted = $this->accept($session, $answer, $configure, $token);

        return $this->result($accepted, $accepted->status === 'connected' ? $token : null);
    }

    /** Authenticated acceptance is billable exactly once, even when the owner already asked to stop. */
    private function accept(RealtimeMediaSession $session, array $answer, array $configure, string $token): RealtimeMediaSession
    {
        return DB::transaction(function () use ($session, $answer, $configure, $token): RealtimeMediaSession {
            User::query()->whereKey($session->user_id)->lockForUpdate()->firstOrFail();
            $locked = RealtimeMediaSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->tokens->settle($locked->user_id, $locked->billing_reservation, [
                'service' => 'media', 'model' => $locked->model, 'operation' => MediaOperation::RealtimeVideo->value,
                'price_unit' => 'request', 'max_session_seconds' => $locked->max_session_seconds, 'session' => $locked->id,
            ]);
            $now = now();
            $stop = $locked->close_requested_at !== null || ! in_array($locked->status, ['negotiating', 'uncertain'], true);
            $locked->update([
                'billing_status' => 'charged', 'upstream_session_id' => $answer['session_id'], 'accepted_at' => $now,
                'expires_at' => $now->copy()->addSeconds($locked->max_session_seconds), 'error_message' => null,
                ...($stop
                    ? ['status' => 'closed', 'terminal_reason' => 'stopped_before_connect', 'closed_at' => $now, 'answer_sdp' => null, 'configure_message' => null]
                    : ['status' => 'connected', 'terminal_reason' => null, 'closed_at' => null, 'answer_sdp' => $answer['sdp'],
                        'configure_message' => $configure, 'session_token_hash' => hash('sha256', $token)]),
            ]);

            return $locked;
        });
    }

    private function uncertain(RealtimeMediaSession $session): bool
    {
        return DB::transaction(function () use ($session): bool {
            $locked = RealtimeMediaSession::query()->lockForUpdate()->find($session->id);
            if ($locked === null || $locked->status !== 'negotiating') {
                return false;
            }
            $locked->update(['status' => 'uncertain', 'terminal_reason' => 'acceptance_unknown', 'closed_at' => now(),
                'error_message' => 'The provider did not confirm whether this session started. The tokens stay reserved for review, and the session will not be retried.',
                'answer_sdp' => null, 'configure_message' => null]);

            return true;
        });
    }

    /** Refund only while the bridge definitively never accepted this session. */
    private function release(RealtimeMediaSession $session, string $expected, string $reason, string $message): bool
    {
        return DB::transaction(function () use ($session, $expected, $reason, $message): bool {
            User::query()->whereKey($session->user_id)->lockForUpdate()->firstOrFail();
            $locked = RealtimeMediaSession::query()->lockForUpdate()->find($session->id);
            if ($locked === null || $locked->status !== $expected || $locked->billing_status !== 'reserved') {
                return false;
            }
            $this->tokens->release($locked->user_id, $locked->billing_reservation, 'Realtime session did not start');
            $locked->update(['status' => 'failed', 'billing_status' => 'released', 'terminal_reason' => $reason,
                'error_message' => $message, 'closed_at' => now(), 'answer_sdp' => null, 'configure_message' => null]);

            return true;
        });
    }

    /** End a connected lease; after the reviewed deadline the terminal state is always expiry. */
    private function end(RealtimeMediaSession $session, string $status, string $reason, ?string $message = null): RealtimeMediaSession
    {
        return DB::transaction(function () use ($session, $status, $reason, $message): RealtimeMediaSession {
            $locked = RealtimeMediaSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== 'connected') {
                return $locked;
            }
            $expired = $locked->expires_at !== null && ! $locked->expires_at->isFuture();
            $locked->update([
                'status' => $expired ? 'expired' : $status, 'terminal_reason' => $expired ? 'session_limit' : $reason,
                'error_message' => $expired ? null : $message, 'closed_at' => $expired ? $locked->expires_at : now(),
                'answer_sdp' => null, 'configure_message' => null,
            ]);

            return $locked;
        });
    }

    private function expireIfDue(RealtimeMediaSession $session): RealtimeMediaSession
    {
        return $session->status === 'connected' && $session->expires_at !== null && ! $session->expires_at->isFuture()
            ? $this->end($session, 'expired', 'session_limit') : $session;
    }

    private function strike(RealtimeMediaSession $session): array
    {
        $failures = DB::transaction(function () use ($session): int {
            $locked = RealtimeMediaSession::query()->lockForUpdate()->findOrFail($session->id);
            $locked->update(['heartbeat_failures' => $locked->heartbeat_failures + 1]);

            return $locked->heartbeat_failures;
        });
        if ($failures >= self::HEARTBEAT_MAX_STRIKES) {
            return ['alive' => false, 'session' => $this->end($session, 'failed', 'lease_lost',
                'The provider refused the session lease repeatedly, so the session ended.')];
        }

        return ['alive' => true, 'degraded' => true, 'session' => $session->refresh()];
    }

    private function result(RealtimeMediaSession $session, ?string $token = null): array
    {
        if ($token === null || ! $this->usable($session)) {
            return ['session' => $session];
        }

        return ['session' => $session, 'answer' => ['type' => 'answer', 'sdp' => $session->answer_sdp],
            'configure' => $session->configure_message, 'session_token' => $token, 'protocol' => $this->protocol($session->provider_bindings ?? [])];
    }

    private function usable(RealtimeMediaSession $session): bool
    {
        return $session->status === 'connected' && $session->expires_at !== null && $session->expires_at->isFuture()
            && is_string($session->answer_sdp) && is_array($session->configure_message);
    }

    /** Public protocol facts the browser needs to interpret events; derived from the reviewed source profile. */
    private function protocol(array $bindings): array
    {
        $profile = is_array($bindings['wma_profile'] ?? null) ? $bindings['wma_profile'] : [];
        $errors = is_array($profile['errors'] ?? null) ? $profile['errors'] : [];
        $codes = static fn (mixed $value): array => is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
        $event = static function (mixed $reference, string $fallback): string {
            $ref = is_array($reference) ? ($reference['$ref'] ?? null) : null;

            return is_string($ref) && preg_match('#/messages/server\.([a-z_]+)$#D', $ref, $match) === 1 ? $match[1] : $fallback;
        };

        return [
            'setup_type' => 'configure', 'update_type' => 'prompt', 'version_field' => 'prompt_version',
            'initial_version' => (int) ($profile['sequence']['initial'] ?? 1),
            'ready_event' => $event($profile['configuredSession']['ready'] ?? null, 'configured'),
            'ended_event' => $event($profile['ended'] ?? null, 'stream_exhausted'),
            'errors' => ['session_failure' => $codes($errors['sessionFailure'] ?? null),
                'input_failure' => $codes($errors['inputFailure'] ?? null), 'diagnostic' => $codes($errors['diagnostic'] ?? null)],
            'max_message_bytes' => self::MAX_CONTROL_BYTES, 'heartbeat_interval_ms' => 5000,
        ];
    }

    /** @return array{session_id: string, sdp: string}|null */
    private function answer(array $data): ?array
    {
        $sessionId = $data['session_id'] ?? null;
        $sdp = $data['sdp'] ?? null;
        if (! is_string($sessionId) || preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $sessionId) !== 1 || ($data['type'] ?? null) !== 'answer'
            || ! is_string($sdp) || strlen($sdp) > self::MAX_SDP_BYTES || ! str_starts_with(ltrim($sdp), 'v=0')) {
            return null;
        }

        return ['session_id' => $sessionId, 'sdp' => $sdp];
    }

    private function offer(mixed $sdp): string
    {
        if (! is_string($sdp)) {
            throw ValidationException::withMessages(['sdp' => 'Create a complete WebRTC offer before starting.']);
        }
        // Request middleware trims strings; restore canonical CRLF lines including the final terminator.
        $sdp = str_replace(["\r\n", "\n"], ["\n", "\r\n"], trim($sdp))."\r\n";
        if (strlen($sdp) > self::MAX_SDP_BYTES || ! str_starts_with($sdp, "v=0\r\n")
            || preg_match('/^m=application\s/m', $sdp) !== 1 || preg_match('/^a=candidate:/m', $sdp) !== 1) {
            throw ValidationException::withMessages(['sdp' => 'Create a complete WebRTC offer with gathered network candidates before starting.']);
        }

        return $sdp;
    }

    private function setupInputs(MediaCapability $capability, array $bindings, array $raw): array
    {
        try {
            $inputs = MediaJsonSchema::normalize($capability->inputSchema, $raw);
        } catch (CapabilityValidationException $exception) {
            throw ValidationException::withMessages($this->prefixed($exception->errors()));
        }
        $errors = [];
        if (is_array($inputs['script'] ?? null) && $inputs['script'] !== []) {
            foreach (['end_image_url', 'audio_url'] as $field) {
                if (($inputs[$field] ?? null) !== null) {
                    $errors['inputs.'.$field] = 'A setup script cannot be combined with this field. Place it on a script beat instead.';
                }
            }
        }
        $errors += $this->scriptErrors($inputs['script'] ?? null, $bindings, 'inputs.script');
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $inputs;
    }

    private function updateInputs(array $bindings, array $schema, array $raw): array
    {
        try {
            $inputs = MediaJsonSchema::normalize($schema, $raw);
        } catch (CapabilityValidationException $exception) {
            throw ValidationException::withMessages($this->prefixed($exception->errors()));
        }
        $directives = array_values(array_filter(['prompt', 'end_image_url', 'audio_url'], static fn (string $field): bool => ($inputs[$field] ?? null) !== null));
        $script = $inputs['script'] ?? null;
        $errors = [];
        if ($script !== null) {
            foreach ($directives as $field) {
                $errors['inputs.'.$field] = 'A script update cannot be combined with this field. Send it separately or place it on a script beat.';
            }
        } elseif ($directives === []) {
            $errors['inputs'] = 'Add a prompt, image, audio or script before sending an update.';
        }
        $errors += $this->scriptErrors($script, $bindings, 'inputs.script');
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $inputs;
    }

    /** Documented script rules JSON Schema cannot express, using the source's published session limits. */
    private function scriptErrors(mixed $script, array $bindings, string $path): array
    {
        if (! is_array($script) || $script === []) {
            return [];
        }
        $limits = $bindings['event_schemas']['session_info']['properties'] ?? [];
        $constant = static fn (string $name): ?int => is_int($limits[$name]['const'] ?? null) && $limits[$name]['const'] > 0 ? $limits[$name]['const'] : null;
        $spacing = $constant('script_min_end_image_spacing_seconds') ?? 3;
        $ends = [];
        $audio = 0;
        foreach ($script as $index => $beat) {
            if (! is_array($beat)) {
                continue;
            }
            if (($beat['end_image_url'] ?? null) !== null) {
                $ends[] = ['index' => $index, 'offset' => (int) ($beat['offset'] ?? 0)];
            }
            $audio += ($beat['audio_url'] ?? null) !== null ? 1 : 0;
        }
        usort($ends, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset'] ?: $a['index'] <=> $b['index']);
        $errors = [];
        for ($index = 1; $index < count($ends); $index++) {
            if ($ends[$index]['offset'] - $ends[$index - 1]['offset'] < $spacing) {
                $errors[$path.'.'.$ends[$index]['index'].'.offset'] = "Successive end images must be at least {$spacing} seconds apart.";
            }
        }
        $maxEnds = $constant('script_max_end_images');
        if ($maxEnds !== null && count($ends) > $maxEnds) {
            $errors[$path] = "A script can contain at most {$maxEnds} end images.";
        }
        $maxAudio = $constant('script_max_audio_beats');
        if ($maxAudio !== null && $audio > $maxAudio) {
            $errors[$path] ??= "A script can contain at most {$maxAudio} audio beats.";
        }

        return $errors;
    }

    /**
     * Stage the owner's validated files as short-lived provider URLs, then prove the complete wire
     * message against the private source schema. Protocol constants are server-controlled.
     */
    private function controlMessage(array $wireSchema, array $publicSchema, array $constants, array $inputs, AiProviderProfile $provider, int $ownerId): array
    {
        $urls = [];
        $staged = MediaJsonSchema::replaceAssets($publicSchema, $inputs, function (array $asset) use (&$urls, $provider, $ownerId): string {
            return $urls[$asset['asset_id']] ??= $this->stager->stage($provider, $asset['asset_id'], $ownerId);
        });
        $message = [...$constants, ...$this->compact($publicSchema, $staged)];
        if ($wireSchema === [] || MediaJsonSchema::errors($wireSchema, $message) !== []) {
            throw new UnexpectedValueException('The control message does not match the provider contract.');
        }
        if ($this->encodedBytes($message) > self::MAX_CONTROL_BYTES) {
            throw new UnexpectedValueException('The control message exceeds the realtime data-channel limit.');
        }

        return $message;
    }

    /** Omit optional members whose explicit null equals the documented default. */
    private function compact(array $schema, mixed $value): mixed
    {
        if (! is_array($value) || $value === []) {
            return $value;
        }
        if (array_is_list($value)) {
            $items = $this->branch($schema, 'array')['items'] ?? [];

            return array_map(fn (mixed $item): mixed => $this->compact(is_array($items) ? $items : [], $item), $value);
        }
        $object = $this->branch($schema, 'object');
        foreach ($value as $name => $member) {
            $property = is_array($object['properties'][$name] ?? null) ? $object['properties'][$name] : [];
            if ($member === null && array_key_exists('default', $property) && $property['default'] === null
                && ! in_array($name, $object['required'] ?? [], true)) {
                unset($value[$name]);

                continue;
            }
            $value[$name] = $this->compact($property, $member);
        }

        return $value;
    }

    private function branch(array $schema, string $type): array
    {
        if (($schema['type'] ?? null) === $type || ($type === 'object' && isset($schema['properties']))) {
            return $schema;
        }
        foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $candidate) {
                if (is_array($candidate) && (($candidate['type'] ?? null) === $type || ($type === 'object' && isset($candidate['properties'])))) {
                    return $candidate;
                }
            }
        }

        return [];
    }

    private function references(array $schema, array $inputs): array
    {
        try {
            return MediaJsonSchema::assetReferences($schema, $inputs);
        } catch (CapabilityValidationException $exception) {
            throw ValidationException::withMessages($this->prefixed($exception->errors()));
        }
    }

    private function assertAssets(User $owner, array $references): void
    {
        if ($references === []) {
            return;
        }
        $assets = MediaAsset::query()->whereIn('id', array_values(array_unique(array_column($references, 'asset_id'))))->get()->keyBy('id');
        foreach ($references as $reference) {
            $asset = $assets->get($reference['asset_id']);
            $field = 'inputs.'.implode('.', $reference['path']);
            if ($asset === null || (int) $asset->user_id !== (int) $owner->id) {
                throw new AuthorizationException('This input asset is not available to your account.');
            }
            $this->assets->assertOwner($owner, $asset);
            if (($reference['kind'] ?? 'file') !== 'file' && $asset->media_type !== $reference['kind']) {
                throw ValidationException::withMessages([$field => 'An input file does not match the required media kind.']);
            }
            try {
                $this->assets->assertSourceConstraints($asset, $reference);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([$field => $exception->getMessage()]);
            }
        }
    }

    /** Refuse before any reservation when staged URLs could push the message past the channel limit. */
    private function assertControlSize(array $message, int $files): void
    {
        if ($this->encodedBytes($message) + $files * 2048 > self::MAX_CONTROL_BYTES) {
            throw ValidationException::withMessages(['inputs' => 'This message is larger than the 256 KiB realtime control-channel limit. Shorten the prompt or script.']);
        }
    }

    private function encodedBytes(array $message): int
    {
        return strlen(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array{urls: list<string>, username?: string, credential?: string}> */
    private function iceList(mixed $servers): array
    {
        if (! is_array($servers) || ! array_is_list($servers) || count($servers) > 8) {
            return [];
        }
        $result = [];
        foreach ($servers as $server) {
            $urls = is_array($server) ? ($server['urls'] ?? null) : null;
            $urls = is_string($urls) ? [$urls] : $urls;
            if (! is_array($urls) || ! array_is_list($urls) || $urls === [] || count($urls) > 8) {
                return [];
            }
            foreach ($urls as $url) {
                if (! is_string($url) || strlen($url) > 512 || preg_match('/^(?:stun|stuns|turn|turns):[^\s"\'<>\\\\]+$/iD', $url) !== 1) {
                    return [];
                }
            }
            $entry = ['urls' => $urls];
            foreach (['username', 'credential'] as $field) {
                if (array_key_exists($field, $server)) {
                    if (! is_string($server[$field]) || strlen($server[$field]) > 1024) {
                        return [];
                    }
                    $entry[$field] = $server[$field];
                }
            }
            $result[] = $entry;
        }

        return $result;
    }

    private function controlled(User $user, string $id, mixed $token): RealtimeMediaSession
    {
        $session = $this->owned($user, $id);
        if (! is_string($token) || $token === '' || strlen($token) > 128 || ! hash_equals($session->session_token_hash, hash('sha256', $token))) {
            throw new ImageGenerationException('This browser tab no longer controls the realtime session.', 403);
        }

        return $session;
    }

    private function provider(RealtimeMediaSession $session): AiProviderProfile
    {
        $provider = AiProviderProfile::query()->find($session->provider_id);
        if ($provider === null || ! $provider->is_enabled || $provider->protocol !== 'fal'
            || ! hash_equals($session->connection_fingerprint, self::providerFingerprint($provider))) {
            throw new AiProxyException('The saved provider connection changed. No alternate provider will be used.', 503);
        }

        return $provider;
    }

    private static function providerFingerprint(AiProviderProfile $provider): string
    {
        return hash('sha256', json_encode(array_intersect_key($provider->getRawOriginal(),
            array_flip(['base_url', 'api_key', 'protocol', 'api_version'])), JSON_THROW_ON_ERROR));
    }

    private function recordingLimit(): array
    {
        $policy = $this->assets->policy()['roles'][InputRole::ReferenceVideo->value]['max_bytes'] ?? 0;

        return ['max_bytes' => max(0, min((int) config('realtime_media.max_recording_bytes', 104_857_600), (int) $policy)),
            'max_files' => max(0, (int) config('realtime_media.max_recordings_per_session', 3))];
    }

    /** @param  iterable<RealtimeMediaSession>  $sessions @return array<string, MediaAsset> */
    private function recordingAssets(int $userId, iterable $sessions): array
    {
        $ids = [];
        foreach ($sessions as $session) {
            foreach ($session->recording_asset_ids ?? [] as $assetId) {
                if (MediaJsonSchema::isAssetId($assetId)) {
                    $ids[$assetId] = true;
                }
            }
        }

        return $ids === [] ? [] : MediaAsset::query()->where('user_id', $userId)->whereIn('id', array_keys($ids))->get()->keyBy('id')->all();
    }

    private function settings(array $inputs): array
    {
        $summary = [];
        foreach ($inputs as $name => $value) {
            if (! is_string($name)) {
                continue;
            }
            $summary[$name] = match (true) {
                is_string($value) => MediaJsonSchema::isAssetId($value) ? ['asset_id' => $value] : mb_substr($value, 0, 280),
                is_array($value) => ['items' => count($value)],
                default => $value,
            };
        }

        return $summary;
    }

    private function fingerprint(string $model, array $inputs, string $hash, int $price): string
    {
        return hash('sha256', json_encode($this->canonical([$model, MediaOperation::RealtimeVideo->value, $inputs, $hash, $price]), JSON_THROW_ON_ERROR));
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

    private function prefixed(array $errors): array
    {
        $result = [];
        foreach ($errors as $key => $message) {
            $result[$key === 'inputs' || $key === '' ? 'inputs' : 'inputs.'.$key] = $message;
        }

        return $result;
    }

    private function offset(mixed $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $decoded = is_string($cursor) ? base64_decode($cursor, true) : false;
        if (! is_string($decoded) || ! ctype_digit($decoded) || (int) $decoded > 1_000_000) {
            throw ValidationException::withMessages(['cursor' => 'This pagination cursor is invalid.']);
        }

        return (int) $decoded;
    }
}
