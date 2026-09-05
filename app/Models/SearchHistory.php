<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Recent searches, per user (D.3a). Addressed by uuid on the wire — docs/03 §2.4. */
class SearchHistory extends Model
{
    use HasUuids;

    public const TYPE_TERM = 'term';
    public const TYPE_USER = 'user';
    public const TYPE_ROOM = 'room';

    public const TYPES = [self::TYPE_TERM, self::TYPE_USER, self::TYPE_ROOM];

    /** How many entries a user's history keeps. Older ones are trimmed on write. */
    public const KEEP = 20;

    protected $table = 'search_histories';

    protected $fillable = ['uuid', 'user_id', 'type', 'term', 'target_uuid', 'searched_at'];

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
        return ['searched_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
