<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/02 §5.1.
 *
 * A **cached projection of the ledger, not the truth** (§15 rule 2). Never write to a
 * balance here directly — go through WalletService, which locks the row and writes the
 * matching ledger entry in the same transaction.
 */
class Wallet extends Model
{
    public const COIN = 'coin';
    public const DIAMOND = 'diamond';

    protected $fillable = [
        'user_id', 'coin_balance', 'diamond_balance', 'frozen_coins', 'frozen_diamonds',
        'lifetime_coins_purchased', 'lifetime_coins_spent', 'lifetime_diamonds_earned',
        'lifetime_withdrawn_paise', 'is_frozen', 'version',
        'wealth_level_override_id', 'charm_level_override_id',
    ];

    /**
     * The zeroes are also DB defaults, but those only apply after a round trip — a freshly
     * created model would report `null` balances until reloaded, and null is not a balance.
     */
    protected $attributes = [
        'coin_balance'             => 0,
        'diamond_balance'          => 0,
        'frozen_coins'             => 0,
        'frozen_diamonds'          => 0,
        'lifetime_coins_purchased' => 0,
        'lifetime_coins_spent'     => 0,
        'lifetime_diamonds_earned' => 0,
        'lifetime_withdrawn_paise' => 0,
        'is_frozen'                => false,
        'version'                  => 0,
    ];

    protected function casts(): array
    {
        return [
            // Cast to int, not float: these are counts, and a float balance is how you
            // lose a coin per thousand transactions (§15 rule 1).
            'coin_balance'             => 'integer',
            'diamond_balance'          => 'integer',
            'frozen_coins'             => 'integer',
            'frozen_diamonds'          => 'integer',
            'lifetime_coins_purchased' => 'integer',
            'lifetime_coins_spent'     => 'integer',
            'lifetime_diamonds_earned' => 'integer',
            'lifetime_withdrawn_paise' => 'integer',
            'is_frozen'                => 'boolean',
            'version'                  => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coinTransactions(): HasMany
    {
        return $this->hasMany(CoinTransaction::class);
    }

    public function diamondTransactions(): HasMany
    {
        return $this->hasMany(DiamondTransaction::class);
    }

    public function wealthLevelOverride(): BelongsTo
    {
        return $this->belongsTo(WealthCharmLevel::class, 'wealth_level_override_id');
    }

    public function charmLevelOverride(): BelongsTo
    {
        return $this->belongsTo(WealthCharmLevel::class, 'charm_level_override_id');
    }

    /**
     * GFT-027's override wins outright; otherwise the level is derived from the same
     * lifetime counter the ladder is built against. Never null once at least one active
     * level-1 threshold exists at 0 — a wallet with 0 lifetime spend still resolves to
     * whatever level starts at 0, if the ladder defines one.
     */
    public function wealthLevel(): ?WealthCharmLevel
    {
        return $this->wealthLevelOverride ?? WealthCharmLevel::resolveFor('wealth', $this->lifetime_coins_spent);
    }

    public function charmLevel(): ?WealthCharmLevel
    {
        return $this->charmLevelOverride ?? WealthCharmLevel::resolveFor('charm', $this->lifetime_diamonds_earned);
    }

    public function balanceOf(string $currency): int
    {
        return $currency === self::DIAMOND ? $this->diamond_balance : $this->coin_balance;
    }

    /** Spendable balance excludes anything held against a pending operation. */
    public function availableOf(string $currency): int
    {
        return $currency === self::DIAMOND
            ? $this->diamond_balance - $this->frozen_diamonds
            : $this->coin_balance - $this->frozen_coins;
    }
}
