<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $table = 'feedback';
    protected $fillable = ['user_id', 'rating', 'category', 'message', 'status', 'is_testimonial', 'admin_note'];
    protected function casts(): array { return ['rating' => 'integer', 'is_testimonial' => 'boolean']; }
    public function user() { return $this->belongsTo(User::class); }
}
