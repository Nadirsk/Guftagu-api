<?php

namespace Tests\Feature\Api;

use App\Events\Rooms\GiftSent;
use App\Models\Gift;
use App\Models\GiftCategory;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomSeat;
use App\Models\Wallet;
use Database\Seeders\RoomCatalogueSeeder;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use App\Domain\Wallet\WalletService;

/** Phase 3 — one gift to everyone on a mic. */
class RoomGiftAllTest extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoomCatalogueSeeder::class);
    }

    protected function scene(int $coins): array
    {
        $room = Room::create([
            'room_code' => 'GA'.random_int(100000, 999999), 'owner_id' => $this->makeUser('Owner')->id,
            'category_id' => RoomCategory::where('key', 'music')->value('id'), 'name' => 'All gifts',
            'seat_count' => 5, 'status' => Room::LIVE, 'started_at' => now()->subHour(),
        ]);
        for ($n = 1; $n <= 5; $n++) {
            RoomSeat::create(['room_id' => $room->id, 'seat_number' => $n]);
        }
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');
        $sender = $this->actingAsUser($this->makeUser('Sender'));
        foreach ([$a, $b, $sender] as $i => $u) {
            $room->seats()->where('seat_number', $i + 1)->update(['user_id' => $u->id]);
        }
        app(WalletService::class)->creditSystem($sender, Wallet::COIN, $coins, 'test');
        $cat = GiftCategory::create(['key' => 'g', 'name_en' => 'G', 'sort_order' => 1]);
        $gift = Gift::create(['code' => 'apple', 'name_en' => 'Apple', 'category_id' => $cat->id, 'coin_price' => 10, 'diamond_value' => 5, 'tier' => 'basic']);

        return [$room, $gift, $sender];
    }

    #[Test]
    public function all_sends_the_gift_to_every_other_mic_in_one_batch(): void
    {
        Event::fake([GiftSent::class]);
        [$room, $gift, $sender] = $this->scene(100);

        $this->postJson("{$this->base}/rooms/{$room->uuid}/gifts", ['gift_id' => $gift->id, 'to_user' => 'all', 'quantity' => 2])
            ->assertOk()->assertJsonPath('data.all', true)->assertJsonCount(2, 'data.receivers')->assertJsonPath('data.coin_balance', 60);

        Event::assertDispatchedTimes(GiftSent::class, 2);
        $batches = collect(Event::dispatched(GiftSent::class))->map(fn ($e) => $e[0]->batch)->unique();
        $this->assertCount(1, $batches);
        $this->assertNotNull($batches->first());
    }

    #[Test]
    public function all_needs_the_coins_for_every_receiver(): void
    {
        [$room, $gift] = $this->scene(30);

        $this->postJson("{$this->base}/rooms/{$room->uuid}/gifts", ['gift_id' => $gift->id, 'to_user' => 'all', 'quantity' => 2])
            ->assertStatus(402);
    }
}
