<?php

namespace App\Console\Commands;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\User;
use App\Services\MediaCatalogService;
use Illuminate\Console\Command;
use Throwable;

class RenormalizeMediaCatalog extends Command
{
    protected $signature = 'media:renormalize-catalog
        {--provider= : Stored fal or Runware provider ID}
        {--actor= : Administrator user ID for candidate audit attribution}
        {--after=0 : Resume after this model row ID}
        {--limit=100 : Maximum models in this batch, 1–500}
        {--report= : Optional append-only NDJSON coverage report path}';

    protected $description = 'Normalize captured fal or Runware schemas offline into immutable v2 candidates; never discover, price, activate or publish';

    public function handle(MediaCatalogService $catalog): int
    {
        $providerId = filter_var($this->option('provider'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($providerId === false || $actorId === false || $after === false || $limit === false) {
            $this->error('Provide --provider and --actor positive IDs, --after >= 0 and --limit between 1 and 500.');

            return self::INVALID;
        }
        $provider = AiProviderProfile::find($providerId);
        $actor = User::find($actorId);
        if (! in_array($provider?->protocol, ['fal', 'runware'], true) || ! $actor?->isAdmin()) {
            $this->error('A stored fal or Runware provider and an administrator actor are required.');

            return self::INVALID;
        }
        $report = null;
        if ($this->option('report')) {
            $report = @fopen($this->option('report'), 'ab');
            if ($report === false) {
                $this->error('Cannot open the coverage report for append. No models were changed.');

                return self::FAILURE;
            }
        }
        $processed = 0;
        $created = 0;
        $blocked = 0;
        $cursor = $after;
        try {
            $ids = AiModelProfile::query()->where('provider_id', $provider->id)->where('id', '>', $after)->orderBy('id')->limit($limit)->pluck('id');
            foreach ($ids as $id) {
                $model = AiModelProfile::findOrFail($id);
                $coverage = $catalog->renormalize($model, $actor);
                $line = json_encode(['type' => 'model', ...$coverage], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (is_resource($report)) {
                    if (! flock($report, LOCK_EX)) {
                        throw new \RuntimeException('Report lock failed.');
                    }
                    try {
                        $bytes = $line.PHP_EOL;
                        if (fwrite($report, $bytes) !== strlen($bytes) || ! fflush($report)) {
                            throw new \RuntimeException('Report append failed.');
                        }
                    } finally {
                        flock($report, LOCK_UN);
                    }
                }
                $this->line($line);
                $processed++;
                $created += (int) $coverage['created'];
                $blocked += (int) (($coverage['blockers'] ?? []) !== []);
                $cursor = $id;
            }
            $hasMore = AiModelProfile::query()->where('provider_id', $provider->id)->where('id', '>', $cursor)->exists();
            $this->line(json_encode([
                'type' => 'summary', 'provider_id' => $provider->id, 'processed' => $processed, 'created' => $created,
                'blocked' => $blocked, 'last_model_id' => $cursor, 'next_after' => $hasMore ? $cursor : null,
                'published' => 0, 'prices_changed' => 0, 'network_requests' => 0,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Offline normalization stopped. Resume with --after='.$cursor.'. Previously created candidates are reusable; no publication or pricing occurred. Failure type: '.class_basename($exception));

            return self::FAILURE;
        } finally {
            if (is_resource($report)) {
                fclose($report);
            }
        }
    }
}
