<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** docs/02 §12 — the profile-visitor list. One row per pair, bumped on each visit. */
class UserVisit extends Model
{
    protected $fillable = ['visitor_id', 'profile_id', 'visit_count', 'visited_at'];

    protected function casts(): array
    {
        return [
            'visited_at'  => 'datetime',
            'visit_count' => 'integer',
        ];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visitor_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(User::class, 'profile_id');
    }
}
