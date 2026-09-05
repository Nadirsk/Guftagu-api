<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** docs/02 §12 — a chat message (D.4). */
class Message extends Model
{
    use HasUuids;

    public const TEXT = 'text';
    public const IMAGE = 'image';
    public const AUDIO = 'audio';
    public const VIDEO = 'video';
    public const GIFT = 'gift';
    public const SYSTEM = 'system';

    public const TYPES = [self::TEXT, self::IMAGE, self::AUDIO, self::VIDEO, self::GIFT, self::SYSTEM];

    protected $fillable = [
        'uuid', 'conversation_id', 'sender_id', 'type', 'body', 'media_url',
        'media_meta', 'reply_to_id', 'is_deleted', 'deleted_for',
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
            'media_meta' => 'array',
            'deleted_for' => 'array',
            'is_deleted' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /** "Delete for me" hides the row from one side; "delete for everyone" from all. */
    public function isHiddenFor(int $userId): bool
    {
        return in_array($userId, $this->deleted_for ?? [], true);
    }
}
