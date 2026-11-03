<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * The uploaded photo, or — when there is none — the default avatar for their gender,
     * so every API payload carries something to draw. The raw column stays null; read it
     * with `getRawOriginal('avatar_url')` when "did they upload one" matters.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (?string $value) => $value ?: static::defaultAvatarUrl(
            $this->attributes['gender'] ?? null,
            $this->attributes['user_id'] ?? null,
        ));
    }

    /** Male / female get their own; anyone else alternates by id so a list is not all one face. */
    public static function defaultAvatarUrl(?string $gender, int|string|null $userId = null): string
    {
        $which = match ($gender) {
            'male'   => 'male',
            'female' => 'female',
            default  => ((int) $userId % 2) === 0 ? 'female' : 'male',
        };

        return url("avatars/default-{$which}.png");
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
