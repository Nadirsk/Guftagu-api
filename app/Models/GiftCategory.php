<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// docs/02 §6 — A.6b.
class GiftCategory extends Model
{
    protected $fillable = ['key', 'name_en', 'name_hi', 'icon_url', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function gifts(): HasMany
    {
        return $this->hasMany(Gift::class, 'category_id');
    }
}
