<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §13 — the historical record of a page. Append-only, like audit_logs.
class CmsPageVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cms_page_id', 'version', 'title_en', 'title_hi',
        'content_en', 'content_hi', 'created_by',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'cms_page_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
