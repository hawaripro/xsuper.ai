<?php

namespace App\Services\Pricing\Sources;

use App\Models\AiProviderProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

final class ReferenceCatalogSource
{
    public function collect(AiProviderProfile $provider, Collection $models): array
    {
        if ($models->isEmpty()) {
            return [];
        }
        $openrouter = [];
        foreach ($this->body('openrouter')['data'] ?? [] as $row) {
            $architecture = $row['architecture'] ?? [];
            $outputs = $architecture['output_modalities'] ?? [];
            // Image/audio-only entries in the public reference catalog are not token-billed chat.
            $text = in_array('text', is_array($outputs) ? $outputs : [], true)
                || str_ends_with((string) ($architecture['modality'] ?? ''), '->text');
            if (! $text || ! is_string($row['id'] ?? null)) {
                continue;
            }
            $p = $row['pricing'] ?? [];
            $this->index($openrouter, $row['id'], ['input' => $p['prompt'] ?? null, 'output' => $p['completion'] ?? null,
                'cache_read' => $p['input_cache_read'] ?? null, 'cache_write' => $p['input_cache_write'] ?? null]);
        }
        $litellm = [];
        foreach ($this->body('litellm') as $id => $row) {
            if (! is_array($row) || ! in_array($row['mode'] ?? null, ['chat', 'responses', 'completion'], true)) {
                continue;
            }
            $this->index($litellm, $id, ['input' => $row['input_cost_per_token'] ?? null, 'output' => $row['output_cost_per_token'] ?? null,
                'cache_read' => $row['cache_read_input_token_cost'] ?? null, 'cache_write' => $row['cache_creation_input_token_cost'] ?? null]);
        }
        $result = [];
        foreach ($models as $model) {
            $match = null;
            foreach ([$openrouter, $litellm] as $index) {
                foreach (array_unique([...self::variants($model->upstream_model_id ?: ''), ...self::variants($model->model_id)]) as $key) {
                    if (isset($index[$key])) {
                        $match = $index[$key];
                        break 2;
                    }
                }
            }
            $result[$model->id] = $match
                ? CostData::llm('reference', $match['id'], $match['prices'], 'estimate')
                : CostData::unknown('reference', 'No matching chat price in OpenRouter or LiteLLM. Enter a verified manual cost.');
        }

        return $result;
    }

    private function body(string $source): array
    {
        $url = (string) config('pricing.reference.'.$source.'_url');
        return Cache::remember('pricing-reference:'.hash('sha256', $url), now()->addHours((int) config('pricing.reference.cache_hours', 12)), function () use ($url): array {
            try {
                $response = Http::acceptJson()->timeout(30)->get($url);
                return $response->successful() && is_array($response->json()) ? $response->json() : [];
            } catch (ConnectionException) {
                return [];
            }
        });
    }

    private function index(array &$index, string $id, array $prices): void
    {
        foreach (self::variants($id) as $key) {
            $old = $index[$key] ?? null;
            if ($old === null || substr_count($id, '/') < substr_count($old['id'], '/')
                || (substr_count($id, '/') === substr_count($old['id'], '/') && (float) ($prices['input'] ?? 0) > (float) ($old['prices']['input'] ?? 0))) {
                $index[$key] = ['id' => $id, 'prices' => $prices];
            }
        }
    }

    private static function variants(string $id): array
    {
        $id = strtolower(trim($id));
        $parts = explode('/', $id);
        $variants = [];
        foreach ([$id, end($parts)] as $value) {
            $value = str_replace(['.', '_'], '-', preg_replace('/:(free|beta)$/', '', $value));
            $value = preg_replace('/-(?:\d{8}|\d{4}-\d{2}-\d{2}|\d{4})$/', '', $value);
            $variants[] = $value;
            $variants[] = preg_replace('#^(?:anthropic|google|openai|deepseek|qwen|alibaba|xai|x-ai|meta|mistral|moonshot|minimax|zai|z-ai)[/-]#', '', $value);
        }

        return array_values(array_unique(array_filter($variants)));
    }
}
