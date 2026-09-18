<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromptTemplate extends Model
{
    public const CATEGORY_MODES = [
        'Coding' => 'coding_assistant',
        'UMKM' => 'umkm_assistant',
        'Konten' => 'content_creator',
        'Marketplace' => 'marketplace_helper',
        'Excel' => 'excel_office_helper',
        'Desain' => 'prompt_visual_generator',
        'Prompt Gambar' => 'prompt_visual_generator',
        'Prompt Video' => 'prompt_visual_generator',
        'Bisnis' => 'project_builder',
        'Belajar' => null,
    ];

    protected $fillable = [
        'template_key',
        'category',
        'title',
        'prompt_text',
        'mode',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByMode($query, string $mode)
    {
        return $query->where('mode', $mode);
    }
}
