<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Media\RunwareSchemaNormalizer;
use App\Models\AiProviderProfile;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Runware's public, credential-free model catalog: the docs index, per-model OpenAPI schema and
 * examples, and the content service's headlines, creators and price references. Reads either a
 * bounded page over HTTPS (pinned DNS, no redirects, size caps) or the same layout on disk.
 * Produces normalizer bundles; never touches the database and never sends an API key.
 */
class RunwareCatalogSource
{
    public const INDEX_URL = 'https://runware.ai/docs/models/index.json';

    public const CONTENT_URL = 'https://content.runware.ai/models';

    public const CREATORS_URL = 'https://content.runware.ai/creators';

    private const HOSTS = ['runware.ai', 'content.runware.ai', 'schemas.runware.ai'];

    private const MAX_BYTES = ['index' => 4_194_304, 'content' => 16_777_216, 'creators' => 16_777_216, 'schema' => 8_388_608, 'examples' => 8_388_608];

    private const CONTENT_FIELDS = ['model', 'air', 'name', 'creator', 'status', 'headline', 'capabilities', 'pricingOverview',
        'pricingRates', 'pricingMeasured', 'pricingExamples', 'pricingBasis'];

    public function __construct(private readonly AiProviderEndpoint $endpoint) {}

    /**
     * One bounded page of the public catalog. Only index entries the normalizer would import are
     * expanded with their schema and examples; a failed schema becomes that model's fetch error.
     *
     * @return array{models: list<array<string, mixed>>, next_cursor: ?string, total: int}
     */
    public function page(AiProviderProfile $provider, ?string $cursor, int $limit): array
    {
        if ($provider->protocol !== 'runware' || $limit < 1 || $limit > 10 || ($cursor !== null && preg_match('/^(?:0|[1-9][0-9]{0,5})$/D', $cursor) !== 1)) {
            throw new AiProxyException('Runware catalog discovery requires a Runware provider, 1–10 models and a numeric page cursor.', 422);
        }
        $rateKey = 'runware-discovery:'.$provider->id;
        if (RateLimiter::tooManyAttempts($rateKey, 20)) {
            throw new AiProxyException('Catalog discovery is rate limited. Try again shortly.', 429);
        }
        RateLimiter::hit($rateKey, 60);
        try {
            $index = self::entries($this->fetch(self::INDEX_URL, 'index'));
        } catch (Throwable) {
            throw new AiProxyException('The public Runware catalog is unavailable. Try again shortly.', 502);
        }
        $offset = (int) ($cursor ?? 0);
        $content = $this->cached('runware-catalog:content', fn (): array => self::contentMap($this->fetch(self::CONTENT_URL, 'content')));
        $creators = $this->cached('runware-catalog:creators', fn (): array => self::creatorMap($this->fetch(self::CREATORS_URL, 'creators')));
        $normalizer = new RunwareSchemaNormalizer;
        $models = [];
        foreach (array_slice($index, $offset, $limit) as $entry) {
            $bundle = self::bundle($entry, $content, $creators);
            if ($normalizer->describe($bundle)['skip'] === null) {
                try {
                    $bundle['openapi'] = $this->fetch($bundle['schema_url'], 'schema');
                } catch (Throwable $exception) {
                    $bundle['fetch_error'] = 'The public Runware schema could not be fetched: '.self::reason($exception);
                }
                $examples = is_string($bundle['openapi']['info']['x-examples'] ?? null) ? $bundle['openapi']['info']['x-examples']
                    : 'https://runware.ai/docs/models/'.$bundle['model_id'].'/examples.json';
                if (isset($bundle['openapi']) && self::allowedUrl($examples)) {
                    try {
                        $bundle['examples'] = $this->fetch($examples, 'examples');
                    } catch (Throwable) {
                        // Examples are optional form presets; a model imports without them.
                    }
                }
            }
            $models[] = $bundle;
        }
        $next = $offset + $limit < count($index) ? (string) ($offset + $limit) : null;

        return ['models' => $models, 'next_cursor' => $next, 'total' => count($index)];
    }

    /**
     * The same catalog read from an offline copy: index.json, content-models.json, creators.json,
     * schemas/<id>.json and examples/<id>.json.
     *
     * @return array{models: list<array<string, mixed>>, next_after: ?int, total: int}
     */
    public function directoryPage(string $directory, int $offset, int $limit): array
    {
        $directory = rtrim($directory, '/\\');
        $index = self::entries($this->file($directory.'/index.json', 'index'));
        $content = is_file($directory.'/content-models.json') ? self::contentMap($this->file($directory.'/content-models.json', 'content')) : [];
        $creators = is_file($directory.'/creators.json') ? self::creatorMap($this->file($directory.'/creators.json', 'creators')) : [];
        $normalizer = new RunwareSchemaNormalizer;
        $models = [];
        foreach (array_slice($index, $offset, $limit) as $entry) {
            $bundle = self::bundle($entry, $content, $creators);
            if ($normalizer->describe($bundle)['skip'] === null) {
                $schema = $directory.'/schemas/'.$bundle['model_id'].'.json';
                try {
                    $bundle['openapi'] = $this->file($schema, 'schema');
                } catch (Throwable $exception) {
                    $bundle['fetch_error'] = 'The offline Runware schema could not be read: '.self::reason($exception);
                }
                $examples = $directory.'/examples/'.$bundle['model_id'].'.json';
                if (isset($bundle['openapi']) && is_file($examples)) {
                    try {
                        $bundle['examples'] = $this->file($examples, 'examples');
                    } catch (Throwable) {
                        // Optional form presets.
                    }
                }
            }
            $models[] = $bundle;
        }

        return ['models' => $models, 'next_after' => $offset + $limit < count($index) ? $offset + $limit : null, 'total' => count($index)];
    }

