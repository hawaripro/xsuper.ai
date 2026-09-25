<?php

namespace App\Media;

use App\Media\Enums\MediaOperation;
use App\Media\Exceptions\CapabilityConfigException;
use App\Models\AiModelProfile;
use App\Models\MediaCapabilityRevision;
use App\Services\MediaModelConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * The single authority that turns (model, operation) into an effective MediaCapability.
 * A published revision wins; a broken one raises CapabilityConfigException (never a
 * silent legacy fallback). Otherwise the capability is derived from existing config.
 * ensureRevision() materializes a stable revision row a job can reference.
 */
final class CapabilityResolver
{
    /** Published operations extend, rather than depend on, the legacy inventory. */
    public function operations(AiModelProfile $model): array
    {
        $operations = array_fill_keys(array_keys(MediaModelConfig::deriveCapabilities($model)), true);
        $revisions = MediaCapabilityRevision::where('ai_model_profile_id', $model->id)
            ->select(['operation', 'status', 'published_at'])->get()->groupBy('operation');
        foreach ($revisions as $operation => $rows) {
            if ($rows->contains('status', 'published') && MediaOperation::tryFrom($operation) !== null) {
                $operations[$operation] = true;
            } elseif ($rows->contains(fn ($row) => $row->published_at !== null)) {
                unset($operations[$operation]);
            }
        }

        return array_keys($operations);
    }

    /**
     * Legacy fixed-form entrypoints receive only version-1 contracts. Schema contracts are
     * executed exclusively by callers that opt in; a published schema contract never falls
     * back to a native builder.
     */
    public function resolve(AiModelProfile $model, MediaOperation $operation, bool $schemaContracts = false): ResolvedCapability
    {
        $published = MediaCapabilityRevision::query()
            ->where('ai_model_profile_id', $model->id)
            ->where('operation', $operation->value)
            ->where('status', 'published')
            ->orderByDesc('revision')
            ->first();

        if ($published !== null) {
            try {
                $capability = MediaCapability::fromArray([...($published->definition ?? []), 'provider_bindings' => $published->provider_bindings ?? []]);
                if ($capability->modelPublicId !== $model->model_id || $capability->operation !== $operation
                    || $capability->contractVersion !== (int) $published->contract_version || ! in_array($capability->contractVersion, [1, 2], true)) {
                    throw new \UnexpectedValueException('Capability identity or contract version does not match.');
                }
                $this->assertBinding($model, $published, $capability);
            } catch (Throwable $e) {
                throw new CapabilityConfigException(
                    "Published capability for {$model->model_id}/{$operation->value} is invalid.", 0, $e
                );
            }
            if ($capability->contractVersion !== 1 && ! $schemaContracts) {
                throw new CapabilityConfigException("Operation {$operation->value} for {$model->model_id} is available only in the media workspace.");
            }

            return new ResolvedCapability(
                $capability, 'published', $published->id,
                $this->hash(['definition' => $published->definition, 'provider_bindings' => $published->provider_bindings]),
            );
        }

        if (MediaCapabilityRevision::where('ai_model_profile_id', $model->id)->where('operation', $operation->value)->whereNotNull('published_at')->exists()) {
            throw new CapabilityConfigException("Operation {$operation->value} is disabled for {$model->model_id}.");
        }

        $derived = MediaModelConfig::deriveCapabilities($model)[$operation->value] ?? null;
        if ($derived === null) {
            throw new CapabilityConfigException(
                "Model {$model->model_id} does not support operation {$operation->value}."
            );
        }

        return new ResolvedCapability($derived, 'legacy', null, $this->hash($derived->toArray()));
    }

    /** Execution must match the reviewed provider binding and the model's current routing identity. */
    private function assertBinding(AiModelProfile $model, MediaCapabilityRevision $published, MediaCapability $capability): void
    {
        $bindings = $capability->providerBindings;
        $routing = $model->upstream_model_id ?: $model->model_id;
        if ($capability->contractVersion === 1) {
            if ($published->source_schema !== null && ($model->provider?->protocol !== 'fal'
                || ($bindings['adapter'] ?? null) !== 'fal_image_v1' || ($bindings['endpoint'] ?? null) !== $routing)) {
                throw new \UnexpectedValueException('Published provider binding does not match model routing.');
            }

            return;
        }
        $protocol = $model->provider?->protocol;
        $valid = ($bindings['endpoint'] ?? null) === $routing && $capability->inputSchema !== [] && match ($bindings['adapter'] ?? null) {
            'fal_schema_v2' => $protocol === 'fal' && is_array($bindings['request_schema'] ?? null) && is_array($bindings['output_schema'] ?? null)
                && (($bindings['transport'] ?? null) === 'direct'
                    || (($bindings['transport'] ?? null) === 'queue' && is_string($bindings['queue_root'] ?? null) && $bindings['queue_root'] !== '')),
            'fal_wma_v1' => $protocol === 'fal' && $capability->operation === MediaOperation::RealtimeVideo,
            // One asynchronous Runware task per job, addressed by the reviewed AIR and task type.
            'runware_v1' => $protocol === 'runware' && ($bindings['transport'] ?? null) === 'async'
                && is_string($bindings['task_type'] ?? null) && $bindings['task_type'] !== ''
                && is_array($bindings['request_schema'] ?? null) && is_array($bindings['output_schema'] ?? null),
            default => false,
        };
        if (! $valid) {
            throw new \UnexpectedValueException('Published schema binding is not executable for this model routing.');
        }
    }

    public function ensureRevision(AiModelProfile $model, MediaOperation $operation, ResolvedCapability $resolved): MediaCapabilityRevision
    {
        if ($resolved->revisionId !== null) {
            return MediaCapabilityRevision::query()->findOrFail($resolved->revisionId);
        }

        $existing = $this->findBySourceHash($model, $operation, $resolved->sourceHash);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return MediaCapabilityRevision::create([
                'ai_model_profile_id' => $model->id,
                'operation' => $operation->value,
                'contract_version' => $resolved->capability->contractVersion,
                'revision' => (int) MediaCapabilityRevision::query()
                    ->where('ai_model_profile_id', $model->id)
                    ->where('operation', $operation->value)
                    ->max('revision') + 1,
                'status' => 'tested',
                'definition' => $resolved->capability->toArray(),
                'source_hash' => $resolved->sourceHash,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent writer created the same derived revision; reuse it.
            return $this->findBySourceHash($model, $operation, $resolved->sourceHash)
                ?? throw new CapabilityConfigException('Could not materialize a capability revision.');
        }
    }

    private function findBySourceHash(AiModelProfile $model, MediaOperation $operation, string $hash): ?MediaCapabilityRevision
    {
        return MediaCapabilityRevision::query()
            ->where('ai_model_profile_id', $model->id)
            ->where('operation', $operation->value)
            ->where('source_hash', $hash)
            ->first();
    }

    private function hash(array $definition): string
    {
        return hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR));
    }
}
