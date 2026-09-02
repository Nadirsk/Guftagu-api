<?php

namespace Database\Seeders;

use App\Domain\Events\EventService;
use App\Domain\Events\LuckyDrawService;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo events across every phase, so the builder and the reward flow are exercisable.
 *
 * Local/testing only. The phases are produced by choosing dates, not by setting a status —
 * which is also a demonstration that A.9a's transitions really are derived.
 *
 *     php artisan db:seed --class=DemoEventsSeeder
 */
class DemoEventsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('DemoEventsSeeder refuses to run outside local/testing.');

            return;
        }

        $users = User::query()->where('status', User::STATUS_ACTIVE)->limit(8)->get();

        if ($users->isEmpty()) {
            $this->command->error('No active users — run DemoUsersSeeder first.');

            return;
        }

        $events = app(EventService::class);
        $draws = app(LuckyDrawService::class);

        // [title, type, starts (days from now), ends, status]
        $definitions = [
            ['Diwali Gifting Marathon', 'event', -10, -3, Event::SCHEDULED],       // ended
            ['Weekend Singing Contest', 'tournament', -1, 2, Event::SCHEDULED],    // live
            ['New Year Lucky Draw', 'lucky_draw', 3, 10, Event::SCHEDULED],        // upcoming
            ['Republic Day Special', 'event', 20, 25, Event::DRAFT],               // draft
        ];

        foreach ($definitions as [$title, $type, $startDays, $endDays, $status]) {
            if (Event::query()->where('title_en', $title)->exists()) {
                $this->command->warn("{$title} already exists — left alone.");

                continue;
            }

            $event = Event::create([
                'type'        => $type,
                'title_en'    => $title,
                'description' => 'Seeded so the console has an event in this phase.',
                'entry_type'  => 'free',
                'starts_at'   => now()->addDays($startDays),
                'ends_at'     => now()->addDays($endDays),
                'status'      => $status,
                'is_featured' => $startDays < 0 && $endDays > 0,
            ]);

            if ($type === 'lucky_draw') {
                $draws->create($event, [
                    'draw_at'      => $event->ends_at,
                    'winner_count' => 3,
                    'algorithm'    => 'random',
                ]);
            }

            // The finished event gets reward bands and entrants, so the distribution flow
            // has something real to act on.
            if ($event->hasEnded()) {
                $event->rewards()->createMany([
                    ['rank_from' => 1, 'rank_to' => 1, 'reward_type' => 'coins', 'reward_value' => 10000],
                    ['rank_from' => 2, 'rank_to' => 3, 'reward_type' => 'coins', 'reward_value' => 5000],
                    ['rank_from' => 4, 'rank_to' => 10, 'reward_type' => 'coins', 'reward_value' => 1000],
                ]);
            }

            if ($status === Event::SCHEDULED) {
                foreach ($users as $index => $user) {
                    $events->join($event, $user, score: (8 - $index) * random_int(100, 400));
                }
            }

            $this->command->info(sprintf('%-28s %-12s %s', $title, $type, $event->phase()));
        }

        $this->command->info('Phases come from the dates, not from a status column.');
    }
}
