<?php

namespace App\Domain\Social;

use App\Models\Room;
use App\Models\SearchHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GFT-222 — search across people and rooms, plus each user's recent searches (epic D.3a).
 *
 * Two things about searching for people that are easy to get wrong here:
 *
 *  1. **`users.phone` is ciphertext.** docs/02 §2.1 encrypts it, so there is nothing to
 *     `LIKE`. The existing {@see User::scopeSearch()} already handles this by hashing the
 *     term — this service reuses it rather than writing a second, weaker one. The visible
 *     consequence is that a partial number finds nobody while a full one is instant, and
 *     that is correct, not a bug to work around.
 *
 *  2. **A blocked person must not be findable.** D.9c is not only about messaging; a block
 *     that still surfaces the person in search hands back the profile the block was hiding.
 */
class SearchService
{
    public const MIN_TERM = 2;

    public function __construct(protected SocialService $social)
    {
    }

    /**
     * @return array{users: Collection<int, User>, rooms: Collection<int, Room>}
     */
    public function search(?User $viewer, string $term, string $type, int $limit): array
    {
        $term = trim($term);

        $empty = ['users' => new Collection(), 'rooms' => new Collection()];

        if (mb_strlen($term) < self::MIN_TERM) {
            return $empty;
        }

        return [
            'users' => in_array($type, ['users', 'all'], true) ? $this->users($viewer, $term, $limit) : $empty['users'],
            'rooms' => in_array($type, ['rooms', 'all'], true) ? $this->rooms($term, $limit) : $empty['rooms'],
        ];
    }

    /** @return Collection<int, User> */
    public function users(?User $viewer, string $term, int $limit): Collection
    {
        $query = User::query()
            ->search($term)
            ->where('status', '!=', User::STATUS_DELETED)
            ->with('profile:id,user_id,display_name,avatar_url');

        if ($viewer !== null) {
            $query->whereKeyNot($viewer->id);
            $this->social->excludeBlocked($query, $viewer->id);
        }

        // Recently active first: in a people search the useful answer is almost always
        // somebody who is around, not whoever registered first.
        return $query->orderByDesc('last_active_at')->limit($limit)->get();
    }

    /** @return Collection<int, Room> */
    public function rooms(string $term, int $limit): Collection
    {
        return Room::query()
            ->where('visibility', 'public')
            ->whereIn('status', [Room::LIVE, Room::IDLE])
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('room_code', $term))
            ->with(['owner.profile:id,user_id,display_name,avatar_url', 'category:id,name_en'])
            // Live rooms above idle ones, then by how busy they are — the ordering the
            // `(status, listener_count)` index in docs/02 §14 exists for.
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [Room::LIVE])
            ->orderByDesc('listener_count')
            ->limit($limit)
            ->get();
    }

    // ------------------------------------------------------------------ history

    /**
     * Record a search. Re-searching the same thing moves the existing entry to the top
     * rather than adding a duplicate, and the list is trimmed to {@see SearchHistory::KEEP}.
     */
    public function remember(User $user, string $term, string $type = SearchHistory::TYPE_TERM, ?string $targetUuid = null): SearchHistory
    {
        $term = trim($term);

        return DB::transaction(function () use ($user, $term, $type, $targetUuid) {
            $entry = SearchHistory::updateOrCreate(
                ['user_id' => $user->id, 'type' => $type, 'term' => $term],
                ['target_uuid' => $targetUuid, 'searched_at' => now()],
            );

            // Trim by id of the survivors rather than "delete where searched_at < x": two
            // entries can share a timestamp to the second, and a cutoff would take both.
            $keepIds = SearchHistory::where('user_id', $user->id)
                ->orderByDesc('searched_at')
                ->orderByDesc('id')
                ->limit(SearchHistory::KEEP)
                ->pluck('id');

            SearchHistory::where('user_id', $user->id)->whereNotIn('id', $keepIds)->delete();

            return $entry;
        });
    }

    /** @return Collection<int, SearchHistory> */
    public function history(User $user): Collection
    {
        return SearchHistory::where('user_id', $user->id)
            ->orderByDesc('searched_at')
            ->orderByDesc('id')
            ->limit(SearchHistory::KEEP)
            ->get();
    }

    public function clearHistory(User $user): int
    {
        return SearchHistory::where('user_id', $user->id)->delete();
    }

    /** Scoped to the caller, so a guessed uuid still cannot delete somebody else's entry. */
    public function deleteHistoryEntry(User $user, string $uuid): int
    {
        return SearchHistory::where('user_id', $user->id)->where('uuid', $uuid)->delete();
    }
}
