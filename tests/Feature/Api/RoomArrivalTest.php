<?php

namespace Tests\Feature\Api;

use App\Domain\Store\InventoryService;
use App\Events\Rooms\EntranceEffectPlayed;
use App\Events\Rooms\MemberJoined;
use App\Events\Rooms\MemberLeft;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomSeat;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\VipTier;
use Database\Seeders\RoomCatalogueSeeder;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/** Phase 1 of the room animations — arrivals, departures, equipped entry banner/effect. */
class RoomArrivalTest extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoomCatalogueSeeder::class);
    }

    protected function makeRoom(): Room
    {
        static $seq = 0;
        $seq++;

        $room = Room::create([
            'room_code'   => 'RC'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'owner_id'    => $this->makeUser('Owner')->id,
            'category_id' => RoomCategory::where('key', 'music')->value('id'),
            'name'        => 'Arrivals',
            'seat_count'  => 5,
            'status'      => Room::LIVE,
            'started_at'  => now()->subHour(),
        ]);

        for ($n = 1; $n <= 5; $n++) {
            RoomSeat::create(['room_id' => $room->id, 'seat_number' => $n]);
        }

        return $room;
    }

    protected function item(string $type, string $name): StoreItem
    {
        return StoreItem::create([
            'type'           => $type,
            'name'           => $name,
            'image_url'      => "https://cdn.test/{$name}.png",
            'animation_url'  => $type === StoreItem::TYPE_ENTRANCE_EFFECT ? "https://cdn.test/{$name}.svga" : null,
            'animation_type' => $type === StoreItem::TYPE_ENTRANCE_EFFECT ? 'svga' : null,
            'duration_ms'    => $type === StoreItem::TYPE_ENTRANCE_EFFECT ? 4000 : null,
            'coin_price'     => 100,
            'rental_days'    => 7,
        ]);
    }

    #[Test]
    public function a_plain_arrival_announces_the_member_but_plays_no_effect(): void
    {
        Event::fake([MemberJoined::class, EntranceEffectPlayed::class]);
        $room = $this->makeRoom();
        $me = $this->actingAsUser($this->makeUser('Plain'));

        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")
            ->assertOk()
            ->assertJsonPath('data.my.arrival', null);

        Event::assertDispatched(MemberJoined::class, fn (MemberJoined $e) => $e->user->is($me) && $e->listenerCount === 1);
        Event::assertNotDispatched(EntranceEffectPlayed::class);
    }

    #[Test]
    public function rejoining_while_already_inside_announces_nothing(): void
    {
        $room = $this->makeRoom();
        $this->actingAsUser($this->makeUser());
        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")->assertOk();

        Event::fake([MemberJoined::class]);
        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")->assertOk();

        Event::assertNotDispatched(MemberJoined::class);
    }

    #[Test]
    public function an_equipped_banner_and_effect_play_for_the_room_and_come_back_to_the_joiner(): void
    {
        Event::fake([MemberJoined::class, EntranceEffectPlayed::class]);
        $room = $this->makeRoom();
        $me = $this->actingAsUser($this->makeUser('Racer'));

        $car = $this->item(StoreItem::TYPE_ENTRANCE_EFFECT, 'Lamborghini');
        $banner = $this->item(StoreItem::TYPE_ENTRY_BANNER, 'Wolf');
        $inventory = app(InventoryService::class);
        $inventory->grantStoreItem($me, $car, 7, 'purchase');
        $inventory->grantStoreItem($me, $banner, 7, 'purchase');

        $this->postJson("{$this->base}/store/items/{$car->id}/equip")->assertOk();
        $this->postJson("{$this->base}/store/items/{$banner->id}/equip")->assertOk();

        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")
            ->assertOk()
            ->assertJsonPath('data.my.arrival.effect.name', 'Lamborghini')
            ->assertJsonPath('data.my.arrival.effect.duration_ms', 4000)
            ->assertJsonPath('data.my.arrival.banner.name', 'Wolf')
            ->assertJsonPath('data.my.arrival.vip', null);

        Event::assertDispatched(EntranceEffectPlayed::class, function (EntranceEffectPlayed $e) use ($me) {
            $payload = $e->broadcastWith();

            return $e->broadcastAs() === 'entrance.effect'
                && $payload['user']['uuid'] === $me->uuid
                && $payload['effect']['animation_url'] === 'https://cdn.test/Lamborghini.svga';
        });
    }

    #[Test]
    public function a_vip_with_nothing_equipped_still_gets_the_vip_banner(): void
    {
        Event::fake([MemberJoined::class, EntranceEffectPlayed::class]);
        $room = $this->makeRoom();
        $me = $this->actingAsUser($this->makeUser());
        $tier = VipTier::create(['level' => 4, 'name_en' => 'VIP4']);
        app(InventoryService::class)->grantVip($me, $tier, 30);

        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")
            ->assertOk()
            ->assertJsonPath('data.my.arrival.vip.level', 4)
            ->assertJsonPath('data.my.arrival.effect', null);

        Event::assertDispatched(EntranceEffectPlayed::class);
    }

    #[Test]
    public function equipping_takes_off_the_previous_item_of_the_same_type_only(): void
    {
        $me = $this->actingAsUser($this->makeUser());
        $inventory = app(InventoryService::class);
        $first = $this->item(StoreItem::TYPE_ENTRANCE_EFFECT, 'First');
        $second = $this->item(StoreItem::TYPE_ENTRANCE_EFFECT, 'Second');
        $frame = $this->item(StoreItem::TYPE_FRAME, 'Crown');
        foreach ([$first, $second, $frame] as $item) {
            $inventory->grantStoreItem($me, $item, 7, 'purchase');
        }

        $this->postJson("{$this->base}/store/items/{$frame->id}/equip")->assertOk();
        $this->postJson("{$this->base}/store/items/{$first->id}/equip")->assertOk();
        $this->postJson("{$this->base}/store/items/{$second->id}/equip")->assertOk();

        $equipped = collect($this->getJson("{$this->base}/store/items/mine")->json('data'))
            ->where('equipped', true)->pluck('name')->sort()->values()->all();
        $this->assertSame(['Crown', 'Second'], $equipped);

        $this->deleteJson("{$this->base}/store/items/{$second->id}/equip")->assertOk();
        $this->assertNull($inventory->equipped($me, StoreItem::TYPE_ENTRANCE_EFFECT));
    }

    #[Test]
    public function an_item_you_do_not_own_or_that_expired_cannot_be_equipped(): void
    {
        $me = $this->actingAsUser($this->makeUser());
        $item = $this->item(StoreItem::TYPE_FRAME, 'Crown');

        $this->postJson("{$this->base}/store/items/{$item->id}/equip")->assertStatus(422);

        app(InventoryService::class)->grantStoreItem($me, $item, 7, 'purchase');
        $this->travel(8)->days();

        $this->postJson("{$this->base}/store/items/{$item->id}/equip")->assertStatus(422);
    }

    #[Test]
    public function an_expired_equipped_effect_stops_playing(): void
    {
        $me = $this->makeUser();
        $inventory = app(InventoryService::class);
        $car = $this->item(StoreItem::TYPE_ENTRANCE_EFFECT, 'Car');
        $inventory->grantStoreItem($me, $car, 7, 'purchase');
        $inventory->equip($me, $car);

        $this->travel(8)->days();

        $this->assertNull($inventory->arrival($me));
    }

    #[Test]
    public function leaving_announces_the_new_listener_count(): void
    {
        $room = $this->makeRoom();
        $me = $this->actingAsUser($this->makeUser());
        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")->assertOk();

        Event::fake([MemberLeft::class]);
        $this->postJson("{$this->base}/rooms/{$room->uuid}/leave")->assertOk();
        $this->postJson("{$this->base}/rooms/{$room->uuid}/leave")->assertOk();

        Event::assertDispatchedTimes(MemberLeft::class, 1);
        Event::assertDispatched(MemberLeft::class, fn (MemberLeft $e) => $e->user->is($me) && $e->listenerCount === 0);
    }

    #[Test]
    public function a_socket_failure_does_not_fail_the_join(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.port' => 1]);
        $room = $this->makeRoom();
        $this->actingAsUser($this->makeUser());

        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")->assertOk();

        $this->assertSame(1, $room->activeMembers()->count());
    }

    #[Test]
    public function a_seat_carries_what_its_occupant_was_sent_in_this_room_only(): void
    {
        $room = $this->makeRoom();
        $other = $this->makeRoom();
        $me = $this->actingAsUser($this->makeUser('Seated'));
        $sender = $this->makeUser('Sender');

        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")->assertOk();
        $this->postJson("{$this->base}/rooms/{$room->uuid}/seats/2/take")->assertOk();

        $category = \App\Models\GiftCategory::create(['key' => 'general', 'name_en' => 'General', 'sort_order' => 1]);
        $gift = \App\Models\Gift::create(['code' => 'rose', 'name_en' => 'Rose', 'category_id' => $category->id, 'coin_price' => 10, 'diamond_value' => 7, 'tier' => 'basic']);
        foreach ([[$room, 30], [$room, 120], [$other, 999]] as [$where, $coins]) {
            \App\Models\GiftTransaction::create([
                'sender_id' => $sender->id, 'receiver_id' => $me->id, 'room_id' => $where->id,
                'gift_id' => $gift->id, 'gift_category_id' => $category->id, 'quantity' => 1, 'coin_value' => $coins,
            ]);
        }

        $seats = collect($this->getJson("{$this->base}/rooms/{$room->uuid}/state")->assertOk()->json('data.seats'));
        $this->assertSame(150, $seats->firstWhere('seat_number', 2)['gift_value']);
        $this->assertSame(0, $seats->firstWhere('seat_number', 1)['gift_value']);
    }
}
