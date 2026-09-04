<?php

namespace Database\Seeders;

use App\Models\StoreItem;
use Illuminate\Database\Seeder;

/**
 * Demo data for the app's "Mall" — frames, bubbles, entry banners and entrance effects.
 * Real artwork is a client input (CI-06); the image URLs here are placeholder icons
 * generated locally and uploaded once, purely so the catalogue does not look empty.
 *
 * Idempotent — keyed on [type, name], safe to re-run.
 */
class StoreItemSeeder extends Seeder
{
    protected const ICONS = [
        StoreItem::TYPE_FRAME           => 'https://space-1.blr1.vultrobjects.com/store-items-seed-v2/4vpfHVMX1uga2BIlkF7SApZC3grKKo7CxzXKvhmB.png',
        StoreItem::TYPE_BUBBLE          => 'https://space-1.blr1.vultrobjects.com/store-items-seed-v2/xNnyTQvrxc9Bs865ZQTNExo1OtPBzpJ8lhAVYneE.png',
        StoreItem::TYPE_ENTRY_BANNER    => 'https://space-1.blr1.vultrobjects.com/store-items-seed-v2/tErGAercTV1pJueO16LXLlkwjQnjiF3cGllAdK6w.png',
        StoreItem::TYPE_ENTRANCE_EFFECT => 'https://space-1.blr1.vultrobjects.com/store-items-seed-v2/ulPUZ3UCH7IZyGv7BwIKQNKeGTQqYBwDrVhShMHc.png',
    ];

    /** [name, coin_price, rental_days] — null rental = permanent */
    protected const FRAMES = [
        ['Microphone', 100000, 3],
        ['Pearl', 100000, 3],
        ['Starlight', 100000, 3],
        ['Princess', 100000, 3],
        ['Kitty', 100000, 3],
        ['YoYo', 100000, 3],
    ];

    protected const BUBBLES = [
        ['Crystal Bubble', 300, 7],
        ['Golden Bubble', 3000, 7],
        ['Neon Bubble', 800, 7],
    ];

    protected const ENTRY_BANNERS = [
        ['Royal Entry', 5000, 7],
        ['Galaxy Banner', 15000, 30],
    ];

    protected const ENTRANCE_EFFECTS = [
        ['Sparkle Entry', 'vip_entry', 2000, 30],
        ['Fireworks Entry', 'big_gift', 0, null],
        ['Level Up Blast', 'level_up', 0, null],
    ];

    public function run(): void
    {
        $count = 0;

        foreach (self::FRAMES as [$name, $price, $days]) {
            $this->item(StoreItem::TYPE_FRAME, $name, [
                'source' => 'admin',
                'coin_price' => $price,
                'rental_days' => $days,
            ]);
            $count++;
        }

        foreach (self::BUBBLES as [$name, $price, $days]) {
            $this->item(StoreItem::TYPE_BUBBLE, $name, ['coin_price' => $price, 'rental_days' => $days]);
            $count++;
        }

        foreach (self::ENTRY_BANNERS as [$name, $price, $days]) {
            $this->item(StoreItem::TYPE_ENTRY_BANNER, $name, ['coin_price' => $price, 'rental_days' => $days]);
            $count++;
        }

        foreach (self::ENTRANCE_EFFECTS as [$name, $trigger, $price, $days]) {
            $this->item(StoreItem::TYPE_ENTRANCE_EFFECT, $name, [
                'trigger' => $trigger,
                'coin_price' => $price,
                'rental_days' => $days,
            ]);
            $count++;
        }

        $this->command->info("Store items: {$count} demo items across ".count(StoreItem::TYPES).' types.');
    }

    protected function item(string $type, string $name, array $attributes): void
    {
        StoreItem::updateOrCreate(
            ['type' => $type, 'name' => $name],
            [...$attributes, 'image_url' => self::ICONS[$type], 'is_active' => true],
        );
    }
}
