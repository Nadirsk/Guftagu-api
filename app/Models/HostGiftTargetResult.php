<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A host's gift-target standing for one calendar month. See the creating migration. */
class HostGiftTargetResult extends Model
{
    protected $fillable = [
        'host_id', 'period', 'coins_sent', 'minutes_live', 'policy_id',
        'host_reward_paise', 'agency_reward_paise', 'evaluated_at', 'evaluated_by',
    ];

    protected function casts(): array
    {
        return [
            'coins_sent'           => 'integer',
            'minutes_live'         => 'integer',
            'host_reward_paise'    => 'integer',
            'agency_reward_paise'  => 'integer',
            'evaluated_at'         => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(GiftTargetPolicy::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'evaluated_by');
    }
}
