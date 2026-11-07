<?php

namespace Tests\Feature\Api;

use App\Domain\Wallet\WalletService;
use App\Models\Block;
use App\Models\StoreItem;
use App\Models\UserStoreItem;
use App\Models\Wallet;
use PHPUnit\Framework\Attributes\Test;

/** Store → Send → Select: buying an item for somebody else. */
class StoreSendTest extends MobileTestCase
{
    protected string $base = '/api/v1';

    protected function frame(int $price = 100, ?int $days = 3): StoreItem
    {
        return StoreItem::create([
            'type'        => StoreItem::TYPE_FRAME,
            'name'        => 'Pearl',
            'image_url'   => 'https://cdn.test/pearl.png',
            'source'      => 'purchase',
            'coin_price'  => $price,
            'rental_days' => $days,
            'is_active'   => true,
        ]);
    }

    #[Test]
    public function send_charges_the_sender_and_grants_the_recipient(): void
    {
        $sender = $this->actingAsUser($this->makeUser('Sender'));
        $friend = $this->makeUser('Friend');
        app(WalletService::class)->creditSystem($sender, Wallet::COIN, 250, 'test');
        $item = $this->frame();

        $this->postJson("{$this->base}/store/items/{$item->id}/send", ['user_uuid' => $friend->uuid])
            ->assertOk()
            ->assertJsonPath('data.user_uuid', $friend->uuid);

        $this->assertSame(150, (int) Wallet::where('user_id', $sender->id)->value('coin_balance'));
        $owned = UserStoreItem::where('user_id', $friend->id)->where('store_item_id', $item->id)->first();
        $this->assertNotNull($owned);
        $this->assertSame('gift', $owned->source);
        $this->assertTrue($owned->expires_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
        $this->assertFalse(UserStoreItem::where('user_id', $sender->id)->exists());
    }

    #[Test]
    public function send_without_enough_coins_grants_nothing(): void
    {
        $sender = $this->actingAsUser($this->makeUser('Sender'));
        $friend = $this->makeUser('Friend');
        app(WalletService::class)->creditSystem($sender, Wallet::COIN, 50, 'test');
        $item = $this->frame(100);

        $this->postJson("{$this->base}/store/items/{$item->id}/send", ['user_uuid' => $friend->uuid])
            ->assertStatus(422);

        $this->assertFalse(UserStoreItem::where('user_id', $friend->id)->exists());
        $this->assertSame(50, (int) Wallet::where('user_id', $sender->id)->value('coin_balance'));
    }

    #[Test]
    public function send_refuses_yourself_and_blocked_users(): void
    {
        $sender = $this->actingAsUser($this->makeUser('Sender'));
        $blocker = $this->makeUser('Blocker');
        app(WalletService::class)->creditSystem($sender, Wallet::COIN, 500, 'test');
        $item = $this->frame();
        Block::create(['blocker_id' => $blocker->id, 'blocked_id' => $sender->id]);

        $this->postJson("{$this->base}/store/items/{$item->id}/send", ['user_uuid' => $sender->uuid])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RECIPIENT');
        $this->postJson("{$this->base}/store/items/{$item->id}/send", ['user_uuid' => $blocker->uuid])
            ->assertStatus(403)->assertJsonPath('error.code', 'BLOCKED');

        $this->assertSame(500, (int) Wallet::where('user_id', $sender->id)->value('coin_balance'));
    }
}
