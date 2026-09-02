<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §10.
class AgencyMember extends Model
{
    public const ROLES = ['owner', 'manager', 'host'];

    protected $fillable = ['agency_id', 'user_id', 'role', 'joined_at', 'left_at', 'is_active'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
