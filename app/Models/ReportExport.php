<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A.2d — a queued export. The row exists before the file does, so the caller is not blocked.
class ReportExport extends Model
{
    use HasUuids;

    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const READY = 'ready';
    public const FAILED = 'failed';

    protected $fillable = [
        'uuid', 'admin_user_id', 'type', 'filters', 'format',
        'status', 'file_path', 'row_count', 'error', 'expires_at',
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

    protected function casts(): array
    {
        return ['filters' => 'array', 'expires_at' => 'datetime'];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::READY && $this->file_path !== null;
    }
}
