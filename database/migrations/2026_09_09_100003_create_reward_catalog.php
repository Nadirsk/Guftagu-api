<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces `event_tier_rewards.reward_type` (a fixed enum) with a reference to an
 * admin-manageable catalog. Adding a new reward — including one nobody wrote a grant
 * handler for — is now a catalog row, not a code change: `handler_key` names one of the
 * automated grant paths (`coins`, `diamonds`, `vip`, `frame`, `chat_bubble`,
 * `entry_effect`, `badge`), or `manual` for anything the admin invents on the spot, which
 * records the claim and leaves it for support to fulfil outside the system.
 *
 * The two seeded events' existing reward lines are migrated onto catalog rows below —
 * each distinct (reward_type, reward_ref_id) combination becomes one catalog entry —
 * rather than left pointing at a column that no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('icon_url', 500)->nullable();
            $table->string('description', 300)->nullable();
            $table->string('handler_key', 20); // coins diamonds vip frame chat_bubble entry_effect badge manual
            // Meaning depends on handler_key: store_items.id (frame/chat_bubble/entry_effect),
            // vip_tiers.id (vip), badges.id (badge); null for coins/diamonds/manual.
            $table->unsignedBigInteger('handler_ref_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['handler_key', 'is_active']);
        });

        Schema::table('event_tier_rewards', function (Blueprint $table) {
            $table->foreignId('reward_catalog_id')->nullable()->after('event_tier_id')
                ->constrained('reward_catalog_items')->restrictOnDelete();
        });

        $this->backfillCatalog();

        Schema::table('event_tier_rewards', function (Blueprint $table) {
            $table->dropColumn(['reward_type', 'reward_ref_id']);
            $table->unsignedBigInteger('reward_catalog_id')->nullable(false)->change();
        });
    }

    /** One catalog row per distinct (reward_type, reward_ref_id) already in use. */
    protected function backfillCatalog(): void
    {
        if (! Schema::hasColumn('event_tier_rewards', 'reward_type')) {
            return;
        }

        $handlerKeys = [
            'coins' => 'coins', 'diamonds' => 'diamonds', 'vip_days' => 'vip',
            'frame' => 'frame', 'bubble' => 'chat_bubble', 'entrance_effect' => 'entry_effect',
            'badge' => 'badge', 'special_id' => 'manual', 'customize_gift' => 'manual',
        ];

        $rows = DB::table('event_tier_rewards')->select('id', 'reward_type', 'reward_ref_id')->get();
        $catalogIdFor = []; // "reward_type:ref_id" => catalog id

        foreach ($rows as $row) {
            $key = "{$row->reward_type}:{$row->reward_ref_id}";

            if (! isset($catalogIdFor[$key])) {
                $catalogIdFor[$key] = DB::table('reward_catalog_items')->insertGetId([
                    'name'            => $this->displayName($row->reward_type, $row->reward_ref_id),
                    'handler_key'     => $handlerKeys[$row->reward_type] ?? 'manual',
                    'handler_ref_id'  => $row->reward_ref_id,
                    'is_active'       => true,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            DB::table('event_tier_rewards')->where('id', $row->id)->update(['reward_catalog_id' => $catalogIdFor[$key]]);
        }
    }

    protected function displayName(string $rewardType, ?int $refId): string
    {
        return match (true) {
            $rewardType === 'coins' => 'Coins',
            $rewardType === 'diamonds' => 'Diamonds',
            $rewardType === 'special_id' => 'Special ID',
            $rewardType === 'customize_gift' => 'Customize Gift',
            $rewardType === 'vip_days' && $refId !== null => (DB::table('vip_tiers')->find($refId)?->name_en) ?? 'VIP',
            in_array($rewardType, ['frame', 'bubble', 'entrance_effect'], true) && $refId !== null
                => (DB::table('store_items')->find($refId)?->name) ?? ucfirst($rewardType),
            $rewardType === 'badge' && $refId !== null => (DB::table('badges')->find($refId)?->name_en) ?? 'Badge',
            default => ucfirst(str_replace('_', ' ', $rewardType)),
        };
    }

    public function down(): void
    {
        Schema::table('event_tier_rewards', function (Blueprint $table) {
            $table->string('reward_type', 30)->nullable();
            $table->unsignedBigInteger('reward_ref_id')->nullable();
        });

        DB::table('event_tier_rewards')->orderBy('id')->each(function ($row) {
            $catalog = DB::table('reward_catalog_items')->find($row->reward_catalog_id);
            $rewardType = match ($catalog?->handler_key) {
                'vip' => 'vip_days',
                'chat_bubble' => 'bubble',
                'entry_effect' => 'entrance_effect',
                default => $catalog?->handler_key ?? 'manual',
            };

            DB::table('event_tier_rewards')->where('id', $row->id)->update([
                'reward_type'   => $rewardType,
                'reward_ref_id' => $catalog?->handler_ref_id,
            ]);
        });

        Schema::table('event_tier_rewards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reward_catalog_id');
        });

        Schema::dropIfExists('reward_catalog_items');
    }
};
