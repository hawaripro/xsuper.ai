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
    public function resolve(AiModelProfile $model, MediaOperation $operation): ResolvedCapability
    {
        $published = MediaCapabilityRevision::query()
            ->where('ai_model_profile_id', $model->id)
            ->where('operation', $operation->value)
            ->where('status', 'published')
            ->orderByDesc('revision')
            ->first();

        if ($published !== null) {
            try {
                $capability = MediaCapability::fromArray($published->definition ?? []);
            } catch (Throwable $e) {
                throw new CapabilityConfigException(
                    "Published capability for {$model->model_id}/{$operation->value} is invalid.", 0, $e
                );
            }

            return new ResolvedCapability(
                $capability, 'published', $published->id,
                (string) ($published->source_hash ?: $this->hash($published->definition ?? [])),
            );
        }

        $derived = MediaModelConfig::deriveCapabilities($model)[$operation->value] ?? null;
        if ($derived === null) {
            throw new CapabilityConfigException(
                "Model {$model->model_id} does not support operation {$operation->value}."
            );
        }

        return new ResolvedCapability($derived, 'legacy', null, $this->hash($derived->toArray()));
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
