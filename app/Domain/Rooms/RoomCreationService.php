<?php

namespace App\Domain\Rooms;

use App\Domain\Store\InventoryService;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\RoomSeatTemplate;
use App\Models\RoomTheme;
use App\Models\User;
use App\Models\VipTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * D.2a — a host publishes a room (docs/03 §4, docs/04 D.2). Previously the only way a
 * `rooms` row came to exist was a seeder — there was no app-facing "create a room" flow at
 * all, which meant a host could not actually start hosting.
 */
class RoomCreationService
{
    public function __construct(protected InventoryService $inventory)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     * @throws RoomException
     */
    public function create(User $owner, array $data): Room
    {
        $category = null;

        if (! empty($data['category_id'])) {
            $category = RoomCategory::where('id', $data['category_id'])->where('is_active', true)->first();

            if ($category === null) {
                throw new RoomException('NOT_FOUND', 'That room category does not exist.', 404);
            }
        }

        $theme = null;

        if (! empty($data['theme_id'])) {
            $theme = RoomTheme::where('id', $data['theme_id'])->where('is_active', true)->first();

            if ($theme === null) {
                throw new RoomException('NOT_FOUND', 'That room theme does not exist.', 404);
            }

            if ($theme->required_vip_tier_id !== null && ! $this->meetsRequiredTier($owner, $theme->required_vip_tier_id)) {
                throw new RoomException('FORBIDDEN', 'Your VIP tier is not high enough for this theme.', 403);
            }
        }

        $template = null;

        if (! empty($data['seat_template_id'])) {
            $template = RoomSeatTemplate::where('id', $data['seat_template_id'])->where('is_active', true)->first();

            if ($template === null) {
                throw new RoomException('NOT_FOUND', 'That seat template does not exist.', 404);
            }
        }

        $visibility = $data['visibility'] ?? 'public';

        if ($visibility === 'private' && empty($data['password'])) {
            throw new RoomException('VALIDATION_ERROR', 'A password is required for a private room.', 422);
        }

        $seatCount = $template?->total_seats ?? (int) ($data['seat_count'] ?? 8);
        $vipPositions = $template?->vip_positions ?? [];

        return DB::transaction(function () use ($owner, $data, $category, $theme, $template, $visibility, $seatCount, $vipPositions) {
            $room = Room::create([
                'room_code'        => $this->generateRoomCode(),
                'owner_id'         => $owner->id,
                'category_id'      => $category?->id,
                'theme_id'         => $theme?->id,
                'seat_template_id' => $template?->id,
                'name'             => trim((string) $data['name']),
                'description'      => $data['description'] ?? null,
                'cover_url'        => $data['cover_url'] ?? null,
                'visibility'       => $visibility,
                'password_hash'    => $visibility === 'private' ? Hash::make($data['password']) : null,
                'seat_count'       => $seatCount,
                'video_enabled'    => (bool) ($data['video_enabled'] ?? false),
                'status'           => Room::LIVE,
                'started_at'       => now(),
            ]);

            for ($i = 1; $i <= $seatCount; $i++) {
                RoomSeat::create([
                    'room_id'     => $room->id,
                    'seat_number' => $i,
                    'is_vip'      => in_array($i, $vipPositions, true),
                ]);
            }

            RoomMember::create([
                'room_id'   => $room->id,
                'user_id'   => $owner->id,
                'role'      => RoomMember::OWNER,
                'joined_at' => now(),
                'is_active' => true,
            ]);

            // A host publishing a room is hosting it — seated on the floor from the start,
            // not standing in an empty room they have to then take a seat in themselves.
            $room->seats()->where('seat_number', 1)->update([
                'user_id'     => $owner->id,
                'occupied_at' => now(),
            ]);

            return $room->fresh(['owner.profile', 'category', 'theme', 'seats']);
        });
    }

    /** A theme's required tier is a floor, not an exact match — level 3 wears a level-2 theme fine. */
    protected function meetsRequiredTier(User $user, int $requiredTierId): bool
    {
        $required = VipTier::find($requiredTierId);
        $active = $this->inventory->activeVip($user);

        return $required !== null && $active !== null && $active->vipTier->level >= $required->level;
    }

    protected function generateRoomCode(): string
    {
        do {
            $candidate = 'RM'.str_pad((string) random_int(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT);
        } while (Room::query()->where('room_code', $candidate)->exists());

        return $candidate;
    }
}
