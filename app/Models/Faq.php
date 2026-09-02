<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// docs/02 §13 — A.10a, GFT-104.
class Faq extends Model
{
    protected $fillable = [
        'category', 'question_en', 'question_hi', 'answer_en', 'answer_hi',
        'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** A Hindi answer that is missing is a gap the app has to fall back from. */
    public function isBilingual(): bool
    {
        return filled($this->question_hi) && filled($this->answer_hi);
    }
}
