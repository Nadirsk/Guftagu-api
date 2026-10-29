<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The app's Create Room screen (Figma 397:2803).
 *
 * - `rooms.background_url`: the "+" tile under Room Background lets a host upload their
 *   own picture instead of picking a theme, so a room can carry a background no theme has.
 * - Room Tag: the screen offers Chat, Birthday, Party, Singing, Game, Friend, Emotion,
 *   Football and DJ ("All" means no tag). They are added as categories alongside the
 *   existing ones, which stay as they are — rooms already filed under them keep working.
 */
return new class extends Migration
{
    private const TAGS = [
        'chat' => 'Chat', 'birthday' => 'Birthday', 'party' => 'Party', 'singing' => 'Singing',
        'game' => 'Game', 'friend' => 'Friend', 'emotion' => 'Emotion', 'football' => 'Football', 'dj' => 'DJ',
    ];

    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('background_url', 500)->nullable()->after('cover_url');
        });

        $order = (int) DB::table('room_categories')->max('sort_order');
        foreach (self::TAGS as $key => $name) {
            if (DB::table('room_categories')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('room_categories')->insert([
                'key' => $key, 'name_en' => $name, 'sort_order' => ++$order, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('background_url');
        });

        // Only the tags no room has been filed under; a used one stays so its rooms keep it.
        DB::table('room_categories')
            ->whereIn('key', array_keys(self::TAGS))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rooms')->whereColumn('rooms.category_id', 'room_categories.id'))
            ->delete();
    }
};
