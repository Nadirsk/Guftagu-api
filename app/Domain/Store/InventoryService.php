<?php

namespace App\Domain\Store;

use App\Models\Badge;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\UserStoreItem;
use App\Models\UserVipSubscription;
use App\Models\VipTier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The entitlement layer events, VIP purchases and the store all grant through — the
 * piece `EventService::payReward()` and `CheckinReward::TYPES` both flagged as missing
 * ("need user_frames, user_badges and user_vip_subscriptions").
 *
 * Every grant here is **extend, not stack**: a repeat grant of the same VIP tier, the
 * same store item, or the same badge lengthens the existing entitlement rather than
 * creating a second, ambiguous one running in parallel. That is a product decision, not
 * an accident — see the docstring on each method for the exact rule.
 */
class InventoryService
{
    /**
     * Grant VIP for `$days` days.
     *
     * Repeat grants of the **same** tier extend the current expiry (never discarding
     * remaining time, so a renewal bought a day early does not cost the user that day).
     * A grant of a **different** tier starts a fresh `$days`-day term on the new tier
     * from now — tiers do not stack, so upgrading mid-term simply replaces what was left.
     */
    public function grantVip(User $user, VipTier $tier, int $days, string $source = 'event'): UserVipSubscription
    {
        $current = $this->activeVip($user);

        if ($current !== null && $current->vip_tier_id === $tier->id) {
            $current->forceFill(['expires_at' => $current->expires_at->copy()->addDays($days)])->save();

            return $current;
        }

        return UserVipSubscription::create([
            'user_id'     => $user->id,
            'vip_tier_id' => $tier->id,
            'starts_at'   => now(),
            'expires_at'  => now()->addDays($days),
            'source'      => $source,
        ]);
    }

    /**
     * Grant a store item (frame, bubble, entry banner or entrance effect).
     *
     * `$days` null means permanent. Repeat grants extend from whichever is later — the
     * current expiry or now — so a grant never shortens what the user already has, and a
     * permanent item stays permanent regardless of what is granted on top of it.
     */
    public function grantStoreItem(User $user, StoreItem $item, ?int $days, string $source = 'event'): UserStoreItem
    {
        $owned = UserStoreItem::query()
            ->where('user_id', $user->id)
            ->where('store_item_id', $item->id)
            ->first();

        if ($owned === null) {
            return UserStoreItem::create([
                'user_id'       => $user->id,
                'store_item_id' => $item->id,
                'starts_at'     => now(),
                'expires_at'    => $days === null ? null : now()->addDays($days),
                'source'        => $source,
            ]);
        }

        if ($owned->expires_at === null) {
            // Already permanent — nothing shorter can take that away.
            return $owned;
        }

        if ($days === null) {
            $owned->forceFill(['expires_at' => null])->save();

            return $owned;
        }

        $base = $owned->expires_at->isFuture() ? $owned->expires_at : now();
        $owned->forceFill(['expires_at' => $base->copy()->addDays($days)])->save();

        return $owned;
    }

    /**
     * Grant a badge ("Medal"). `$days` null means permanent — the common case, since
     * badges are usually earned once and kept; an event can still grant a timed one.
     */
    public function grantBadge(User $user, Badge $badge, ?int $days = null, string $source = 'event'): UserBadge
    {
        $owned = UserBadge::query()
            ->where('user_id', $user->id)
            ->where('badge_id', $badge->id)
            ->first();

        if ($owned === null) {
            return UserBadge::create([
                'user_id'    => $user->id,
                'badge_id'   => $badge->id,
                'awarded_at' => now(),
                'expires_at' => $days === null ? null : now()->addDays($days),
                'source'     => $source,
            ]);
        }

        if ($owned->expires_at === null || $days === null) {
            $owned->forceFill(['expires_at' => $days === null ? null : $owned->expires_at])->save();

            return $owned;
        }

        $base = $owned->expires_at->isFuture() ? $owned->expires_at : now();
        $owned->forceFill(['expires_at' => $base->copy()->addDays($days)])->save();

        return $owned;
    }

