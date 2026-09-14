<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentBlock extends Model
{
    protected $fillable = ['key', 'locale', 'draft', 'published', 'is_published', 'updated_by', 'published_at'];

    protected function casts(): array
    {
        return ['draft' => 'array', 'published' => 'array', 'is_published' => 'boolean', 'published_at' => 'datetime'];
    }

    public function editor() { return $this->belongsTo(User::class, 'updated_by'); }
}
