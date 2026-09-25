<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class FeedbackImage extends Model
{
    protected $fillable = ['feedback_id', 'path'];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }

    /** Resolved through the same disk FeedbackController stored it on — see ImageUploadService. */
    public function url(): string
    {
        return Storage::disk(config('filesystems.uploads_disk', 'public'))->url($this->path);
    }
}