    /** @return list<array<string, mixed>> validated index entries in published order */
    private static function entries(mixed $index): array
    {
        if (! is_array($index) || ! array_is_list($index)) {
            throw new RuntimeException('The Runware model index is not a list.');
        }
        $entries = [];
        foreach ($index as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $entry['id']) === 1) {
                $entries[] = array_intersect_key($entry, array_flip(['id', 'air', 'name', 'status', 'capabilities', 'schema', 'creator', 'architecture']));
            }
        }

        return $entries;
    }

    private static function bundle(array $entry, array $content, array $creators): array
    {
        $id = $entry['id'];
        $schema = 'https://runware.ai/docs/models/'.$id.'/schema.json';
        $item = $content[$id] ?? null;

        return ['model_id' => $id, 'schema_url' => $schema, 'index' => $entry, 'content' => $item,
            'creator' => $creators[$item['creator'] ?? ''] ?? null, 'openapi' => null, 'examples' => null];
    }

    /** @return array<string, array<string, mixed>> content entries by docs id, reduced to import facts */
    private static function contentMap(mixed $content): array
    {
        $items = is_array($content) && ! array_is_list($content) ? ($content['items'] ?? []) : $content;
        $map = [];
        foreach (is_array($items) ? $items : [] as $entry) {
            if (is_array($entry) && is_string($entry['model'] ?? null)) {
                $map[$entry['model']] = array_intersect_key($entry, array_flip(self::CONTENT_FIELDS));
            }
        }

        return $map;
    }

    /** @return array<string, array{id: string, name: ?string, logo: ?string}> */
    private static function creatorMap(mixed $creators): array
    {
        $items = is_array($creators) && ! array_is_list($creators) ? ($creators['items'] ?? []) : $creators;
        $map = [];
        foreach (is_array($items) ? $items : [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $map[$entry['id']] = ['id' => $entry['id'], 'name' => is_string($entry['name'] ?? null) ? $entry['name'] : null,
                    'logo' => is_string($entry['logo'] ?? null) ? $entry['logo'] : null];
            }
        }

        return $map;
    }

    /** Headline, logo and price-reference lookups are optional; a failure leaves them empty. */
    private function cached(string $key, callable $load): array
    {
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $value = $load();
        } catch (Throwable) {
            return [];
        }
        Cache::put($key, $value, now()->addMinutes(10));

        return $value;
    }

    private function fetch(string $url, string $kind): mixed
    {
        if (! self::allowedUrl($url)) {
            throw new RuntimeException('The catalog URL is not a public Runware address.');
        }
        $maximum = self::MAX_BYTES[$kind];
        // The cap holds while streaming (chunked bodies too), not only after the whole document is buffered.
        $buffer = Utils::streamFor(fopen('php://temp', 'w+b'));
        $written = 0;
        $bounded = FnStream::decorate($buffer, ['write' => static function (string $chunk) use ($buffer, &$written, $maximum): int {
            if ($written + strlen($chunk) > $maximum) {
                throw new RuntimeException('The catalog document exceeds its size limit.');
            }
            $written += strlen($chunk);

            return $buffer->write($chunk);
        }]);
        try {
            $options = $this->endpoint->requestOptions('https://'.parse_url($url, PHP_URL_HOST));
            $handler = new CurlHandler;
            $response = Http::accept('application/json')->timeout(20)->connectTimeout(5)
                ->setHandler(static fn ($request, array $options) => $handler($request, [...$options, 'sink' => $bounded]))
                ->withOptions([...$options, 'allow_redirects' => false, 'cookies' => false,
                    'on_headers' => static function (ResponseInterface $response) use ($maximum): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $maximum) {
                            throw new RuntimeException('The catalog document exceeds its size limit.');
                        }
                    }])
                ->get($url);
            if (! $response->successful()) {
                throw new RuntimeException('HTTP '.$response->status().'.');
            }
            if ($written === 0) {
                // A stubbed response never reaches the handler's sink: apply the same bound to its body.
                $bounded->write($response->body());
            }
            $buffer->rewind();

            return json_decode($buffer->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $bounded->close();
        }
    }

    private function file(string $path, string $kind): mixed
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false) {
            throw new RuntimeException('The catalog file is missing.');
        }
        if ($size > self::MAX_BYTES[$kind]) {
            throw new RuntimeException('The catalog file exceeds its size limit.');
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function allowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && preg_match('#^/[A-Za-z0-9._~/-]*$#D', $parts['path'] ?? '') === 1 && ! str_contains($parts['path'], '..');
    }

    private static function reason(Throwable $exception): string
    {
        return $exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'HTTP ')
            ? $exception->getMessage() : 'the document is missing, oversized or not valid JSON.';
    }
}
