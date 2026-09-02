<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// docs/02 §13 — read through App\Domain\Settings\SettingsRepository, not directly.
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'is_public', 'description', 'updated_by'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }
}
