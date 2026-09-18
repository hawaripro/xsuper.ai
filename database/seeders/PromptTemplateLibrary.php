<?php

namespace Database\Seeders;

use App\Models\PromptTemplate;
use RuntimeException;

final class PromptTemplateLibrary
{
    /**
     * Twenty distinct workflows meet twenty-five domain briefs per category.
     * Explicit slugs are permanent identities; wording and ordering may evolve.
     */
    public static function templates(): array
    {
        $files = [
            'Coding' => 'coding',
            'UMKM' => 'umkm',
            'Konten' => 'konten',
            'Marketplace' => 'marketplace',
            'Excel' => 'excel',
            'Desain' => 'desain',
            'Prompt Gambar' => 'prompt-gambar',
            'Prompt Video' => 'prompt-video',
            'Bisnis' => 'bisnis',
            'Belajar' => 'belajar',
        ];
        $templates = [];
        $seen = ['template_key' => [], 'title' => [], 'prompt_text' => []];

        foreach ($files as $category => $file) {
            $path = __DIR__.'/data/templates/'.$file.'.json';
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (count($data['contexts']) !== 25 || count($data['recipes']) !== 20) {
                throw new RuntimeException("Template source {$file} must have 25 contexts and 20 workflows.");
            }

            $order = 0;
            foreach ($data['recipes'] as $recipe) {
                foreach ($data['contexts'] as $context) {
                    foreach ([$recipe['key'], $context['key']] as $key) {
                        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $key)) {
                            throw new RuntimeException("Invalid permanent template key in {$file}.");
                        }
                    }
                    $replacements = [];
                    foreach ($context as $name => $value) {
                        $replacements['{{'.$name.'}}'] = $value;
                    }
                    $prompt = implode("\n\n", [
                        'Tugas: '.strtr($recipe['task'], $replacements),
                        $data['role'],
                        'Konteks: '.$context['brief'],
                        "Bahan yang saya berikan:\n".$context['inputs'],
                        'Persyaratan khusus: '.$context['requirements'],
                        'Format hasil: '.strtr($recipe['output'], $replacements),
                        $data['guidance'],
                    ]);
                    if (str_contains($prompt, '{{')) {
                        throw new RuntimeException("Unresolved template variable in {$file}.");
                    }
                    $row = [
                        'template_key' => 'ultrai.library.'.$file.'.'.$context['key'].'.'.$recipe['key'],
                        'category' => $category,
                        'title' => $recipe['title'].' — '.$context['title'],
                        'prompt_text' => $prompt,
                        'mode' => PromptTemplate::CATEGORY_MODES[$category],
                        'is_active' => true,
                        'sort_order' => ++$order,
                    ];
                    foreach (array_keys($seen) as $field) {
                        $identity = mb_strtolower(trim($row[$field]));
                        if (isset($seen[$field][$identity])) {
                            throw new RuntimeException("Duplicate {$field} in template source {$file}.");
                        }
                        $seen[$field][$identity] = true;
                    }
                    $templates[] = $row;
                }
            }
        }

        return $templates;
    }
}
