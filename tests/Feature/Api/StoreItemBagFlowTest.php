<?php

namespace Tests\Feature\Api;

use App\Domain\Wallet\WalletService;
use App\Models\StoreItem;
use App\Models\Wallet;
use PHPUnit\Framework\Attributes\Test;

/**
 * Store â†’ Item Bag, as the app walks it: what you buy lands in your own bag under its
 * type's category, and what you send lands in the friend's bag under the same one.
 * The Item Bag reads `GET /store/items/mine` and files each row by its `type`.
 */
class StoreItemBagFlowTest extends MobileTestCase
{
    protected string $base = '/api/v1';

    protected function item(string $type, string $name): StoreItem
    {
        return StoreItem::create([
            'type'        => $type,
            'name'        => $name,
            'image_url'   => "https://cdn.test/{$name}.png",
            'source'      => 'purchase',
            'coin_price'  => 100,
            'rental_days' => 7,
            'is_active'   => true,
        ]);
    }

    /** @return array<string, string> name => type, as the Item Bag would file them */
    protected function bag(): array
    {
        return collect($this->getJson("{$this->base}/store/items/mine")->assertOk()->json('data'))
            ->mapWithKeys(fn (array $row) => [$row['name'] => $row['type']])
            ->all();
    }

    #[Test]
    public function a_purchase_lands_in_your_bag_under_its_category(): void
    {
        $me = $this->actingAsUser($this->makeUser('Buyer'));
        app(WalletService::class)->creditSystem($me, Wallet::COIN, 1000, 'test');
        $frame = $this->item(StoreItem::TYPE_FRAME, 'Pearl');
        $bubble = $this->item(StoreItem::TYPE_BUBBLE, 'Cloud');
        $wave = $this->item(StoreItem::TYPE_MIC_WAVE, 'Pulse');

        foreach ([$frame, $bubble, $wave] as $item) {
            $this->postJson("{$this->base}/store/items/{$item->id}/purchase")->assertOk();
        }

        $this->assertSame(
            ['Pearl' => StoreItem::TYPE_FRAME, 'Cloud' => StoreItem::TYPE_BUBBLE, 'Pulse' => StoreItem::TYPE_MIC_WAVE],
            $this->bag(),
        );
        // Bought, not worn: putting it on is the Item Bag's Equip.
        $this->assertFalse(collect($this->getJson("{$this->base}/store/items/mine")->json('data'))->contains('equipped', true));
    }

    #[Test]
    public function a_sent_item_lands_in_the_friends_bag_not_yours(): void
    {
        $me = $this->actingAsUser($this->makeUser('Sender'));
        $friend = $this->makeUser('Friend');
        app(WalletService::class)->creditSystem($me, Wallet::COIN, 1000, 'test');
        $frame = $this->item(StoreItem::TYPE_FRAME, 'Pearl');

        $this->postJson("{$this->base}/store/items/{$frame->id}/send", ['user_uuid' => $friend->uuid])->assertOk();
        $this->assertSame([], $this->bag());

        $this->actingAsUser($friend);
        $this->assertSame(['Pearl' => StoreItem::TYPE_FRAME], $this->bag());
        $this->assertSame('gift', $this->getJson("{$this->base}/store/items/mine")->json('data.0.source'));
    }

    #[Test]
    public function equip_and_unequip_from_the_bag(): void
    {
        $me = $this->actingAsUser($this->makeUser('Wearer'));
        app(WalletService::class)->creditSystem($me, Wallet::COIN, 1000, 'test');
        $a = $this->item(StoreItem::TYPE_FRAME, 'Pearl');
        $b = $this->item(StoreItem::TYPE_FRAME, 'Ruby');
        $this->postJson("{$this->base}/store/items/{$a->id}/purchase")->assertOk();
        $this->postJson("{$this->base}/store/items/{$b->id}/purchase")->assertOk();

        $worn = fn () => collect($this->getJson("{$this->base}/store/items/mine")->json('data'))->where('equipped', true)->pluck('name')->all();

        $this->postJson("{$this->base}/store/items/{$a->id}/equip")->assertOk();
        $this->assertSame(['Pearl'], $worn());

        // Equipping another frame takes the first one off.
        $this->postJson("{$this->base}/store/items/{$b->id}/equip")->assertOk();
        $this->assertSame(['Ruby'], array_values($worn()));

        // Unequip: nothing of that type is worn anywhere.
        $this->deleteJson("{$this->base}/store/items/{$b->id}/equip")->assertOk();
        $this->assertSame([], $worn());
    }

    #[Test]
    public function the_worn_frame_follows_you_everywhere_and_leaves_with_unequip(): void
    {
        $me = $this->makeUser('Wearer');
        $fan = $this->makeUser('Fan');
        app(WalletService::class)->creditSystem($me, Wallet::COIN, 1000, 'test');
        $pearl = $this->item(StoreItem::TYPE_FRAME, 'Pearl');
        $this->actingAsUser($fan);
        $this->postJson("{$this->base}/users/{$me->uuid}/follow")->assertSuccessful();

        $this->actingAsUser($me);
        $this->postJson("{$this->base}/store/items/{$pearl->id}/purchase")->assertOk();
        $this->assertNull($this->getJson("{$this->base}/auth/me")->json('data.user.frame'));

        $this->postJson("{$this->base}/store/items/{$pearl->id}/equip")->assertOk();
        // Your own profile â€¦
        $this->assertSame('https://cdn.test/Pearl.png', $this->getJson("{$this->base}/auth/me")->json('data.user.frame.image_url'));
        // â€¦ and anywhere someone else sees you: here, the fan's Following list.
        $seen = fn () => collect($this->getJson("{$this->base}/users/{$fan->uuid}/following")->json('data'))
            ->firstWhere('uuid', $me->uuid)['frame'] ?? null;
        $this->assertSame('https://cdn.test/Pearl.png', $seen()['image_url'] ?? null);

        $this->deleteJson("{$this->base}/store/items/{$pearl->id}/equip")->assertOk();
        $this->assertNull($this->getJson("{$this->base}/auth/me")->json('data.user.frame'));
        $this->assertNull($seen());
    }
}
