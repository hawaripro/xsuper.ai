<?php

namespace Database\Seeders;

use App\Models\PromptTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PromptTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Compile and validate every source before the first database mutation.
        $templates = PromptTemplateLibrary::templates();
        $keys = array_column($templates, 'template_key');

        DB::transaction(function () use ($templates, $keys) {
            $existing = collect();
            foreach (array_chunk($keys, 500) as $chunk) {
                foreach (PromptTemplate::whereIn('template_key', $chunk)->get() as $template) {
                    $existing->put($template->template_key, $template);
                }
            }
            $new = [];
            $now = now();

            foreach ($templates as $template) {
                $current = $existing->get($template['template_key']);
                if ($current) {
                    // A library refresh does not undo publication or manual ordering.
                    $current->fill([
                        'category' => $template['category'],
                        'title' => $template['title'],
                        'prompt_text' => $template['prompt_text'],
                        'mode' => $template['mode'],
                    ]);
                    if ($current->isDirty()) {
                        $current->save();
                    }
                } else {
                    $new[] = array_merge($template, ['created_at' => $now, 'updated_at' => $now]);
                }
            }

            // Fifty rows also stay below SQLite's conservative bind-parameter limit.
            foreach (array_chunk($new, 50) as $chunk) {
                PromptTemplate::insert($chunk);
            }
        });
    }
}
