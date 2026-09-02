<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// docs/02 §7.
class Badge extends Model
{
    protected $fillable = [
        'key', 'name_en', 'name_hi', 'icon_url', 'description',
        'criteria', 'is_auto_awarded', 'is_active',
    ];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'is_auto_awarded' => 'boolean', 'is_active' => 'boolean'];
    }
}
