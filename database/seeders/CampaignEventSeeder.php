<?php

namespace Database\Seeders;

use App\Models\Badge;
use App\Models\Event;
use App\Models\RankingRule;
use App\Models\RewardCatalogItem;
use App\Models\StoreItem;
use App\Models\VipTier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The two reference campaign events built on the Event Builder (`event_tiers` /
 * `event_tier_rewards` / `reward_catalog_items`): "Recharge Activity" (threshold tiers)
 * and "Weekly Star" (rank_range tiers, riding the existing `charm_weekly` RankingRule).
 *
 * Both events prove out the block-based `layout` — every block, its `config` and its
 * `style` are exactly what a real admin session would produce from the Event Builder, not
 * a special-cased shape only these two events understand.
 *
 * ⚠ **Bundle contents are read off the reference screenshots, not a spec — every
 * threshold, duration and coin amount here is a placeholder the same way VipTierSeeder's
 * prices are (CI-02).** A few cells in the screenshots were ambiguous (exact VIP/medal
 * durations on some tiers); the choices made here are called out in each line below and
 * are meant to be corrected once real numbers exist, not treated as final.
 *
 * Idempotent — keyed on title/label/type+name, safe to re-run.
 */
class CampaignEventSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRechargeActivity();
        $this->seedWeeklyStar();
    }

    protected function seedRechargeActivity(): void
    {
        $coins = $this->catalog('Coins', 'coins');
        $entryEffect = $this->catalog('Entry Effect', 'entry_effect', $this->storeItem(StoreItem::TYPE_ENTRANCE_EFFECT, 'Entry Effect')->id);
        $chatBubble = $this->catalog('Chat Bubble', 'chat_bubble', $this->storeItem(StoreItem::TYPE_BUBBLE, 'Chat Bubble')->id);
        $medal = $this->catalog('Medal', 'badge', $this->badge('recharge_medal', 'Medal')->id);
        // A custom, admin-invented reward with no automated handler — proves the
        // catalog's "manual" escape hatch needs no code change to add.
        $specialId = $this->catalog('Special ID', 'manual');
        $customizeGift = $this->catalog('Customize Gift', 'manual');

        $vip = VipTier::query()->orderBy('level')->pluck('id', 'level')
            ->mapWithKeys(fn ($id, $level) => [$level => $this->catalog("VIP {$level}", 'vip', $id)]);

        $event = Event::updateOrCreate(
            ['title_en' => 'Recharge Activity'],
            [
                'type'             => 'recharge_activity',
                'progress_metric'  => 'recharge_amount',
                'period'           => 'monthly',
                'banner_url'       => null,
                'rules'            => ['Recharge the listed amount within the period to unlock a tier.', 'Each tier is claimed once per period.'],
                'layout'           => [
                    $this->block('banner', style: ['bg_color' => '#2a0f1f', 'text_color' => '#ffd76b']),
                    $this->block('countdown'),
                    $this->block('my_progress_card', ['label_template' => 'My {period} Recharge']),
                    $this->block('tabs', ['labels' => ['Daily', 'Monthly']]),
                    $this->block('tier_grid'),
                    $this->block('rules_button', ['label' => 'Rules']),
                ],
                'starts_at'        => now()->subDays(2),
                'ends_at'          => now()->addMonths(3),
                'status'           => Event::SCHEDULED,
                'is_featured'      => true,
            ],
        );

        // [threshold, label, coin_amount, vip_level, vip_days, frame_days,
        //  entry_effect_days, bubble_days, medal_days, special_id_days, customize_gift_days]
        // 300K's "Coin 1000 1 Day" read as the coins line only — a 1-day validity on a
        // one-off coin credit has no meaning, so that "1 Day" is treated as a screenshot
        // labelling quirk rather than a real duration.
        $tiers = [
            [300_000, '300K', 1000, 1, 15, 30, 30, 30, null, null, null],
            [500_000, '500K', 3000, 2, 30, 30, null, null, 30, 15, null],
            [700_000, '700K', 7000, 3, 15, 30, null, null, 30, 15, 30],
            [1_000_000, '1M', 15000, 4, 15, 30, null, null, 30, 30, 30],
        ];

        foreach ($tiers as $index => [$threshold, $label, $coinAmount, $vipLevel, $vipDays, $frameDays, $effectDays, $bubbleDays, $medalDays, $specialIdDays, $customizeGiftDays]) {
            $tier = $event->tiers()->updateOrCreate(
                ['threshold_value' => $threshold],
                ['tier_type' => 'threshold', 'label' => $label, 'sort_order' => $index + 1],
            );

            $tier->rewards()->delete();
            $frame = $this->catalog("{$label} Frame", 'frame', $this->storeItem(StoreItem::TYPE_FRAME, "{$label} Frame")->id);

            $lines = [
                ['reward_catalog_id' => $coins->id, 'reward_value' => $coinAmount],
                ['reward_catalog_id' => $frame->id, 'duration_days' => $frameDays],
                ['reward_catalog_id' => $vip[$vipLevel]?->id, 'duration_days' => $vipDays],
            ];

            if ($effectDays !== null) {
                $lines[] = ['reward_catalog_id' => $entryEffect->id, 'duration_days' => $effectDays];
            }
            if ($bubbleDays !== null) {
                $lines[] = ['reward_catalog_id' => $chatBubble->id, 'duration_days' => $bubbleDays];
            }
            if ($medalDays !== null) {
                $lines[] = ['reward_catalog_id' => $medal->id, 'duration_days' => $medalDays];
            }
            if ($specialIdDays !== null) {
                $lines[] = ['reward_catalog_id' => $specialId->id, 'duration_days' => $specialIdDays];
            }
            if ($customizeGiftDays !== null) {
                $lines[] = ['reward_catalog_id' => $customizeGift->id, 'duration_days' => $customizeGiftDays];
            }

            foreach ($lines as $sort => $line) {
                $tier->rewards()->create([...$line, 'sort_order' => $sort]);
            }
        }

        $this->command->info('Recharge Activity: 4 tiers seeded (⚠ bundle numbers are placeholders).');
    }

    protected function seedWeeklyStar(): void
    {
        $rule = RankingRule::query()->where('key', 'charm_weekly')->first();

        if ($rule === null) {
            $this->command->error('No charm_weekly ranking rule — run RankingRuleSeeder first. Weekly Star skipped.');

            return;
        }

        $coins = $this->catalog('Coins', 'coins');
        $entryEffect = $this->catalog('Entry Effect', 'entry_effect', $this->storeItem(StoreItem::TYPE_ENTRANCE_EFFECT, 'Entry Effect')->id);
        $starFrame = $this->catalog('Star Frame', 'frame', $this->storeItem(StoreItem::TYPE_FRAME, 'Star Frame')->id);
        $medal = $this->catalog('Medal', 'badge', $this->badge('recharge_medal', 'Medal')->id);
        $vip = VipTier::query()->orderBy('level')->pluck('id', 'level')
            ->mapWithKeys(fn ($id, $level) => [$level => $this->catalog("VIP {$level}", 'vip', $id)]);

        $event = Event::updateOrCreate(
            ['title_en' => 'Weekly Star'],
            [
                'type'             => 'weekly_star',
                'ranking_rule_key' => $rule->key,
                'period'           => 'weekly',
                'rules'            => ['Ranked by diamonds earned (gifts received) this week.', 'Top 3 receive their bundle once the week closes.'],
                'layout'           => [
                    $this->block('banner', style: ['bg_color' => '#241a3a', 'text_color' => '#ffd76b']),
                    $this->block('countdown'),
                    $this->block('tabs', ['labels' => ['Ranking', 'Reward']]),
                    $this->block('leaderboard_list', ['visible_in_tab' => 'a']),
                    $this->block('reward_bundle_card', ['visible_in_tab' => 'b']),
                    $this->block('rules_button', ['label' => 'Rules']),
                ],
                'starts_at'        => now()->subDays(2),
                'ends_at'          => now()->addMonths(6),
                'status'           => Event::SCHEDULED,
                'is_featured'      => true,
            ],
        );

        // [rank, label, coin_amount, vip_level] — every band also gets Entry Effect*7D,
        // Frame*7D and the Medal, per the screenshot.
        $bands = [
            [1, 'Top 1', 15000, 4],
            [2, 'Top 2', 10000, 3],
            [3, 'Top 3', 3000, 2],
        ];

        foreach ($bands as [$rank, $label, $coinAmount, $vipLevel]) {
            $tier = $event->tiers()->updateOrCreate(
                ['rank_from' => $rank, 'rank_to' => $rank],
                ['tier_type' => 'rank_range', 'label' => $label, 'sort_order' => $rank],
            );

            $tier->rewards()->delete();
            $tier->rewards()->createMany([
                ['reward_catalog_id' => $vip[$vipLevel]?->id, 'duration_days' => 7, 'sort_order' => 1],
                ['reward_catalog_id' => $entryEffect->id, 'duration_days' => 7, 'sort_order' => 2],
                ['reward_catalog_id' => $starFrame->id, 'duration_days' => 7, 'sort_order' => 3],
                ['reward_catalog_id' => $medal->id, 'sort_order' => 4],
                ['reward_catalog_id' => $coins->id, 'reward_value' => $coinAmount, 'sort_order' => 5],
            ]);
        }

        $this->command->info('Weekly Star: 3 rank bands seeded on '.$rule->key.' (ranks 4-10 render from the plain board, no bundle).');
    }

    /** One reusable catalog row per (name) — "Coins" is the same row everywhere, only reward_value differs per use. */
    protected function catalog(string $name, string $handlerKey, ?int $refId = null): RewardCatalogItem
    {
        return RewardCatalogItem::updateOrCreate(
            ['name' => $name],
            ['handler_key' => $handlerKey, 'handler_ref_id' => $refId, 'is_active' => true],
        );
    }

    protected function storeItem(string $type, string $name): StoreItem
    {
        return StoreItem::updateOrCreate(
            ['type' => $type, 'name' => $name],
            ['source' => 'event', 'coin_price' => 0, 'is_active' => true],
        );
    }

    protected function badge(string $key, string $nameEn): Badge
    {
        return Badge::updateOrCreate(
            ['key' => $key],
            ['name_en' => $nameEn, 'is_auto_awarded' => false, 'is_active' => true],
        );
    }

    /** @return array{id: string, type: string, config: array<string, mixed>, style: array<string, mixed>} */
    protected function block(string $type, array $config = [], array $style = []): array
    {
        return ['id' => 'blk_'.Str::random(8), 'type' => $type, 'config' => $config, 'style' => $style];
    }
}
