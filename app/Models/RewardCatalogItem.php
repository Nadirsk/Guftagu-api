<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-manageable reward an event can hand out — replaces the old fixed
 * `reward_type` enum. `handler_key` names one of the automated grant paths
 * (see {@see \App\Domain\Events\EventCampaignService::grantBundle()}); `manual` is the
 * escape hatch for anything an admin invents that no handler exists for — it records the
 * claim and leaves it flagged for support, so a brand new kind of reward never needs a
 * code change to configure.
 */
class RewardCatalogItem extends Model
{
    public const AUTOMATED_HANDLERS = ['coins', 'diamonds', 'vip', 'frame', 'chat_bubble', 'entry_effect', 'badge'];
    public const MANUAL = 'manual';

    public const HANDLER_KEYS = [...self::AUTOMATED_HANDLERS, self::MANUAL];

    /** Which StoreItem::TYPE_* a handler_key of frame/chat_bubble/entry_effect must point at. */
    public const STORE_ITEM_TYPES = [
        'frame'        => StoreItem::TYPE_FRAME,
        'chat_bubble'  => StoreItem::TYPE_BUBBLE,
        'entry_effect' => StoreItem::TYPE_ENTRANCE_EFFECT,
    ];

    protected $fillable = ['name', 'icon_url', 'description', 'handler_key', 'handler_ref_id', 'is_active'];

    protected function casts(): array
    {
        return ['handler_ref_id' => 'integer', 'is_active' => 'boolean'];
    }

    public function tierRewards(): HasMany
    {
        return $this->hasMany(EventTierReward::class, 'reward_catalog_id');
    }

    public function isManual(): bool
    {
        return $this->handler_key === self::MANUAL;
    }

    /** Which catalogue table `handler_ref_id` addresses, or null for coins/diamonds/manual. */
    public function refModel(): ?string
    {
        return match ($this->handler_key) {
            'frame', 'chat_bubble', 'entry_effect' => StoreItem::class,
            'vip'   => VipTier::class,
            'badge' => Badge::class,
            default => null,
        };
    }

    /**
     * The picture the app shows on a reward tile: the admin's own icon if one was set,
     * otherwise the image of the thing actually granted (the frame itself, the medal,
     * the VIP badge). Null for coins/diamonds/manual — the app has bundled art for those.
     */
    public function imageUrl(): ?string
    {
        if ($this->icon_url) {
            return $this->icon_url;
        }

        $model = $this->refModel();

        if ($model === null || $this->handler_ref_id === null) {
            return null;
        }

        $ref = $model::find($this->handler_ref_id);

        return match (true) {
            $ref instanceof StoreItem => $ref->image_url,
            $ref instanceof Badge => $ref->icon_url,
            $ref instanceof VipTier => $ref->badge_url,
            default => null,
        };
    }

    /** A human-readable name for whatever handler_ref_id points at, for admin display. */
    public function refLabel(): ?string
    {
        $model = $this->refModel();

        if ($model === null || $this->handler_ref_id === null) {
            return null;
        }

        $ref = $model::find($this->handler_ref_id);

        return match (true) {
            $ref === null => null,
            $ref instanceof VipTier => $ref->name_en,
            $ref instanceof Badge => $ref->name_en,
            $ref instanceof StoreItem => $ref->name,
            default => null,
        };
    }
}
