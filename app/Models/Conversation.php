<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** docs/02 §12 — a DM thread or a group (D.4). */
class Conversation extends Model
{
    use HasUuids;

    public const DIRECT = 'direct';
    public const GROUP = 'group';

    public const TYPES = [self::DIRECT, self::GROUP];

    protected $fillable = [
        'uuid', 'type', 'title', 'avatar_url', 'created_by', 'last_message_at', 'is_active',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getIncrementing(): bool
    {
        return true;
    }

    public function getKeyType(): string
    {
        return 'int';
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'is_active'       => 'boolean',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** Only those still in the thread — someone who left keeps their row but stops here. */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participantFor(int $userId): ?ConversationParticipant
    {
        return $this->participants()->where('user_id', $userId)->first();
    }

    public function hasParticipant(int $userId): bool
    {
        return $this->participants()->where('user_id', $userId)->whereNull('left_at')->exists();
    }

    /** @return array<int, int> user ids still in the thread */
    public function participantIds(): array
    {
        return $this->activeParticipants()->pluck('user_id')->all();
    }
}
