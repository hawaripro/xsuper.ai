<?php

namespace App\Console\Commands;

use App\Models\AiProviderProfile;
use App\Models\User;
use App\Services\MediaCatalogService;
use App\Services\RunwareCatalogSource;
use Illuminate\Console\Command;
use Throwable;

class ImportRunwareCatalog extends Command
{
    protected $signature = 'media:import-runware-catalog
        {provider : Stored Runware provider ID}
        {--from= : Offline copy of the public catalog (index.json, content-models.json, creators.json, schemas/, examples/)}
        {--after=0 : Skip this many index entries}
        {--limit=50 : Maximum index entries in this batch, 1–500}
        {--actor= : Administrator user ID for audit attribution (default: the first administrator)}';

    protected $description = 'Import an offline copy of the public Runware catalog as unpublished v2 candidates; never prices, activates or publishes';

    public function handle(MediaCatalogService $catalog, RunwareCatalogSource $source): int
    {
        $providerId = filter_var($this->argument('provider'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        $directory = (string) $this->option('from');
        if ($providerId === false || $after === false || $limit === false || $directory === '' || ! is_file(rtrim($directory, '/\\').'/index.json')) {
            $this->error('Provide a positive provider ID, --from with the catalog directory (index.json, schemas/), --after >= 0 and --limit between 1 and 500.');

            return self::INVALID;
        }
        $provider = AiProviderProfile::find($providerId);
        $actor = $this->option('actor') !== null
            ? User::find(filter_var($this->option('actor'), FILTER_VALIDATE_INT) ?: 0)
            : User::query()->where('role', 'admin')->orderBy('id')->first();
        if ($provider?->protocol !== 'runware' || ! $actor?->isAdmin()) {
            $this->error('A stored Runware provider and an administrator actor are required.');

            return self::INVALID;
        }
        $cursor = $after;
        $totals = ['discovered' => 0, 'imported' => 0, 'skipped' => 0, 'revisions' => 0];
        try {
            $end = $after + $limit;
            do {
                // Small transactions: one index slice per import, resumable with --after.
                $page = $source->directoryPage($directory, $cursor, min(10, $end - $cursor));
                $result = $catalog->importRunware($provider, $actor, $page['models']);
                foreach ($result['models'] as $model) {
                    $this->line(json_encode(['type' => 'model', ...$model], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                }
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $result[$key];
                }
                $cursor += count($page['models']);
            } while ($page['next_after'] !== null && $cursor < $end && $page['models'] !== []);
            $this->line(json_encode(['type' => 'summary', 'provider_id' => $provider->id, ...$totals, 'total' => $page['total'],
                'next_after' => $cursor < $page['total'] ? $cursor : null, 'published' => 0, 'prices_changed' => 0, 'network_requests' => 0,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('The offline Runware import stopped. Resume with --after='.$cursor.'. Candidates already created are reused; nothing was priced or published. Failure type: '.class_basename($exception));

            return self::FAILURE;
        }
    }
}