    /** The user's current VIP subscription, or null if none is active. */
    public function activeVip(User $user): ?UserVipSubscription
    {
        return UserVipSubscription::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderByDesc('expires_at')
            ->first();
    }

    /** @return Collection<int, UserStoreItem> */
    public function activeItems(User $user, ?string $type = null): Collection
    {
        return UserStoreItem::query()
            ->where('user_id', $user->id)
            ->active()
            ->with('storeItem')
            ->when($type !== null, fn ($q) => $q->whereHas('storeItem', fn ($q2) => $q2->where('type', $type)))
            ->get()
            ->values();
    }

    /**
     * Wear one owned item. One item per type is worn at a time, so equipping a frame takes
     * off whichever frame was on before. Returns null when the user does not own the item
     * or it has expired.
     */
    public function equip(User $user, StoreItem $item): ?UserStoreItem
    {
        $owned = UserStoreItem::query()
            ->where('user_id', $user->id)
            ->where('store_item_id', $item->id)
            ->active()
            ->first();

        if ($owned === null) {
            return null;
        }

        DB::transaction(function () use ($user, $item, $owned) {
            UserStoreItem::query()
                ->where('user_id', $user->id)
                ->whereNotNull('equipped_at')
                ->whereHas('storeItem', fn ($q) => $q->where('type', $item->type))
                ->update(['equipped_at' => null]);

            $owned->forceFill(['equipped_at' => now()])->save();
        });

        return $owned;
    }

    /** Take an item off. Not owning it is not an error — there is nothing to take off. */
    public function unequip(User $user, StoreItem $item): void
    {
        UserStoreItem::query()
            ->where('user_id', $user->id)
            ->where('store_item_id', $item->id)
            ->update(['equipped_at' => null]);
    }

    /** The item of `$type` the user is wearing right now, if it has not expired. */
    public function equipped(User $user, string $type): ?StoreItem
    {
        return UserStoreItem::query()
            ->where('user_id', $user->id)
            ->whereNotNull('equipped_at')
            ->active()
            ->whereHas('storeItem', fn ($q) => $q->where('type', $type)->where('is_active', true))
            ->with('storeItem')
            ->latest('equipped_at')
            ->first()
            ?->storeItem;
    }

    /**
     * What plays when this user walks into a room: their VIP tier (the "VIP4 Enter" banner),
     * the entry banner and the entrance effect they are wearing. Null when there is nothing
     * to show — a plain arrival is just the chat line.
     *
     * @return array{vip: array{level: int, name: string}|null, banner: array<string, mixed>|null, effect: array<string, mixed>|null}|null
     */
    public function arrival(User $user): ?array
    {
        $tier = $this->activeVip($user)?->vipTier;
        $banner = $this->equipped($user, StoreItem::TYPE_ENTRY_BANNER);
        $effect = $this->equipped($user, StoreItem::TYPE_ENTRANCE_EFFECT);

        if ($tier === null && $banner === null && $effect === null) {
            return null;
        }

        return [
            'vip'    => $tier === null ? null : ['level' => $tier->level, 'name' => $tier->name_en],
            'banner' => $banner === null ? null : [
                'name'      => $banner->name,
                'image_url' => $banner->image_url,
            ],
            'effect' => $effect === null ? null : [
                'name'           => $effect->name,
                'image_url'      => $effect->image_url,
                'animation_url'  => $effect->animation_url,
                'animation_type' => $effect->animation_type,
                'duration_ms'    => $effect->duration_ms,
            ],
        ];
    }

    /** A required tier is a floor, not an exact match — level 3 satisfies a level-2 gate. */
    public function meetsTier(User $user, int $requiredTierId): bool
    {
        $required = VipTier::find($requiredTierId);
        $active = $this->activeVip($user);

        return $required !== null && $active !== null && $active->vipTier->level >= $required->level;
    }

    /** @return Collection<int, UserBadge> */
    public function activeBadges(User $user): Collection
    {
        return UserBadge::query()
            ->where('user_id', $user->id)
            ->active()
            ->with('badge')
            ->get()
            ->values();
    }
}
