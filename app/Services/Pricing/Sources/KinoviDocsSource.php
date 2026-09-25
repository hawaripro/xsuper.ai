<?php

namespace App\Services\Pricing\Sources;

use App\Models\AiProviderProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

final class KinoviDocsSource
{
    public function collect(AiProviderProfile $provider, Collection $models): array
    {
        $result = [];
        foreach ($models as $model) {
            $id = $model->upstream_model_id ?: $model->model_id;
            $cost = CostData::unknown('kinovi_docs', 'No published credit price matching the submitted tier.', $id, 'credit');
            try {
                $response = Http::timeout(30)->get(rtrim(config('pricing.kinovi_docs_base'), '/').'/'.rawurlencode($id).'.md');
                $markdown = $response->successful() ? $response->body() : '';
            } catch (ConnectionException) {
                $markdown = '';
            }
            if (! preg_match('/^## Pricing[^\n]*\n(.*?)(?=^## |\z)/msi', $markdown, $section)) {
                $result[$model->id] = $cost;
                continue;
            }
            $tier = match ($model->category) { 'image' => '1k', 'avatar' => '480p', 'video' => '720p', default => 'default' };
            $unit = match ($model->category) { 'video', 'avatar' => 'second', 'audio' => 'request', default => 'generation' };
            $headers = [];
            $candidates = [];
            foreach (explode("\n", $section[1]) as $line) {
                if (! str_starts_with(trim($line), '|')) {
                    continue;
                }
                $cells = array_map(fn ($s) => trim(str_replace(['**', '`'], '', $s)), explode('|', trim(trim($line), '|')));
                if ($headers === []) {
                    $headers = $cells;
                    continue;
                }
                if (preg_match('/^[\s:|-]+$/', $line)) {
                    continue;
                }
                $label = strtolower($cells[0] ?? '');
                // Native Kinovi video never sends a reference video; do not select its different tariff.
                if (str_contains($label, 'reference video')) {
                    continue;
                }
                $column = array_search($tier, array_map('strtolower', $headers), true);
                $creditsColumn = array_search('credits', array_map('strtolower', $headers), true);
                if ($column !== false) {
                    $cell = $cells[$column] ?? '';
                    $rank = 0;
                } else {
                    $rank = 0;
                    if ($tier !== 'default' && ! str_contains($label, $tier)) {
                        // autoFix promotes a 1k image request to the lowest documented supported tier.
                        if ($model->category === 'image' && preg_match('/\b([248])k\b/', $label, $floor)) {
                            $rank = (int) $floor[1];
                        } elseif (! str_contains($label, 'default')) {
                            continue;
                        }
                    }
                    $cell = $creditsColumn !== false ? ($cells[$creditsColumn] ?? '') : implode(' ', array_slice($cells, 1));
                }
                $amount = null;
                if (preg_match('/([\d,.]+)\s*(?:cr\b|credits\b)/i', $cell, $match)
                    || ($creditsColumn !== false && preg_match('/^([\d,.]+)(?:\s*\/|\s*$)/', $cell, $match))) {
                    $amount = (float) str_replace(',', '', $match[1]);
                }
                if ($amount === null || $amount <= 0) {
                    continue;
                }
                if ($unit === 'second' && ! preg_match('#per second|/\s*s(?:ec(?:ond)?)?\b#i', $label.' '.$cell.' '.$section[1])) {
                    // A flat credit total is not a per-second price without a documented duration.
                    if (! preg_match('/\b(\d+)\s*(?:s|sec|seconds)\b/i', $label, $seconds) || (int) $seconds[1] < 1) {
                        continue;
                    }
                    $amount /= (int) $seconds[1];
                }
                $candidates[$rank][] = $amount;
            }
            if ($candidates !== []) {
                ksort($candidates);
                $cost = [...$cost, 'status' => 'ok', 'unit' => $unit, 'unit_cost' => number_format(max(reset($candidates)), 10, '.', ''),
                    'basis_note' => 'Published credits at '.$tier.' (image autoFix uses the lowest supported tier); per '.$unit.'.'];
            }
            $result[$model->id] = $cost;
        }
        return $result;
    }
}
