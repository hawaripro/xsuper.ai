<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromptTemplate extends Model
{
    protected $fillable = [
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
