<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/02 §13 — A.10a, GFT-104.
 *
 * Versioned on purpose. Terms and privacy pages are what a user consented to on a
 * particular date, so "what did it say in March" has to be answerable — overwriting the
 * row would destroy the only evidence of that.
 */
class CmsPage extends Model
{
    public const TYPES = ['terms', 'privacy', 'faq', 'about', 'guidelines', 'help'];

    /** Pages a user legally agreed to. Editing one always cuts a new version. */
    public const LEGAL_TYPES = ['terms', 'privacy'];

    protected $fillable = [
        'slug', 'title_en', 'title_hi', 'content_en', 'content_hi',
        'type', 'version', 'is_published', 'published_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version'      => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CmsPageVersion::class)->orderByDesc('version');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    public function isLegal(): bool
    {
        return in_array($this->type, self::LEGAL_TYPES, true);
    }
}
