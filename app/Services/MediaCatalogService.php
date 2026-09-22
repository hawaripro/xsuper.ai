<?php

namespace App\Services;

use App\Media\CapabilityUi;
use App\Media\FalCapabilityImporter;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class MediaCatalogService
{
    public const STATUSES = ['found', 'imported', 'needs_handling', 'tested', 'published', 'disabled'];

    public function __construct(
        private readonly AiProviderTransport $transport,
        private readonly FalCapabilityImporter $importer,
        private readonly AuditService $audit,
    ) {}

    public function discover(AiProviderProfile $provider, User $actor, ?string $cursor, int $limit): array
    {
        $connection = Arr::only($provider->getRawOriginal(), ['base_url', 'api_key', 'protocol', 'api_version']);
        $page = $this->transport->discoverFalPage($provider, $cursor, $limit);
        $imported = DB::transaction(function () use ($provider, $actor, $connection, $page): int {
            $provider = AiProviderProfile::query()->lockForUpdate()->findOrFail($provider->id);
            if (Arr::only($provider->getRawOriginal(), array_keys($connection)) !== $connection) {
                throw new HttpResponseException(response()->json(['message' => 'The provider connection changed. Discover again.'], 409));
            }
            $created = 0;
            foreach ($page['models'] as $entry) {
                $endpoint = $entry['endpoint_id'];
                $model = AiModelProfile::query()->where('provider_id', $provider->id)->where('upstream_identity', $endpoint)->lockForUpdate()->first();
                if ($model === null) {
                    $model = $this->newModel($provider, $entry);
                }
                // Never replace curated identity, category, label, activation, or price on discovery.
                $model->update(['is_available' => ($entry['metadata']['status'] ?? null) === 'active', 'last_seen_at' => now()]);
                $source = is_array($entry['openapi'] ?? null) ? $entry['openapi'] : [];
                $hash = FalCapabilityImporter::hash($source);
                $normalized = $this->importer->normalize([...$entry, 'model_public_id' => $model->model_id]);
                $operation = $normalized['operation'] ?? 'unsupported';
                $exists = MediaCapabilityRevision::query()->where('ai_model_profile_id', $model->id)->where('operation', $operation)->where('source_hash', $hash)->whereNotNull('source_schema')->exists();
                if ($exists) {
                    continue;
                }
                $revision = MediaCapabilityRevision::create([
                    'ai_model_profile_id' => $model->id, 'operation' => $operation,
                    'revision' => 1 + (int) MediaCapabilityRevision::where('ai_model_profile_id', $model->id)->where('operation', $operation)->max('revision'),
                    'contract_version' => 1, 'status' => $normalized['publishable'] ? 'imported' : 'needs_handling',
                    'definition' => $normalized['capability']?->toArray() ?? [],
                    'source_schema' => $source, 'source_hash' => $hash,
                    'source_schema_ref' => 'fal:'.$endpoint,
                    'source_metadata' => ['endpoint_id' => $endpoint, 'metadata' => Arr::only($entry['metadata'] ?? [], ['category', 'display_name', 'description', 'status', 'updated_at'])],
                    'provider_bindings' => $normalized['provider_bindings'],
                    'compatibility_report' => $normalized['report'],
                    'ui_metadata' => $normalized['capability'] ? CapabilityUi::describe($normalized['capability']) : null,
                    'created_by' => $actor->id,
                ]);
                $this->auditRevision($actor, 'imported', $revision);
                $created++;
            }
            $provider->update(['catalog_discovered_at' => now()]);
            $this->audit->record($actor, 'ai_catalog.discovered', $provider, ['discovered' => count($page['models']), 'imported' => $created, 'has_more' => $page['next_cursor'] !== null]);

            return $created;
        });
        Cache::forget('public-model-catalog-v3');

        return ['next_cursor' => $page['next_cursor'], 'discovered' => count($page['models']), 'imported' => $imported, 'counts' => $this->counts($provider->id)];
    }

    public function transition(MediaCapabilityRevision $revision, User $actor, string $action, bool $reviewed = false, ?array $ui = null): MediaCapabilityRevision
    {
        if (! in_array($action, ['review', 'publish', 'disable', 'rollback'], true)) {
            $this->blocked(['Unknown lifecycle action.']);
        }
        $result = DB::transaction(function () use ($revision, $actor, $action, $reviewed, $ui): MediaCapabilityRevision {
            $model = AiModelProfile::query()->with('provider')->lockForUpdate()->findOrFail($revision->ai_model_profile_id);
            $revision = MediaCapabilityRevision::query()->lockForUpdate()->findOrFail($revision->id);
            if ($action === 'disable') {
                $revision->update(['status' => 'disabled', 'disabled_at' => now()]);
                $this->auditRevision($actor, 'disabled', $revision);

                return $revision;
            }
            if ($action === 'rollback' && $revision->published_at === null) {
                $this->blocked(['Only a previously published revision can be selected for rollback.']);
            }
            if ($action !== 'rollback' && $revision->status === 'disabled') {
                $this->blocked(['Disabled revisions cannot be published; restore a previously published revision with rollback.']);
            }
            if ($action === 'publish' && $revision->published_at !== null && $revision->status !== 'published') {
                $this->blocked(['Select rollback to restore a previously published revision.']);
            }
            $report = $this->compatibility($model, $revision);
            if ($report['blockers'] !== []) {
                $this->blocked($report['blockers']);
            }
            if ($reviewed) {
                if ($revision->published_at !== null && $ui !== null) {
                    $this->blocked(['Published revision metadata is immutable. Review a new schema candidate instead.']);
                }
                if ($ui !== null) {
                    $revision->ui_metadata = $this->curateUi($revision, $ui);
                    $revision->curation_overrides = ['ui_metadata' => $ui];
                }
                $revision->reviewed_by = $actor->id;
                $revision->reviewed_at = now();
            }
            if ($revision->reviewed_at === null) {
                $this->blocked(['An administrator must explicitly review this candidate before publication.']);
            }
            $revision->compatibility_report = [...$report, 'tested_at' => now()->toISOString()];
            $revision->tested_at = now();
            if ($action === 'review') {
                if ($revision->status !== 'published') {
                    $revision->status = 'tested';
                }
                $revision->save();
                $this->auditRevision($actor, 'reviewed', $revision);

                return $revision;
            }
            $blockers = [];
            if ($model->category !== 'image' || $model->token_cost < 1) {
                $blockers[] = 'An image category and positive reviewed sale price are required before publication.';
            }
            if (! $model->is_available || ! $model->provider?->is_enabled) {
                $blockers[] = 'The provider must be enabled and the model available.';
            }
            if ($model->provider?->authenticated_at === null || $model->provider?->status !== 'healthy') {
                $blockers[] = 'Check this provider connection successfully before publication; public catalog discovery does not verify credentials.';
            }
            if ($blockers !== []) {
                $this->blocked($blockers);
            }
            $previous = MediaCapabilityRevision::query()->where('ai_model_profile_id', $model->id)->where('operation', $revision->operation)->where('status', 'published')->pluck('id')->all();
            MediaCapabilityRevision::query()->whereIn('id', $previous)->where('id', '!=', $revision->id)->update(['status' => 'tested']);
            $revision->status = 'published';
            $revision->published_at ??= now();
            $revision->disabled_at = null;
            $revision->save();
            $model->update(['is_enabled' => true]);
            $this->auditRevision($actor, $action === 'rollback' ? 'rolled_back' : 'published', $revision, ['previous_active_revision_ids' => $previous]);

            return $revision;
        });
        Cache::forget('public-model-catalog-v3');

        return $result;
    }

    public function counts(int $providerId): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        $counts['found'] = AiModelProfile::where('provider_id', $providerId)
            ->whereDoesntHave('capabilityRevisions', fn ($query) => $query->whereNotNull('source_schema'))->count();
        $rows = DB::table('media_capabilities as c')->join('ai_model_profiles as m', 'm.id', '=', 'c.ai_model_profile_id')
            ->where('m.provider_id', $providerId)->whereNotNull('c.source_schema')
            ->select('c.status')->selectRaw('COUNT(DISTINCT c.ai_model_profile_id) as aggregate')->groupBy('c.status')->get();
        foreach ($rows as $row) {
            if (array_key_exists($row->status, $counts)) {
                $counts[$row->status] = (int) $row->aggregate;
            }
        }

        return $counts;
    }

    public static function publicModelId(AiProviderProfile $provider, string $upstreamId, int $attempt = 0): string
    {
        if ($attempt === 0 && strlen($upstreamId) <= 120) {
            return $upstreamId;
        }
        $base = $provider->slug.'/'.$upstreamId;
        if ($attempt === 1 && strlen($base) <= 120) {
            return $base;
        }

        return substr($base, 0, 107).'-'.substr(hash('sha256', $provider->slug."\0".$upstreamId."\0".$attempt), 0, 12);
    }

    private function newModel(AiProviderProfile $provider, array $entry): AiModelProfile
    {
        $endpoint = $entry['endpoint_id'];
        $category = match ($entry['metadata']['category'] ?? '') {
            'text-to-image', 'image-to-image' => 'image', 'text-to-video', 'image-to-video' => 'video',
            'text-to-audio', 'text-to-speech' => 'audio', 'text-to-3d', 'image-to-3d' => 'model3d', default => 'other',
        };
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $id = self::publicModelId($provider, $endpoint, $attempt);
            try {
                return DB::transaction(fn () => AiModelProfile::create([
                    'provider_id' => $provider->id, 'model_id' => $id, 'upstream_model_id' => $endpoint,
                    'display_name' => mb_substr((string) ($entry['metadata']['display_name'] ?? $endpoint), 0, 160),
                    'provider_name' => $provider->name, 'category' => $category, 'is_enabled' => false,
                    'is_available' => false, 'capabilities' => [], 'token_cost' => null,
                    'input_modalities' => ['text'], 'output_modalities' => [$category],
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                if (! AiModelProfile::where('model_id', $id)->exists()) {
                    throw $exception;
                }
            }
        }
        throw new HttpResponseException(response()->json(['message' => 'The catalog identity changed. Discover again.'], 409));
    }

    private function compatibility(AiModelProfile $model, MediaCapabilityRevision $revision): array
    {
        if ($model->provider?->protocol !== 'fal' || ! is_array($revision->source_schema) || ! is_array($revision->source_metadata)) {
            $this->blocked(['Only schema-backed fal image candidates can use this publication workflow. Existing curated integrations remain unchanged.']);
        }
        $entry = [...$revision->source_metadata, 'openapi' => $revision->source_schema, 'model_public_id' => $model->model_id];
        $result = $this->importer->normalize($entry);
        $report = $result['report'];
        if (($model->upstream_model_id ?: $model->model_id) !== ($entry['endpoint_id'] ?? null)
            || $result['operation'] !== $revision->operation
            || FalCapabilityImporter::hash($revision->source_schema) !== $revision->source_hash
            || FalCapabilityImporter::hash($result['capability']?->toArray() ?? []) !== FalCapabilityImporter::hash($revision->definition ?? [])
            || FalCapabilityImporter::hash($result['provider_bindings']) !== FalCapabilityImporter::hash($revision->provider_bindings ?? [])) {
            $report['blockers'][] = 'The source, routing or normalized contract changed; discover and review a new candidate.';
        }
        $report['compatible'] = $report['blockers'] === [];

        return $report;
    }

    private function curateUi(MediaCapabilityRevision $revision, array $ui): array
    {
        $result = $revision->ui_metadata ?? [];
        foreach ($ui as $group => $entries) {
            if (! in_array($group, ['inputs', 'params', 'order'], true) || ! is_array($entries)) {
                $this->blocked(['UI metadata only supports input/parameter labels, help, placeholders and field order.']);
            }
            if ($group === 'order') {
                if (count($entries) !== count(array_unique($entries)) || array_diff($entries, $result['order'] ?? []) !== []
                    || array_diff($result['order'] ?? [], $entries) !== []) {
                    $this->blocked(['UI field order must contain each declared field exactly once.']);
                }
                $result['order'] = $entries;

                continue;
            }
            foreach ($entries as $key => $metadata) {
                if (! isset($result[$group][$key]) || ! is_array($metadata)) {
                    $this->blocked(['UI metadata references an undeclared field.']);
                }
                foreach ($metadata as $name => $value) {
                    if (! in_array($name, ['label', 'help', 'placeholder', 'group', 'unit', 'advanced'], true)
                        || ($name === 'advanced' ? ! is_bool($value) : (! is_string($value) || mb_strlen($value) > 500))) {
                        $this->blocked(['UI metadata cannot change field validation, bindings or controls.']);
                    }
                }
                $result[$group][$key] = array_replace($result[$group][$key], $metadata);
            }
        }

        return $result;
    }

    private function auditRevision(User $actor, string $action, MediaCapabilityRevision $revision, array $extra = []): void
    {
        $this->audit->record($actor, 'ai_capability.'.$action, $revision, [
            'provider_id' => $revision->model->provider_id, 'model_id' => $revision->ai_model_profile_id,
            'operation' => $revision->operation, 'revision' => $revision->revision,
            'status' => $revision->status, 'source_hash' => $revision->source_hash, ...$extra,
        ]);
    }

    private function blocked(array $blockers): never
    {
        throw new HttpResponseException(response()->json(['message' => 'This capability cannot be published.', 'blockers' => array_values($blockers)], 422));
    }
}
