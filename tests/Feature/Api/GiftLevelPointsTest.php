<?php

namespace Tests\Feature\Api;

use App\Domain\Store\GiftSendService;
use App\Domain\Wallet\WalletService;
use App\Models\Gift;
use App\Models\GiftCategory;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Wallet;
use App\Models\WealthCharmLevel;
use App\Support\SocialPresenter;
use Database\Seeders\RoomCatalogueSeeder;
use PHPUnit\Framework\Attributes\Test;

/**
 * Wealth and charm levels count room gifts by coin value: a 10-coin gift adds 10 to the
 * sender's wealth and 10 to the receiver's charm. Gifts outside a room do not count.
 */
class GiftLevelPointsTest extends MobileTestCase
{
    #[Test]
    public function a_room_gift_moves_the_senders_wealth_and_the_receivers_charm_by_its_coin_value(): void
    {
        $this->seed(RoomCatalogueSeeder::class);

        foreach (['wealth', 'charm'] as $type) {
            WealthCharmLevel::create(['type' => $type, 'level' => 1, 'name_en' => "{$type} 1", 'threshold' => 500]);
            WealthCharmLevel::create(['type' => $type, 'level' => 2, 'name_en' => "{$type} 2", 'threshold' => 2_000]);
        }

        $sender = $this->makeUser('Sender');
        $receiver = $this->makeUser('Receiver');
        app(WalletService::class)->creditSystem($sender, Wallet::COIN, 10_000, 'test');

        $room = Room::create([
            'room_code' => 'LV'.random_int(100000, 999999), 'owner_id' => $receiver->id,
            'category_id' => RoomCategory::where('key', 'music')->value('id'), 'name' => 'Levels',
            'seat_count' => 5, 'status' => Room::LIVE, 'started_at' => now()->subHour(),
        ]);
        $category = GiftCategory::create(['key' => 'g', 'name_en' => 'G', 'sort_order' => 1]);
        // A diamond value unlike the coin price, so charm visibly follows coins, not diamonds.
        $rose = Gift::create(['code' => 'rose', 'name_en' => 'Rose', 'category_id' => $category->id, 'coin_price' => 10, 'diamond_value' => 3, 'tier' => 'basic', 'is_active' => true]);

        $gifts = app(GiftSendService::class);
        $gifts->send($sender, $receiver, $rose, 1, $room);

        $this->assertSame(10, Wallet::where('user_id', $sender->id)->value('lifetime_gift_coins_sent'));
        $this->assertSame(10, Wallet::where('user_id', $receiver->id)->value('lifetime_gift_coins_received'));

        // Still short of level 1 (500): "level 0", with 10 of 500 shown.
        $this->actingAsUser($sender);
        $this->getJson($this->base.'/progression')
            ->assertOk()
            ->assertJsonPath('data.wealth.level', null)
            ->assertJsonPath('data.wealth.lifetime_value', 10)
            ->assertJsonPath('data.wealth.next_level.threshold', 500);

        // 49 more roses: 500 sent — level 1 the moment the threshold is met.
        $gifts->send($sender, $receiver, $rose, 49, $room);
        $this->assertSame(1, Wallet::where('user_id', $sender->id)->first()->wealthLevel()?->level);
        $this->assertSame(1, Wallet::where('user_id', $receiver->id)->first()->charmLevel()?->level);
        // Sending does not raise the sender's charm, nor receiving the receiver's wealth.
        $this->assertNull(Wallet::where('user_id', $sender->id)->first()->charmLevel());
        $this->assertNull(Wallet::where('user_id', $receiver->id)->first()->wealthLevel());

        // The person card on chat lines, seats and posts carries both numbers for the medals.
        $card = SocialPresenter::user($sender->fresh());
        $this->assertSame([1, 0], [$card['wealth_level'], $card['charm_level']]);
        $card = SocialPresenter::user($receiver->fresh());
        $this->assertSame([0, 1], [$card['wealth_level'], $card['charm_level']]);

        // A gift outside a room (a DM) counts toward neither.
        $gifts->send($sender, $receiver, $rose, 100, null);
        $this->assertSame(500, Wallet::where('user_id', $sender->id)->value('lifetime_gift_coins_sent'));
        $this->assertSame(500, Wallet::where('user_id', $receiver->id)->value('lifetime_gift_coins_received'));
    }
}
