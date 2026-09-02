<?php

namespace Tests\Feature\Admin;

use App\Domain\Events\EventService;
use App\Domain\Events\LeaderboardService;
use App\Domain\Events\LuckyDrawService;
use App\Domain\Wallet\WalletService;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventRewardClaim;
use App\Models\LeaderboardSnapshot;
use App\Models\LuckyDraw;
use App\Models\RankingReward;
use App\Models\RankingRewardPayout;
use App\Models\RankingRule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use Database\Seeders\EconomySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RankingRuleSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Epic A.9 acceptance criteria. */
class EventsRankingsTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class,
            EconomySeeder::class, RankingRuleSeeder::class,
        ]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::SUPER_ADMIN)->value('id'), 'status' => 'active',
        ]);
    }

    protected function makeUser(int $coinsSpent = 0, int $diamondsEarned = 0): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198204'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 400000 + $seq,
        ]);

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Player {$seq}"]);

        Wallet::create([
            'user_id'                  => $user->id,
            'lifetime_coins_spent'     => $coinsSpent,
            'lifetime_diamonds_earned' => $diamondsEarned,
        ]);

        return $user;
    }

    protected function makeEvent(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'type'      => 'event',
            'title_en'  => 'Test Event',
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->addDay(),
            'status'    => Event::SCHEDULED,
        ], $attributes));
    }

    // -------------------------------------------------------------------- A.9a

    #[Test]
    public function an_event_moves_through_its_phases_with_no_manual_step(): void
    {
        $event = $this->makeEvent([
            'starts_at' => now()->addHour(),
            'ends_at'   => now()->addHours(3),
        ]);

        $this->assertSame(Event::UPCOMING, $event->phase());

        // Nothing runs between these assertions — no job, no scheduler, no status write.
        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(Event::LIVE, $event->fresh()->phase());

        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame(Event::ENDED, $event->fresh()->phase());

        Carbon::setTestNow();
    }

    #[Test]
    public function the_phase_filters_match_what_the_reader_would_compute(): void
    {
        $this->makeEvent(['title_en' => 'Later', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3)]);
        $this->makeEvent(['title_en' => 'Now', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $this->makeEvent(['title_en' => 'Over', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        $this->makeEvent(['title_en' => 'Unpublished', 'status' => Event::DRAFT]);

        foreach ([['live', 'Now'], ['upcoming', 'Later'], ['ended', 'Over'], ['draft', 'Unpublished']] as [$phase, $expected]) {
            $titles = collect(
                $this->actingAs($this->superAdmin, 'sanctum-admin')
                    ->getJson($this->base."/events?phase={$phase}")->assertOk()->json('data')
            )->pluck('title_en')->all();

            $this->assertSame([$expected], $titles, "Filtering by {$phase} returned the wrong set.");
        }
    }

    #[Test]
    public function a_cancelled_event_stays_cancelled_regardless_of_the_clock(): void
    {
        $event = $this->makeEvent(['status' => Event::CANCELLED]);

        // Operator intent beats the clock — otherwise cancelling a running event would
        // appear to do nothing.
        $this->assertSame(Event::CANCELLED, $event->phase());
    }

    #[Test]
    public function a_finished_events_dates_cannot_be_moved(): void
    {
        $event = $this->makeEvent(['starts_at' => now()->subDays(5), 'ends_at' => now()->subDay()]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/events/{$event->id}", ['ends_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(400);
    }

    // -------------------------------------------------------------------- A.9b

    #[Test]
    public function exactly_the_banded_ranks_are_rewarded_and_only_once(): void
    {
        // A.9b verbatim: bands for 1–3 and 4–10, fifty participants, ten winners.
        $event = $this->makeEvent(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);

        $event->rewards()->createMany([
            ['rank_from' => 1, 'rank_to' => 3, 'reward_type' => 'coins', 'reward_value' => 10_000],
            ['rank_from' => 4, 'rank_to' => 10, 'reward_type' => 'coins', 'reward_value' => 1_000],
        ]);

        $events = app(EventService::class);

        for ($i = 0; $i < 50; $i++) {
            $events->join($event, $this->makeUser(), score: 5_000 - $i);
        }

        $result = $events->distributeRewards($event, $this->superAdmin);

        $this->assertSame(10, $result['eligible'], 'Only ranks covered by a band are eligible.');
        $this->assertSame(10, $result['granted']);
        $this->assertSame(10, EventRewardClaim::where('event_id', $event->id)->count());

        // The right band for the right rank.
        $first = EventRewardClaim::where('event_id', $event->id)->where('rank', 1)->first();
        $fifth = EventRewardClaim::where('event_id', $event->id)->where('rank', 5)->first();

        $this->assertSame(10_000, $first->reward->reward_value);
        $this->assertSame(1_000, $fifth->reward->reward_value);

        // And the coins actually arrived.
        $topUser = User::find($first->user_id);
        $this->assertSame(10_000, Wallet::where('user_id', $topUser->id)->value('coin_balance'));
    }

    #[Test]
    public function distributing_twice_pays_nothing_further(): void
    {
        $event = $this->makeEvent(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        $event->rewards()->create(['rank_from' => 1, 'rank_to' => 2, 'reward_type' => 'coins', 'reward_value' => 500]);

        $events = app(EventService::class);

        foreach ([300, 200, 100] as $score) {
            $events->join($event, $this->makeUser(), score: $score);
        }

        $first = $events->distributeRewards($event, $this->superAdmin);
        $second = $events->distributeRewards($event, $this->superAdmin);

        $this->assertSame(2, $first['granted']);
        $this->assertSame(0, $second['granted'], 'A second run must pay nothing.');
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(2, EventRewardClaim::where('event_id', $event->id)->count());
    }

    #[Test]
    public function rewards_cannot_be_handed_out_before_the_event_ends(): void
    {
        $event = $this->makeEvent();   // live right now
        $event->rewards()->create(['rank_from' => 1, 'rank_to' => 1, 'reward_type' => 'coins', 'reward_value' => 100]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/events/{$event->id}/distribute")
            ->assertStatus(400);
    }

    #[Test]
    public function overlapping_reward_bands_are_refused(): void
    {
        $event = $this->makeEvent();
        $event->rewards()->create(['rank_from' => 1, 'rank_to' => 5, 'reward_type' => 'coins', 'reward_value' => 100]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/events/{$event->id}/rewards", [
                'rank_from' => 3, 'rank_to' => 8, 'reward_type' => 'coins', 'reward_value' => 50,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.overlapping.rank_from', 1);
    }

    #[Test]
    public function a_quantity_cap_limits_how_many_of_a_band_are_paid(): void
    {
        $event = $this->makeEvent(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        $event->rewards()->create([
            'rank_from' => 1, 'rank_to' => 10, 'reward_type' => 'coins', 'reward_value' => 100, 'quantity' => 2,
        ]);

        $events = app(EventService::class);

        foreach ([500, 400, 300, 200] as $score) {
            $events->join($event, $this->makeUser(), score: $score);
        }

        $result = $events->distributeRewards($event, $this->superAdmin);

        $this->assertSame(2, $result['granted'], 'The cap holds.');
        $this->assertSame(2, $result['skipped']);
    }

    // ------------------------------------------------------- lucky draw (A.9a)

    #[Test]
    public function the_seed_is_committed_before_the_draw_and_revealed_after(): void
    {
        $event = $this->makeEvent([
            'type' => 'lucky_draw', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subHour(),
        ]);

        $draw = app(LuckyDrawService::class)->create($event, [
            'draw_at' => now()->subMinute(), 'winner_count' => 2,
        ]);

        // Before: the hash is public, the seed is not — not even through the API.
        $this->assertNotEmpty($draw->seed_hash);
        $this->assertNull($draw->revealedSeed());
        $this->assertArrayNotHasKey('seed', $draw->toArray());

        $events = app(EventService::class);
        foreach (range(1, 6) as $i) {
            $events->join($event, $this->makeUser());
        }

        $drawn = app(LuckyDrawService::class)->draw($draw, $this->superAdmin);

        // After: the seed is out, and it matches the commitment made beforehand.
        $this->assertNotNull($drawn->revealedSeed());
        $this->assertSame($drawn->seed_hash, hash('sha256', $drawn->revealedSeed()));
        $this->assertCount(2, $drawn->result['winners']);
    }

    #[Test]
    public function the_published_result_is_reproducible_from_the_seed(): void
    {
        $event = $this->makeEvent([
            'type' => 'lucky_draw', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subHour(),
        ]);

        $draw = app(LuckyDrawService::class)->create($event, ['draw_at' => now()->subMinute(), 'winner_count' => 3]);

        $events = app(EventService::class);
        foreach (range(1, 10) as $i) {
            $events->join($event, $this->makeUser());
        }

        $drawn = app(LuckyDrawService::class)->draw($draw, $this->superAdmin);

        // This is the check an outsider would run: hash the seed, recompute the winners.
        $verification = LuckyDrawService::verify($drawn);

        $this->assertTrue($verification['hash_matches']);
        $this->assertTrue($verification['winners_match']);
        $this->assertTrue($verification['valid']);
        $this->assertSame($drawn->result['winners'], $verification['recomputed']);
    }

    #[Test]
    public function selection_is_pure_and_stable_for_a_given_seed(): void
    {
        $ids = [11, 22, 33, 44, 55, 66];

        $a = LuckyDrawService::selectWinners('a-fixed-seed', $ids, 3);
        $b = LuckyDrawService::selectWinners('a-fixed-seed', $ids, 3);
        $c = LuckyDrawService::selectWinners('a-different-seed', $ids, 3);

        $this->assertSame($a, $b, 'The same seed must always give the same winners.');
        $this->assertNotSame($a, $c, 'A different seed should give a different answer.');

        // Input order must not matter — the function sorts before selecting.
        $this->assertSame($a, LuckyDrawService::selectWinners('a-fixed-seed', array_reverse($ids), 3));
    }

    #[Test]
    public function a_draw_cannot_be_run_early_or_twice(): void
    {
        $event = $this->makeEvent(['type' => 'lucky_draw']);
        $draw = app(LuckyDrawService::class)->create($event, ['draw_at' => now()->addDay()]);

        app(EventService::class)->join($event, $this->makeUser());

        // Early would break the commitment: entries are still open.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/events/{$event->id}/draw")
            ->assertStatus(400);

        $draw->forceFill(['draw_at' => now()->subMinute()])->save();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/events/{$event->id}/draw")->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/events/{$event->id}/draw")
            ->assertStatus(400);
    }

    // -------------------------------------------------------------------- A.9c

    #[Test]
    public function the_threshold_excludes_users_regardless_of_rank_position(): void
    {
        $rule = RankingRule::where('key', 'wealth_daily')->first();   // min 1000 coins spent

        $this->makeUser(coinsSpent: 5_000);
        $this->makeUser(coinsSpent: 1_200);
        $below = $this->makeUser(coinsSpent: 900);

        $board = app(LeaderboardService::class)->board($rule);

        // Only two qualify, so a third place is open — and the sub-threshold user still
        // must not take it. Filtering after ranking would have let them.
        $this->assertCount(2, $board);
        $this->assertNotContains($below->id, $board->pluck('entity_id')->all());
    }

    #[Test]
    public function the_board_ranks_by_the_rules_metric(): void
    {
        $charm = RankingRule::where('key', 'charm_daily')->first();   // diamonds_earned, min 500

        $big = $this->makeUser(coinsSpent: 100_000, diamondsEarned: 600);
        $bigger = $this->makeUser(coinsSpent: 10, diamondsEarned: 9_000);

        $board = app(LeaderboardService::class)->board($charm);

        // Charm is diamonds, so the big spender does not lead it.
        $this->assertSame($bigger->id, $board->first()['entity_id']);
        $this->assertSame($big->id, $board->last()['entity_id']);
    }

    // -------------------------------------------------------------------- A.9d

    #[Test]
    public function a_snapshot_is_written_and_rewards_pay_once(): void
    {
        $rule = RankingRule::where('key', 'wealth_daily')->first();

        RankingReward::create(['rule_key' => $rule->key, 'rank_from' => 1, 'rank_to' => 1, 'reward_type' => 'coins', 'reward_value' => 5_000]);
        RankingReward::create(['rule_key' => $rule->key, 'rank_from' => 2, 'rank_to' => 3, 'reward_type' => 'coins', 'reward_value' => 2_000]);

        $winner = $this->makeUser(coinsSpent: 90_000);
        $this->makeUser(coinsSpent: 50_000);
        $this->makeUser(coinsSpent: 20_000);
        $this->makeUser(coinsSpent: 10_000);   // rank 4, no band

        $leaderboards = app(LeaderboardService::class);
        [$start] = $leaderboards->periodFor($rule);

        $rows = $leaderboards->snapshot($rule);
        $this->assertSame(4, $rows);
        $this->assertSame(4, LeaderboardSnapshot::where('rule_key', $rule->key)->count());

        $first = $leaderboards->payRewards($rule, $start, $this->superAdmin);

        $this->assertSame(3, $first['paid'], 'Only the three banded ranks are paid.');
        $this->assertSame(9_000, $first['total_value']);
        $this->assertSame(5_000, Wallet::where('user_id', $winner->id)->value('coin_balance'));

        // A.9d — re-running pays nothing further.
        $second = $leaderboards->payRewards($rule, $start, $this->superAdmin);

        $this->assertSame(0, $second['paid']);
        $this->assertSame(3, $second['skipped']);
        $this->assertSame(3, RankingRewardPayout::count());
        $this->assertSame(5_000, Wallet::where('user_id', $winner->id)->value('coin_balance'));
    }

    #[Test]
    public function re_snapshotting_a_period_replaces_it_rather_than_duplicating(): void
    {
        $rule = RankingRule::where('key', 'wealth_daily')->first();

        $this->makeUser(coinsSpent: 5_000);

        $leaderboards = app(LeaderboardService::class);
        $leaderboards->snapshot($rule);

        $this->makeUser(coinsSpent: 9_000);
        $leaderboards->snapshot($rule);

        [$start] = $leaderboards->periodFor($rule);

        $this->assertSame(
            2,
            LeaderboardSnapshot::where('rule_key', $rule->key)->where('period_start', $start->toDateString())->count(),
            'The period should hold one row per rank, not two sets.',
        );
    }

    #[Test]
    public function paying_rewards_without_a_snapshot_is_refused(): void
    {
        $rule = RankingRule::where('key', 'charm_weekly')->first();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/ranking-rules/{$rule->id}/pay-rewards")
            ->assertStatus(404);
    }

    // ------------------------------------------------------------ route gating

    #[Test]
    public function a_moderator_cannot_reach_events_or_rankings(): void
    {
        $moderator = AdminUser::create([
            'name' => 'Mod', 'email' => 'mod@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MODERATOR)->value('id'), 'status' => 'active',
        ]);

        foreach (['/events', '/ranking-rules'] as $path) {
            $this->actingAs($moderator, 'sanctum-admin')
                ->getJson($this->base.$path)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'PERMISSION_DENIED');
        }
    }

    #[Test]
    public function scheduling_an_event_and_giving_away_coins_are_separate_permissions(): void
    {
        $admin = AdminUser::create([
            'name' => 'Ops', 'email' => 'ops@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::ADMIN)->value('id'), 'status' => 'active',
        ]);

        app(\App\Domain\Access\Services\MfaReauthGate::class)->markSatisfied($this->superAdmin);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$admin->id}/permissions/deny", ['permissions' => ['events.reward_manage']])
            ->assertOk();

        $event = $this->makeEvent(['status' => Event::DRAFT]);

        // Still allowed to run the event ...
        $this->actingAs($admin->fresh(), 'sanctum-admin')
            ->patchJson($this->base."/events/{$event->id}", ['title_en' => 'Renamed'])
            ->assertOk();

        // ... but not to configure who gets paid.
        $this->actingAs($admin->fresh(), 'sanctum-admin')
            ->postJson($this->base."/events/{$event->id}/rewards", [
                'rank_from' => 1, 'rank_to' => 1, 'reward_type' => 'coins', 'reward_value' => 100,
            ])
            ->assertStatus(403);
    }
}
