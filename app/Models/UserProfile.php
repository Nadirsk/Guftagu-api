<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §2.2
class UserProfile extends Model
{
    protected $fillable = [
        'user_id', 'display_name', 'avatar_url', 'cover_url', 'bio', 'gender',
        'date_of_birth', 'country', 'city', 'language', 'theme', 'privacy',
        'notification_prefs', 'is_profile_complete',
    ];

    protected function casts(): array
    {
        return [
            'privacy'             => 'array',
            'notification_prefs'  => 'array',
            'is_profile_complete' => 'boolean',
            'date_of_birth'       => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
