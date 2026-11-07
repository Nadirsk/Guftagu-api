<?php

namespace Tests\Feature\Admin;

use App\Domain\Events\EventCampaignService;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventClaim;
use App\Models\EventRound;
use App\Models\Gift;
use App\Models\Role;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserStoreItem;
use App\Models\Wallet;
use Database\Seeders\EconomySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Weekly Star: Event Gifts score by coin value in 7-day rounds; finished rounds pay their Top 3. */
class WeeklyStarTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class, EconomySeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::SUPER_ADMIN)->value('id'), 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function makeUser(): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'WS'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198305'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 500000 + $seq,
        ]);
        UserProfile::create(['user_id' => $user->id, 'display_name' => "Sender {$seq}"]);
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    protected function makeGift(string $code, int $price): Gift
    {
        return Gift::create([
            'code' => $code, 'name_en' => ucfirst($code), 'coin_price' => $price, 'diamond_value' => $price,
            'thumbnail_url' => "https://cdn.test/{$code}.png",
        ]);
    }

    /** A gift sent at a given moment — straight into the ledger the board reads. */
    protected function send(User $from, Gift $gift, int $quantity, Carbon $at): void
    {
        $to = $this->makeUser();

        DB::table('gift_transactions')->insert([
            'sender_id' => $from->id, 'receiver_id' => $to->id, 'gift_id' => $gift->id,
            'quantity' => $quantity, 'coin_value' => $gift->coin_price * $quantity, 'created_at' => $at,
        ]);
    }

    /** A published two-week Weekly Star that started on `$startsOn`. */
    protected function makeWeeklyStar(string $startsOn, array $giftIds): Event
    {
        Carbon::setTestNow(Carbon::parse($startsOn)->subDay());

        $id = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/weekly-stars', ['title_en' => 'Weekly Star', 'starts_on' => $startsOn, 'weeks' => 2])
            ->assertCreated()
            ->json('data.id');

        $this->putJson($this->base."/weekly-stars/{$id}/gifts", ['gift_ids' => $giftIds])->assertOk();
        $this->postJson($this->base."/events/{$id}/publish")->assertOk();

        return Event::findOrFail($id);
    }

    #[Test]
    public function creating_one_sets_weeks_of_seven_days_and_the_three_top_cards(): void
    {
        $event = $this->makeWeeklyStar('2026-11-04', []);   // a Wednesday

        $this->assertSame('2026-11-04 00:00:00', $event->starts_at->toDateTimeString());
        $this->assertSame('2026-11-18 00:00:00', $event->ends_at->toDateTimeString());
        $this->assertSame(['Top 1', 'Top 2', 'Top 3'], $event->tiers()->pluck('label')->all());

        // Rounds run Wednesday to Tuesday night, counted from the start — not calendar weeks.
        [$start, $end] = app(EventCampaignService::class)->roundFor($event, Carbon::parse('2026-11-12 10:00'));
        $this->assertSame('2026-11-11 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-11-17 23:59:59', $end->toDateTimeString());
    }

    #[Test]
    public function only_the_event_gifts_score_and_only_inside_the_round(): void
    {
        $rose = $this->makeGift('rose', 100);
        $car = $this->makeGift('car', 5_000);
        $other = $this->makeGift('other', 99_999);
        $event = $this->makeWeeklyStar('2026-11-04', [$rose->id, $car->id]);

        [$a, $b, $c] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];

        $this->send($a, $rose, 10, Carbon::parse('2026-11-05 12:00'));   // 1,000
        $this->send($b, $car, 1, Carbon::parse('2026-11-06 12:00'));     // 5,000
        $this->send($c, $other, 1, Carbon::parse('2026-11-06 12:00'));   // not an Event Gift
        $this->send($a, $car, 9, Carbon::parse('2026-11-12 12:00'));     // next round

        $board = app(EventCampaignService::class)->windowedBoard($event, 10, Carbon::parse('2026-11-08'));

        $this->assertSame([$b->id, $a->id], $board->pluck('entity_id')->all());
        $this->assertSame([5_000, 1_000], $board->pluck('score')->all());
    }

    #[Test]
    public function a_finished_round_pays_its_top_three_automatically_and_only_once(): void
    {
        $rose = $this->makeGift('rose', 100);
        $frame = StoreItem::create(['type' => 'frame', 'name' => 'Star Frame', 'image_url' => 'https://cdn.test/star.png', 'coin_price' => 0, 'is_active' => true]);
        $event = $this->makeWeeklyStar('2026-11-04', [$rose->id]);

        $top1 = $event->tiers()->where('rank_from', 1)->first();
        $top2 = $event->tiers()->where('rank_from', 2)->first();
        $this->postJson($this->base."/weekly-stars/{$event->id}/tiers/{$top1->id}/rewards", ['reward_type' => 'coins', 'reward_value' => 15_000])->assertCreated();
        $this->postJson($this->base."/weekly-stars/{$event->id}/tiers/{$top1->id}/rewards", ['reward_type' => 'frame', 'ref_id' => $frame->id, 'duration_days' => 7])->assertCreated();
        $this->postJson($this->base."/weekly-stars/{$event->id}/tiers/{$top2->id}/rewards", ['reward_type' => 'coins', 'reward_value' => 10_000])->assertCreated();

        $users = collect(range(1, 4))->map(fn () => $this->makeUser());
        foreach ($users as $i => $user) {
            $this->send($user, $rose, 40 - $i * 10, Carbon::parse('2026-11-05 12:00'));   // 4000, 3000, 2000, 1000
        }

        // Mid-round, nothing is due.
        Carbon::setTestNow('2026-11-08 12:00');
        $this->artisan('events:settle-weekly-star')->assertSuccessful();
        $this->assertSame(0, EventRound::count());

        // After the first round closes it pays itself.
        Carbon::setTestNow('2026-11-11 00:10');
        $this->artisan('events:settle-weekly-star')->assertSuccessful();

        $round = EventRound::firstOrFail();
        $this->assertSame('2026-11-04 00:00:00', $round->period_start->toDateTimeString());
        $this->assertSame([$users[0]->id, $users[1]->id, $users[2]->id], array_column($round->standings, 'user_id'));
        $this->assertSame(15_000, Wallet::where('user_id', $users[0]->id)->value('coin_balance'));
        $this->assertSame(10_000, Wallet::where('user_id', $users[1]->id)->value('coin_balance'));
        $this->assertNotNull(UserStoreItem::where('user_id', $users[0]->id)->where('store_item_id', $frame->id)->first());
        // Top 3 has no rewards set, so a claim is recorded with nothing in it; rank 4 gets nothing.
        $this->assertSame(0, EventClaim::where('user_id', $users[3]->id)->count());

        // Re-running — scheduler or the manual button — pays nobody twice.
        $this->artisan('events:settle-weekly-star')->assertSuccessful();
        $this->postJson($this->base."/weekly-stars/{$event->id}/settle", ['period_start' => '2026-11-04T00:00:00Z'])->assertOk();
        $this->assertSame(15_000, Wallet::where('user_id', $users[0]->id)->value('coin_balance'));
        $this->assertSame(1, EventRound::count());

        // The round still running cannot be settled by hand.
        $this->postJson($this->base."/weekly-stars/{$event->id}/settle", ['period_start' => '2026-11-11T00:00:00Z'])->assertStatus(400);
    }

    #[Test]
    public function only_one_weekly_star_can_be_published_for_the_same_dates(): void
    {
        $this->makeWeeklyStar('2026-11-04', []);

        $second = $this->postJson($this->base.'/weekly-stars', ['title_en' => 'Second', 'starts_on' => '2026-11-10', 'weeks' => 1])
            ->assertCreated()->json('data.id');

        $this->postJson($this->base."/events/{$second}/publish")
            ->assertStatus(422)
            ->assertJsonPath('error.details.clashing_event_id', Event::where('title_en', 'Weekly Star')->value('id'));
    }

    #[Test]
    public function a_reward_must_point_at_an_item_of_its_type(): void
    {
        $bubble = StoreItem::create(['type' => 'bubble', 'name' => 'Bubble', 'coin_price' => 0, 'is_active' => true]);
        $event = $this->makeWeeklyStar('2026-11-04', []);
        $top1 = $event->tiers()->first();

        $this->postJson($this->base."/weekly-stars/{$event->id}/tiers/{$top1->id}/rewards", ['reward_type' => 'frame', 'ref_id' => $bubble->id, 'duration_days' => 7])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ref_id', 'error.details');

        $this->putJson($this->base."/weekly-stars/{$event->id}/gifts", ['gift_ids' => [1, 2, 3, 4]])
            ->assertStatus(422);
    }

    #[Test]
    public function the_app_gets_the_event_gifts_and_each_rewards_real_image(): void
    {
        $rose = $this->makeGift('rose', 100);
        $frame = StoreItem::create(['type' => 'frame', 'name' => 'Star Frame', 'image_url' => 'https://cdn.test/star.png', 'coin_price' => 0, 'is_active' => true]);
        $event = $this->makeWeeklyStar('2026-11-04', [$rose->id]);
        $top1 = $event->tiers()->first();
        $this->postJson($this->base."/weekly-stars/{$event->id}/tiers/{$top1->id}/rewards", ['reward_type' => 'frame', 'ref_id' => $frame->id, 'duration_days' => 7])->assertCreated();

        Carbon::setTestNow('2026-11-05 12:00');
        Sanctum::actingAs($this->makeUser(), ['*'], 'sanctum');

        $this->getJson('/api/v1/events')->assertOk()->assertJsonPath('data.0.uuid', $event->uuid);

        $this->getJson("/api/v1/events/{$event->uuid}")
            ->assertOk()
            ->assertJsonPath('data.event_gifts.0.price', 100)
            ->assertJsonPath('data.event_gifts.0.thumbnail_url', 'https://cdn.test/rose.png')
            ->assertJsonPath('data.tiers.0.rewards.0.catalog.image_url', 'https://cdn.test/star.png')
            ->assertJsonPath('data.cycle_ends_at', '2026-11-10T23:59:59Z');
    }
}
