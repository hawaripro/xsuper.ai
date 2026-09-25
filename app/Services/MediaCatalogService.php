<?php

namespace App\Services;

use App\Media\CapabilityUi;
use App\Media\FalCapabilityImporter;
use App\Media\FalSupplementalContracts;
use App\Media\RunwareSchemaNormalizer;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\MediaCapabilityRevision;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MediaCatalogService
{
    public const STATUSES = ['found', 'imported', 'needs_handling', 'tested', 'published', 'disabled'];

    public function __construct(
        private readonly AiProviderTransport $transport,
        private readonly FalCapabilityImporter $importer,
        private readonly AuditService $audit,
        private readonly RunwareCatalogSource $runwareSource,
        private readonly RunwareSchemaNormalizer $runwareNormalizer,
    ) {}

    public function discover(AiProviderProfile $provider, User $actor, ?string $cursor, int $limit): array
    {
        $connection = Arr::only($provider->getRawOriginal(), ['base_url', 'api_key', 'protocol', 'api_version']);
        if ($provider->protocol === 'runware') {
            // The public catalog needs no credentials; a changed connection still invalidates the page.
            $page = $this->runwareSource->page($provider, $cursor, $limit);
            $result = $this->importRunware($provider, $actor, $page['models'], $connection, 'discovery');

            return ['next_cursor' => $page['next_cursor'], 'discovered' => $result['discovered'], 'imported' => $result['imported'],
                'skipped' => $result['skipped'], 'total' => $page['total'], 'counts' => $this->counts($provider->id)];
        }
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
                [$revision, $wasCreated] = $this->candidate($model, $entry, $actor);
                $created += (int) $wasCreated;
            }
            $provider->update(['catalog_discovered_at' => now()]);
            $this->audit->record($actor, 'ai_catalog.discovered', $provider, ['discovered' => count($page['models']), 'imported' => $created, 'has_more' => $page['next_cursor'] !== null]);

            return $created;
        });
        Cache::forget('public-model-catalog-v3');

        return ['next_cursor' => $page['next_cursor'], 'discovered' => count($page['models']), 'imported' => $imported, 'counts' => $this->counts($provider->id)];
    }

    /**
     * Import normalized Runware bundles (a discovery page or an offline copy) as unpublished v2
     * candidates. New models start disabled, unavailable and unpriced; curated identity,
     * category, label, activation and price are never replaced on re-import.
     *
     * @param  list<array<string, mixed>>  $bundles
     * @return array{discovered: int, imported: int, skipped: int, revisions: int, models: list<array<string, mixed>>}
     */
    public function importRunware(AiProviderProfile $provider, User $actor, array $bundles, ?array $connection = null, string $source = 'offline'): array
    {
        abort_unless($actor->isAdmin(), 403);
        $result = DB::transaction(function () use ($provider, $actor, $bundles, $connection, $source): array {
            $provider = AiProviderProfile::query()->lockForUpdate()->findOrFail($provider->id);
            if ($provider->protocol !== 'runware') {
                throw new HttpResponseException(response()->json(['message' => 'Runware catalog import requires a Runware provider.'], 422));
            }
            if ($connection !== null && Arr::only($provider->getRawOriginal(), array_keys($connection)) !== $connection) {
                throw new HttpResponseException(response()->json(['message' => 'The provider connection changed. Discover again.'], 409));
            }
            $summary = ['discovered' => count($bundles), 'imported' => 0, 'skipped' => 0, 'revisions' => 0, 'models' => []];
            foreach ($bundles as $bundle) {
                $facts = $this->runwareNormalizer->describe($bundle);
                $model = $this->runwareModel($provider, $facts);
                if ($facts['skip'] !== null) {
                    // A local copy of a model Runware retired or has not released must not stay usable.
                    if ($model !== null && $facts['skip'] !== 'text-to-text') {
                        $model->update(['is_available' => false, 'last_seen_at' => now()]);
                    }
                    $summary['skipped']++;
                    $summary['models'][] = ['model_id' => $facts['model_id'], 'skipped' => $facts['skip']];

                    continue;
                }
                // A transient schema fetch failure must not shadow the captured source: the catalog price unit and
                // offline renormalization read the newest source-backed revision.
                if (! is_array($bundle['openapi'] ?? null) && $model !== null && $model->capabilityRevisions()
                    ->whereNotNull('source_schema')->where('source_hash', '!=', FalCapabilityImporter::hash([]))->exists()) {
                    $model->update(['last_seen_at' => now()]);
                    $summary['skipped']++;
                    $summary['models'][] = ['model_id' => $facts['model_id'], 'public_model_id' => $model->model_id, 'skipped' => 'schema_unavailable'];

                    continue;
                }
                $normalized = $this->runwareNormalizer->normalize($bundle, $model?->model_id ?? 'runware/'.$facts['model_id']);
                if ($model === null) {
                    $model = $this->newRunwareModel($provider, $normalized);
                    if ($model->model_id !== 'runware/'.$facts['model_id']) {
                        $normalized = $this->runwareNormalizer->normalize($bundle, $model->model_id);
                    }
                }
                $model->update([
                    'is_available' => $normalized['available'], 'last_seen_at' => now(),
                    // Descriptive fields are filled once; an administrator's text is never replaced.
                    ...(blank($model->description_en) && $normalized['description'] !== null ? ['description_en' => $normalized['description']] : []),
                    ...(blank($model->logo_url) && $normalized['logo_url'] !== null ? ['logo_url' => $normalized['logo_url']] : []),
                ]);
                $created = [];
                foreach ($normalized['operations'] as $operation) {
                    [$revision, $wasCreated] = $this->runwareCandidate($model, $bundle, $normalized, $operation, $actor);
                    if ($wasCreated) {
                        $created[] = $revision->id;
                    }
                }
                $summary['imported'] += (int) ($created !== []);
                $summary['revisions'] += count($created);
                $summary['models'][] = ['model_id' => $facts['model_id'], 'public_model_id' => $model->model_id,
                    'operations' => array_keys($normalized['operations']), 'created_revision_ids' => $created];
            }
            $provider->update(['catalog_discovered_at' => now()]);
            $this->audit->record($actor, 'ai_catalog.discovered', $provider, ['source' => $source, 'discovered' => $summary['discovered'],
                'imported' => $summary['imported'], 'skipped' => $summary['skipped'], 'revisions' => $summary['revisions']]);

            return $summary;
        });
        Cache::forget('public-model-catalog-v3');

        return $result;
    }

    /** Offline only: no catalog lookup, provider authentication, price change or publication. */
    public function renormalize(AiModelProfile $model, User $actor): array
    {
        abort_unless($actor->isAdmin(), 403);

        return DB::transaction(function () use ($model, $actor): array {
            $model = AiModelProfile::query()->with('provider')->lockForUpdate()->findOrFail($model->id);
            $source = $model->capabilityRevisions()->whereNotNull('source_schema')->orderByDesc('id')->first();
            $base = ['model_id' => $model->id, 'model_public_id' => $model->model_id, 'category' => $model->category, 'created' => false, 'contract_version' => 2];
            if ($model->provider?->protocol === 'runware') {
                return $this->renormalizeRunware($model, $actor, $source, $base);
            }
            if ($model->provider?->protocol !== 'fal') {
                return [...$base, 'blockers' => [['code' => 'unsupported_provider', 'message' => 'Offline source normalization requires a fal or Runware provider.']]];
            }
            if ($source !== null && (! is_array($source->source_metadata) || empty($source->source_metadata['endpoint_id']))) {
                return [...$base, 'source_revision_id' => $source->id, 'blockers' => [['code' => 'missing_source_identity', 'message' => 'The captured source has no endpoint identity; do not infer provider routing.']]];
            }
            if ($source !== null && (($model->upstream_model_id ?: $model->model_id) !== $source->source_metadata['endpoint_id']
                || FalCapabilityImporter::hash($source->source_schema ?? []) !== $source->source_hash)) {
                return [...$base, 'source_revision_id' => $source->id, 'blockers' => [['code' => 'source_identity_changed', 'message' => 'Captured schema hash or current endpoint identity differs. Rediscover before normalization.']]];
            }
            $entry = $source !== null
                ? [...$source->source_metadata, 'openapi' => $source->source_schema]
                : ['endpoint_id' => $model->upstream_model_id ?: $model->model_id, 'metadata' => []];
            $entry = FalSupplementalContracts::enrich($entry);
            if (! is_array($entry['openapi'] ?? null) || $entry['openapi'] === []) {
                return [...$base, 'blockers' => [['code' => 'missing_source_schema', 'message' => 'No nonempty captured source schema or exact documented supplement exists. Discover this endpoint before normalization.']]];
            }
            [$revision, $created] = $this->candidate($model, $entry, $actor);

            return [...$base, 'created' => $created, 'source_revision_id' => $source?->id, 'revision_id' => $revision->id,
                'source_hash' => $revision->source_hash, 'operation' => $revision->operation, 'status' => $revision->status,
                'compatible' => ($revision->compatibility_report['compatible'] ?? false) === true,
                'source_recovered' => isset($revision->source_metadata['source_evidence']),
                'blockers' => $revision->compatibility_report['blockers'] ?? [], 'warnings' => $revision->compatibility_report['warnings'] ?? [],
            ];
        });
    }

    /** Explicit, atomic admission for selected, never-published models without a positive tariff. */
    public function bulk(AiProviderProfile $provider, User $actor, array $items, string $action): array
    {
        abort_unless($actor->isAdmin(), 403);
        if (! in_array($action, ['review', 'publish'], true) || count($items) < 1 || count($items) > 50) {
            throw ValidationException::withMessages(['items' => 'Select 1–50 models and an explicit review or publish action.']);
        }
        if (count(array_unique(array_column($items, 'model_id'))) !== count($items)
            || count(array_unique(array_column($items, 'revision_id'))) !== count($items)) {
            throw ValidationException::withMessages(['items' => 'Each selected model and revision must be distinct.']);
        }
        $result = DB::transaction(function () use ($provider, $actor, $items, $action): array {
            $provider = AiProviderProfile::query()->lockForUpdate()->findOrFail($provider->id);
            $models = AiModelProfile::query()->where('provider_id', $provider->id)->whereKey(array_column($items, 'model_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $revisions = MediaCapabilityRevision::query()->whereKey(array_column($items, 'revision_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $errors = [];
            foreach ($items as $index => $item) {
                $model = $models->get($item['model_id']);
                $revision = $revisions->get($item['revision_id']);
                if ($model === null || $revision === null || $revision->ai_model_profile_id !== $model->id) {
                    $errors["items.$index.revision_id"] = 'The selected model or revision changed. Refresh and select it again.';
                    continue;
                }
                $model->setRelation('provider', $provider);
                if (! is_int($item['token_cost'] ?? null) || $item['token_cost'] < 1 || $item['token_cost'] > 2_147_483_647) {
                    $errors["items.$index.token_cost"] = 'Enter a positive integer sale price per whole request.';
                }
                // Each model is priced in its own catalog unit: per request (fal), per result or per second (Runware).
                if (($item['price_unit'] ?? null) !== MediaModelConfig::catalogPriceUnit($model)) {
                    $errors["items.$index.price_unit"] = 'Use this model\'s catalog price unit.';
                }
                if ($model->token_cost > 0) {
                    $errors["items.$index.token_cost"] = 'An existing positive sale price is protected. Review this candidate individually without changing its tariff.';
                }
                if ($action === 'publish' && (! $model->is_available || ! $provider->is_enabled || $provider->status !== 'healthy' || $provider->authenticated_at === null)) {
                    $errors["items.$index.model_id"] = 'Publication requires an available model and an enabled, successfully authenticated provider. Discovery is not account verification.';
                }
                if ($revision->contract_version !== 2 || $revision->status === 'disabled' || ! is_array($revision->source_schema)
                    || $model->capabilityRevisions()->whereNotNull('published_at')->exists()) {
                    $errors["items.$index.revision_id"] = 'Bulk activation accepts only new v2 candidates with no previously published model revision.';

                    continue;
                }
                if ($revision->operation === 'realtime_video'
                    && (int) ($item['max_session_seconds'] ?? 0) !== $revision->executionMetadata()['max_session_seconds']) {
                    $errors["items.$index.revision_id"] = 'Explicitly confirm the current maximum session seconds. A request price purchases one bounded session, not unlimited streaming.';

                    continue;
                }
                $report = $this->compatibility($model, $revision);
                if ($report['blockers'] !== []) {
                    $errors["items.$index.revision_id"] = array_map(static fn ($blocker) => is_array($blocker) ? ($blocker['message'] ?? $blocker['code'] ?? 'Unsupported source contract.') : $blocker, $report['blockers']);
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $results = [];
            foreach ($items as $item) {
                $model = $models->get($item['model_id']);
                $revision = $revisions->get($item['revision_id']);
                $before = $model->token_cost;
                $model->update(['token_cost' => $item['token_cost']]);
                $revision->update(['curation_overrides' => [...($revision->curation_overrides ?? []), 'pricing' => [
                    'token_cost' => $item['token_cost'], 'unit' => $item['price_unit'], 'variable_configuration' => true,
                    'reviewed_by' => $actor->id, 'reviewed_at' => now()->toISOString(),
                    ...($revision->operation === 'realtime_video' ? ['max_session_seconds' => $revision->executionMetadata()['max_session_seconds']] : []),
                ]]]);
                $revision = $this->transition($revision, $actor, $action, true);
                $this->auditRevision($actor, 'price_reviewed', $revision, [
                    'previous_token_cost' => $before, 'token_cost' => $item['token_cost'], 'price_unit' => $item['price_unit'], 'bulk' => true,
                ]);
                $results[] = ['model_id' => $model->id, 'revision_id' => $revision->id, 'status' => $revision->status, 'token_cost' => $model->token_cost, 'price_unit' => $item['price_unit']];
            }
            $this->audit->record($actor, $action === 'publish' ? 'ai_catalog.bulk_published' : 'ai_catalog.bulk_reviewed', $provider, [
                'items' => $results, 'count' => count($results), 'existing_positive_prices_preserved' => true,
            ]);

            return ['items' => $results, 'count' => count($results)];
        });
        Cache::forget('public-model-catalog-v3');

        return $result;
    }

    /** The model row must already be locked; immutable source and definition determine reuse. */
    private function candidate(AiModelProfile $model, array $entry, User $actor): array
    {
        $entry = FalSupplementalContracts::enrich($entry);
        $source = is_array($entry['openapi'] ?? null) ? $entry['openapi'] : [];
        $hash = FalCapabilityImporter::hash($source);
        $normalized = $this->importer->normalize([...$entry, 'model_public_id' => $model->model_id], 2);
        $operation = $normalized['operation'] ?? 'unsupported';
        $definition = $normalized['capability']?->toArray() ?? [];
        $definitionHash = FalCapabilityImporter::hash($definition);
        $bindingHash = FalCapabilityImporter::hash($normalized['provider_bindings']);
        $candidates = $model->capabilityRevisions()->where('operation', $operation)->where('contract_version', 2)->where('source_hash', $hash)->whereNotNull('source_schema')->get();
        foreach ($candidates as $candidate) {
            if (FalCapabilityImporter::hash($candidate->definition ?? []) === $definitionHash
                && FalCapabilityImporter::hash($candidate->provider_bindings ?? []) === $bindingHash) {
                return [$candidate, false];
            }
        }
        $endpoint = (string) ($entry['endpoint_id'] ?? '');
        $revision = MediaCapabilityRevision::create([
            'ai_model_profile_id' => $model->id, 'operation' => $operation,
            'revision' => 1 + (int) $model->capabilityRevisions()->where('operation', $operation)->max('revision'),
            'contract_version' => 2, 'status' => $normalized['publishable'] ? 'imported' : 'needs_handling',
            'definition' => $definition, 'source_schema' => $source, 'source_hash' => $hash,
            'source_schema_ref' => 'fal:'.$endpoint,
            'source_metadata' => ['endpoint_id' => $endpoint, 'metadata' => Arr::only($entry['metadata'] ?? [], ['category', 'display_name', 'description', 'status', 'updated_at']),
                ...(isset($entry['source_evidence']) ? ['source_evidence' => $entry['source_evidence']] : [])],
            'provider_bindings' => $normalized['provider_bindings'], 'compatibility_report' => $normalized['report'],
            'ui_metadata' => $normalized['capability'] ? CapabilityUi::describe($normalized['capability']) : null,
            'created_by' => $actor->id,
        ]);
        $this->auditRevision($actor, 'imported', $revision);

        return [$revision, true];
    }

    public function transition(MediaCapabilityRevision $revision, User $actor, string $action, bool $reviewed = false, ?array $ui = null, ?array $pricing = null): MediaCapabilityRevision
    {
        if (! in_array($action, ['review', 'publish', 'disable', 'rollback'], true)) {
            $this->blocked(['Unknown lifecycle action.']);
        }
        $result = DB::transaction(function () use ($revision, $actor, $action, $reviewed, $ui, $pricing): MediaCapabilityRevision {
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
                    $revision->curation_overrides = [...($revision->curation_overrides ?? []), 'ui_metadata' => $ui];
                }
                $revision->reviewed_by = $actor->id;
                $revision->reviewed_at = now();
            }
            if ($pricing !== null) {
                // Pricing is curation, not contract: a published revision can be re-reviewed after tariff or
                // session-cap drift without touching its definition, bindings or the model tariff.
                if (! $reviewed || $revision->contract_version !== 2
                    || ($pricing['unit'] ?? null) !== MediaModelConfig::catalogPriceUnit($model) || ($pricing['variable_configuration'] ?? false) !== true
                    || (int) ($pricing['token_cost'] ?? 0) !== $model->token_cost || $model->token_cost < 1) {
                    $this->blocked(['Acknowledge the current positive sale price and its existing billing unit on a v2 revision. Variable configuration costs must be reviewed.']);
                }
                if ($revision->operation === 'realtime_video' && $pricing['unit'] === 'second') {
                    $this->blocked(['A realtime session is priced once per bounded session; per-second metering is not implemented.']);
                }
                if ($revision->operation === 'realtime_video'
                    && (int) ($pricing['max_session_seconds'] ?? 0) !== $revision->executionMetadata()['max_session_seconds']) {
                    $this->blocked(['Review the current session duration limit before pricing this realtime candidate.']);
                }
                $revision->curation_overrides = [...($revision->curation_overrides ?? []), 'pricing' => [
                    'token_cost' => $model->token_cost, 'unit' => $pricing['unit'], 'variable_configuration' => true,
                    'reviewed_by' => $actor->id, 'reviewed_at' => now()->toISOString(),
                    ...($revision->operation === 'realtime_video' ? ['max_session_seconds' => $revision->executionMetadata()['max_session_seconds']] : []),
                ]];
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
                $this->auditRevision($actor, 'reviewed', $revision, ['pricing' => $revision->curation_overrides['pricing'] ?? null]);

                return $revision;
            }
            $blockers = [];
            if ($model->token_cost < 1) {
                $blockers[] = 'A positive reviewed sale price is required before publication.';
            }
            if ($revision->contract_version === 1 && $model->category !== 'image') {
                $blockers[] = 'A v1 image contract requires its original image category.';
            }
            if ($revision->contract_version === 2 && ! $revision->hasReviewedPrice($model->token_cost)) {
                $blockers[] = 'Explicitly review this candidate sale price and billing unit, including variable configuration costs, before publication.';
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
        $counts['compatible'] = AiModelProfile::where('provider_id', $providerId)->whereHas('capabilityRevisions',
            fn ($query) => $query->whereNotNull('source_schema')->where('compatibility_report->compatible', true))->count();
        $counts['priced'] = AiModelProfile::where('provider_id', $providerId)->where('token_cost', '>', 0)->whereHas('capabilityRevisions',
            fn ($query) => $query->whereNotNull('source_schema'))->count();

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
        $category = MediaModelConfig::catalogCategory((string) ($entry['metadata']['category'] ?? ''));
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

    /** The stored model for a Runware entry: by AIR, or by its public ID when the schema has no fixed AIR. */
    private function runwareModel(AiProviderProfile $provider, array $facts): ?AiModelProfile
    {
        $query = AiModelProfile::query()->where('provider_id', $provider->id);
        $facts['air'] !== null
            ? $query->where('upstream_identity', $facts['air'])
            : $query->whereNull('upstream_model_id')->where('model_id', 'runware/'.$facts['model_id']);

        return $query->lockForUpdate()->first();
    }

    private function newRunwareModel(AiProviderProfile $provider, array $normalized): AiModelProfile
    {
        $output = match ($normalized['category']) {
            'image', 'video', 'audio', 'model3d' => [$normalized['category']],
            default => ['data'],
        };
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $id = self::publicModelId($provider, 'runware/'.$normalized['model_id'], $attempt);
            try {
                return DB::transaction(fn () => AiModelProfile::create([
                    'provider_id' => $provider->id, 'model_id' => $id, 'upstream_model_id' => $normalized['air'],
                    'display_name' => $normalized['name'], 'provider_name' => $provider->name, 'category' => $normalized['category'],
                    'description_en' => $normalized['description'], 'logo_url' => $normalized['logo_url'],
                    'is_enabled' => false, 'is_available' => false, 'capabilities' => [], 'token_cost' => null,
                    'input_modalities' => ['text'], 'output_modalities' => $output,
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                if (! AiModelProfile::where('model_id', $id)->exists()) {
                    throw $exception;
                }
            }
        }
        throw new HttpResponseException(response()->json(['message' => 'The catalog identity changed. Discover again.'], 409));
    }

    /** The model row must already be locked; one immutable candidate per (source, operation, contract). */
    private function runwareCandidate(AiModelProfile $model, array $bundle, array $normalized, array $operation, User $actor): array
    {
        $source = is_array($bundle['openapi'] ?? null) ? $bundle['openapi'] : [];
        $hash = FalCapabilityImporter::hash($source);
        $definition = $operation['capability']?->toArray() ?? [];
        $definitionHash = FalCapabilityImporter::hash($definition);
        $bindingHash = FalCapabilityImporter::hash($operation['provider_bindings']);
        $candidates = $model->capabilityRevisions()->where('operation', $operation['operation'])->where('contract_version', 2)
            ->where('source_hash', $hash)->whereNotNull('source_schema')->get();
        foreach ($candidates as $candidate) {
            if (FalCapabilityImporter::hash($candidate->definition ?? []) === $definitionHash
                && FalCapabilityImporter::hash($candidate->provider_bindings ?? []) === $bindingHash) {
                return [$candidate, false];
            }
        }
        $revision = MediaCapabilityRevision::create([
            'ai_model_profile_id' => $model->id, 'operation' => $operation['operation'],
            'revision' => 1 + (int) $model->capabilityRevisions()->where('operation', $operation['operation'])->max('revision'),
            'contract_version' => 2, 'status' => $operation['publishable'] ? 'imported' : 'needs_handling',
            'definition' => $definition, 'source_schema' => $source, 'source_hash' => $hash,
            'source_schema_ref' => 'runware:'.$normalized['model_id'],
            // Admin-only evidence: the provider price reference and examples never reach members through here.
            'source_metadata' => array_filter([
                'provider' => 'runware', 'model_id' => $normalized['model_id'], 'schema_url' => $normalized['schema_url'],
                'air' => $normalized['air'], 'task_type' => $normalized['task_type'], 'name' => $normalized['name'],
                'status' => $bundle['index']['status'] ?? null, 'index' => $bundle['index'] ?? null, 'content' => $bundle['content'] ?? null,
                'creator' => $bundle['creator'] ?? null, 'pricing' => $normalized['pricing'], 'examples' => $operation['examples'],
                'fetch_error' => $bundle['fetch_error'] ?? null, 'normalizer_version' => RunwareSchemaNormalizer::VERSION,
            ], static fn ($value) => $value !== null),
            'provider_bindings' => $operation['provider_bindings'], 'compatibility_report' => $operation['report'],
            'ui_metadata' => $operation['capability'] ? CapabilityUi::describe($operation['capability']) : null,
            'created_by' => $actor->id,
        ]);
        $this->auditRevision($actor, 'imported', $revision);

        return [$revision, true];
    }

    /** Offline: re-derive every operation from the newest captured Runware source. */
    private function renormalizeRunware(AiModelProfile $model, User $actor, ?MediaCapabilityRevision $source, array $base): array
    {
        if ($source === null || ! is_array($source->source_metadata) || ($source->source_metadata['provider'] ?? null) !== 'runware') {
            return [...$base, 'blockers' => [['code' => 'missing_source_schema', 'message' => 'No captured Runware source exists. Import this model from the catalog before normalization.']]];
        }
        if (FalCapabilityImporter::hash($source->source_schema ?? []) !== $source->source_hash
            || ($model->upstream_model_id ?? null) !== ($source->source_metadata['air'] ?? null)) {
            return [...$base, 'source_revision_id' => $source->id, 'blockers' => [['code' => 'source_identity_changed', 'message' => 'Captured schema hash or current model identity differs. Import the catalog again before normalization.']]];
        }
        $bundle = self::runwareBundle($source);
        $normalized = $this->runwareNormalizer->normalize($bundle, $model->model_id);
        $created = [];
        $blockers = [];
        $warnings = [];
        foreach ($normalized['operations'] as $operation) {
            [$revision, $wasCreated] = $this->runwareCandidate($model, $bundle, $normalized, $operation, $actor);
            if ($wasCreated) {
                $created[] = $revision->id;
            }
            array_push($blockers, ...($revision->compatibility_report['blockers'] ?? []));
            array_push($warnings, ...($revision->compatibility_report['warnings'] ?? []));
        }

        return [...$base, 'created' => $created !== [], 'source_revision_id' => $source->id, 'revision_ids' => $created,
            'operations' => array_keys($normalized['operations']), 'source_hash' => $source->source_hash,
            'blockers' => array_values(array_unique($blockers)), 'warnings' => array_values(array_unique($warnings))];
    }

    /** A normalizer bundle rebuilt only from what a revision captured. */
    private static function runwareBundle(MediaCapabilityRevision $revision): array
    {
        $metadata = $revision->source_metadata ?? [];

        return ['model_id' => (string) ($metadata['model_id'] ?? ''), 'schema_url' => $metadata['schema_url'] ?? null,
            'index' => $metadata['index'] ?? null, 'content' => $metadata['content'] ?? null, 'creator' => $metadata['creator'] ?? null,
            'openapi' => is_array($revision->source_schema) && $revision->source_schema !== [] ? $revision->source_schema : null,
            'fetch_error' => $metadata['fetch_error'] ?? null];
    }

    private function compatibility(AiModelProfile $model, MediaCapabilityRevision $revision): array
    {
        if (! in_array($model->provider?->protocol, ['fal', 'runware'], true) || ! is_array($revision->source_schema) || ! is_array($revision->source_metadata)) {
            $this->blocked(['Only schema-backed fal or Runware candidates can use this publication workflow. Existing curated integrations remain unchanged.']);
        }
        if ($model->provider->protocol === 'runware') {
            $result = $this->runwareNormalizer->normalize(self::runwareBundle($revision), $model->model_id)['operations'][$revision->operation] ?? null;
            $report = $result['report'] ?? ['blockers' => [], 'warnings' => []];
            $endpoint = $result['provider_bindings']['endpoint'] ?? null;
            if ($result === null || ($model->upstream_model_id ?: $model->model_id) !== $endpoint
                || FalCapabilityImporter::hash($revision->source_schema) !== $revision->source_hash
                || FalCapabilityImporter::hash($result['capability']?->toArray() ?? []) !== FalCapabilityImporter::hash($revision->definition ?? [])
                || FalCapabilityImporter::hash($result['provider_bindings']) !== FalCapabilityImporter::hash($revision->provider_bindings ?? [])) {
                $report['blockers'][] = 'The source, routing or normalized contract changed; discover and review a new candidate.';
            }
        } else {
            $entry = [...$revision->source_metadata, 'openapi' => $revision->source_schema, 'model_public_id' => $model->model_id];
            $result = $this->importer->normalize($entry, $revision->contract_version);
            $report = $result['report'];
            if (($model->upstream_model_id ?: $model->model_id) !== ($entry['endpoint_id'] ?? null)
                || $result['operation'] !== $revision->operation
                || FalCapabilityImporter::hash($revision->source_schema) !== $revision->source_hash
                || FalCapabilityImporter::hash($result['capability']?->toArray() ?? []) !== FalCapabilityImporter::hash($revision->definition ?? [])
                || FalCapabilityImporter::hash($result['provider_bindings']) !== FalCapabilityImporter::hash($revision->provider_bindings ?? [])) {
                $report['blockers'][] = 'The source, routing or normalized contract changed; discover and review a new candidate.';
            }
        }
        if (($result['capability'] ?? null) !== null && ! self::implementedAdapter($revision->contract_version, $revision->operation, $revision->provider_bindings ?? [])) {
            $report['blockers'][] = 'No implemented executor runs this contract. Publication supports historical fal_image_v1, fal_schema_v2 over queue or direct transport, fal_wma_v1 realtime sessions bounded to 1–60 seconds, and asynchronous runware_v1 tasks.';
        }
        $report['compatible'] = $report['blockers'] === [];

        return $report;
    }

    /** Mirrors the executors that exist; realtime sessions additionally need a finite, reviewable bound. */
    private static function implementedAdapter(int $version, string $operation, array $bindings): bool
    {
        $transport = $bindings['transport'] ?? null;
        $seconds = $bindings['max_session_seconds'] ?? null;

        return match ([$version, $bindings['adapter'] ?? null]) {
            [1, 'fal_image_v1'] => true,
            [2, 'fal_schema_v2'] => $transport === 'direct'
                || ($transport === 'queue' && is_string($bindings['queue_root'] ?? null) && $bindings['queue_root'] !== ''),
            [2, 'fal_wma_v1'] => $transport === 'realtime' && $operation === 'realtime_video'
                && is_int($seconds) && $seconds >= 1 && $seconds <= 60,
            // RunwareAdapter submits one async task and restores JSON objects from the declared request schema.
            [2, 'runware_v1'] => $transport === 'async' && is_string($bindings['endpoint'] ?? null) && $bindings['endpoint'] !== ''
                && is_string($bindings['task_type'] ?? null) && $bindings['task_type'] !== ''
                && isset($bindings['request_schema']['properties']['deliveryMethod']),
            default => false,
        };
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
            'contract_version' => $revision->contract_version,
            'status' => $revision->status, 'source_hash' => $revision->source_hash, ...$extra,
        ]);
    }

    private function blocked(array $blockers): never
    {
        throw new HttpResponseException(response()->json(['message' => 'This capability cannot be published.', 'blockers' => array_values($blockers)], 422));
    }
}
