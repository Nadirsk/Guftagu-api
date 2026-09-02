<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * docs/02 §7 — A.6c. ⚠ CI-02 supplies the real pricing.
 *
 * `privileges` is JSON rather than columns because the list is a business decision that
 * will change without a schema change — which is the point of docs/00 §7's "configurable
 * from day one".
 */
class VipTier extends Model
{
    /** The privilege keys the app understands. Unknown keys are stored but ignored. */
    public const PRIVILEGES = [
        'hidden_entry'      => 'Enter rooms invisibly',
        'ad_free'           => 'No advertisements',
        'exclusive_gifts'   => 'Access to VIP-only gifts',
        'profile_frame'     => 'Animated profile frame',
        'entrance_effect'   => 'Entrance animation',
        'chat_colour'       => 'Coloured chat name',
        'anti_kick'         => 'Cannot be kicked from a room',
        'priority_seat'     => 'Priority when requesting a seat',
        'rank_boost'        => 'Bonus wealth points',
        'custom_room_theme' => 'Unlock premium room themes',
    ];

    protected $fillable = [
        'level', 'name_en', 'name_hi', 'badge_url', 'frame_url', 'entrance_effect_id',
        'monthly_price_paise', 'quarterly_price_paise', 'yearly_price_paise',
        'coin_price', 'privileges', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level'                 => 'integer',
            'privileges'            => 'array',
            'is_active'             => 'boolean',
            'monthly_price_paise'   => 'integer',
            'quarterly_price_paise' => 'integer',
            'yearly_price_paise'    => 'integer',
            'coin_price'            => 'integer',
        ];
    }

    /** Paise are the stored truth; rupees are a display concern. */
    public function monthlyRupees(): float
    {
        return $this->monthly_price_paise / 100;
    }

    public function grants(string $privilege): bool
    {
        return in_array($privilege, $this->privileges ?? [], true);
    }
}
