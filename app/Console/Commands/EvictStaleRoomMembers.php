<?php

namespace App\Console\Commands;

use App\Domain\Rooms\RoomParticipationService;
use Illuminate\Console\Command;

/**
 * Takes people whose app went quiet (closed, killed, phone locked) out of their voice room and
 * off their seat — the server never hears about a killed app, so a missing heartbeat is the
 * only signal. The rooms API also runs this lazily, so a stalled scheduler delays the cleanup
 * by seconds rather than leaving ghosts on the mics.
 */
class EvictStaleRoomMembers extends Command
{
    protected $signature = 'rooms:evict-stale {--quiet-on-none : Print nothing when nobody was evicted}';

    protected $description = 'Remove room members (and their seats) whose heartbeat has gone quiet';

    public function handle(RoomParticipationService $rooms): int
    {
        $evicted = $rooms->evictStale();

        if ($evicted > 0 || ! $this->option('quiet-on-none')) {
            $this->info("Evicted {$evicted} stale room member(s).");
        }

        return self::SUCCESS;
    }
}
