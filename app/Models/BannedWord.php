<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §11 — A.5a.
class BannedWord extends Model
{
    protected $fillable = [
        'word', 'language', 'severity', 'replacement',
        'scope', 'is_regex', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['scope' => 'array', 'is_regex' => 'boolean', 'is_active' => 'boolean'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
