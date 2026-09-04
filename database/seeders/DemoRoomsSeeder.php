<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\RoomSeatTemplate;
use App\Models\RoomTheme;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo rooms so the monitoring console has something to monitor.
 *
 * Local/testing only. Without the mobile app nothing creates rooms, and an empty grid
 * cannot show whether filters, the seat map or force-close actually work.
 *
 *     php artisan db:seed --class=DemoRoomsSeeder
 */
class DemoRoomsSeeder extends Seeder
{
    /** [name, category key, status, seats, listeners, featured] */
    public const ROOMS = [
        ['Late Night Ghazals', 'music', Room::LIVE, 9, 412, true],
        ['Mumbai Indie Sessions', 'music', Room::LIVE, 8, 236, false],
        ['Roast Me Friday', 'comedy', Room::LIVE, 5, 189, true],
        ['BGMI Squad Up', 'gaming', Room::LIVE, 12, 97, false],
        ['3AM Overthinkers', 'chat', Room::LIVE, 8, 64, false],
        ['Morning Aarti', 'devotional', Room::LIVE, 5, 51, false],
        ['UPSC Study Hall', 'study', Room::LIVE, 15, 33, false],
        ['Weekend Debate', 'news', Room::IDLE, 8, 0, false],
        ['Closed for spam', 'chat', Room::FORCE_CLOSED, 8, 0, false],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('DemoRoomsSeeder refuses to run outside local/testing.');

            return;
        }

        $owners = User::query()->where('status', User::STATUS_ACTIVE)->pluck('id')->all();

        if ($owners === []) {
            $this->command->error('No active users to own a room — run DemoUsersSeeder first.');

            return;
        }

        $categories = RoomCategory::query()->pluck('id', 'key');
        $themes = RoomTheme::query()->pluck('id')->all();

        if ($categories->isEmpty()) {
            $this->command->error('No room categories — run RoomCatalogueSeeder first.');

            return;
        }

        // Grouped by size, so a room picks whichever seeded template matches its own
        // seat count — the same thing RoomService::setSeatTemplate does, just inline
        // here since the room doesn't exist yet for that endpoint to act on.
        $templatesBySize = RoomSeatTemplate::query()->where('is_active', true)->get()->groupBy('total_seats');

        foreach (self::ROOMS as $index => [$name, $categoryKey, $status, $seats, $listeners, $featured]) {
            if (Room::query()->where('name', $name)->exists()) {
                $this->command->warn("{$name} already exists — left alone.");

                continue;
            }

            DB::transaction(function () use ($index, $name, $categoryKey, $status, $seats, $listeners, $featured, $owners, $categories, $themes, $templatesBySize) {
                $ownerId = $owners[$index % count($owners)];

                // Rotate through whichever templates match this seat count, so demo
                // rooms of the same size don't all look identical.
                $matches = $templatesBySize->get($seats, collect());
                $template = $matches->isEmpty() ? null : $matches[$index % $matches->count()];
                $vipPositions = $template?->vip_positions ?? [];

                $room = Room::create([
                    'room_code'   => 'RM'.str_pad((string) (1000 + $index), 6, '0', STR_PAD_LEFT),
                    'owner_id'    => $ownerId,
                    'category_id' => $categories[$categoryKey] ?? null,
                    'theme_id'    => $themes === [] ? null : $themes[$index % count($themes)],
                    'name'        => $name,
                    'description' => 'A demo room, seeded so the console has something to show.',
                    'visibility'  => 'public',
                    'seat_count'      => $seats,
                    'seat_layout'     => $seats > 9 ? 'party' : 'classic',
                    'seat_template_id' => $template?->id,
                    'status'      => $status,
                    'is_featured' => $featured,
                    // A window in the future, so the effective-featured logic is exercised.
                    'featured_until'          => $featured ? now()->addDay() : null,
                    'listener_count'          => $listeners,
                    'peak_listeners'          => (int) round($listeners * 1.4),
                    'total_diamonds_received' => $listeners * random_int(2, 40),
                    'started_at'              => $status === Room::LIVE ? now()->subMinutes(random_int(5, 300)) : null,
                    'ended_at'                => $status === Room::FORCE_CLOSED ? now()->subHours(2) : null,
                    'close_reason'            => $status === Room::FORCE_CLOSED ? 'Repeated spam links in room chat.' : null,
                ]);

                // Every room gets its full seat set; the first few are occupied on live ones.
                // Seats cap who can be *seated* — how many more are simply in the room
                // listening is a separate number, so the rest of the active users join too,
                // without a seat, as `role = listener`.
                $occupants = $status === Room::LIVE ? array_slice($owners, 0, min(4, count($owners))) : [];
                $listenersOnly = $status === Room::LIVE ? array_slice($owners, count($occupants)) : [];

                for ($seat = 1; $seat <= $seats; $seat++) {
                    $occupant = $occupants[$seat - 1] ?? null;

                    RoomSeat::create([
                        'room_id'     => $room->id,
                        'seat_number' => $seat,
                        'user_id'     => $occupant,
                        'is_locked'   => $seat === $seats && $status === Room::LIVE,
                        // Whichever positions the assigned seat template decided are VIP
                        // — never an independent guess. A room with no matching template
                        // simply has no VIP seats yet, same as a real one would.
                        'is_vip'      => in_array($seat, $vipPositions, true),
                        'occupied_at' => $occupant ? now()->subMinutes(random_int(1, 60)) : null,
                    ]);
                }

                foreach ($occupants as $position => $userId) {
                    RoomMember::create([
                        'room_id'   => $room->id,
                        'user_id'   => $userId,
                        'role'      => $position === 0 ? 'owner' : 'speaker',
                        'joined_at' => now()->subMinutes(random_int(1, 120)),
                        'is_active' => true,
                    ]);
                }

                foreach ($listenersOnly as $userId) {
                    RoomMember::create([
                        'room_id'   => $room->id,
                        'user_id'   => $userId,
                        'role'      => 'listener',
                        'joined_at' => now()->subMinutes(random_int(1, 120)),
                        'is_active' => true,
                    ]);
                }
            });

            $this->command->info(sprintf('%-24s %-12s %-14s %s', $name, $categoryKey, $status, $listeners.' listeners'));
        }
    }
}
