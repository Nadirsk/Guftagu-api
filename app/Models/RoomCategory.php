<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// docs/02 §3.2 — A.4d.
class RoomCategory extends Model
{
    protected $fillable = ['key', 'name_en', 'name_hi', 'icon_url', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'category_id');
    }
}
