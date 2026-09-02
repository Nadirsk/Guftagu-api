<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// GFT-002 — persisted so the lockout trail survives a cache flush (A.1a).
class AdminLoginAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['email', 'ip', 'successful', 'reason', 'user_agent'];

    protected function casts(): array
    {
        return ['successful' => 'boolean', 'created_at' => 'datetime'];
    }
}
