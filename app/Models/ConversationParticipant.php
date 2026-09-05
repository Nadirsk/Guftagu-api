<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/02 §12 — one row per person per thread.
 *
 * The unread count and the two delivery marks live here, not on the conversation: the same
 * thread looks different to each side, and the ticks on my messages are a fact about
 * everybody else's marks.
 */
class ConversationParticipant extends Model
{
    protected $fillable = [
        'conversation_id', 'user_id', 'role', 'joined_at', 'left_at',
        'last_delivered_message_id', 'delivered_at',
        'last_read_message_id', 'read_at',
        'is_muted', 'unread_count',
    ];

    protected function casts(): array
    {
        return [
            'joined_at'    => 'datetime',
            'left_at'      => 'datetime',
            'delivered_at' => 'datetime',
            'read_at'      => 'datetime',
            'is_muted'     => 'boolean',
            'unread_count' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Move a mark forward, never backwards.
     *
     * Acks arrive out of order — a socket ack for message 40 can land after the app-resume
     * sweep already acked 50. Taking the larger value is what stops a late ack from turning
     * somebody's blue ticks grey again.
     */
    public function advance(string $column, ?int $messageId, string $timestampColumn): bool
    {
        if ($messageId === null || $messageId <= (int) $this->{$column}) {
            return false;
        }

        $this->forceFill([$column => $messageId, $timestampColumn => now()])->save();

        return true;
    }
}
