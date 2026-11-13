<?php

namespace App\Domain\Rooms;

use App\Domain\Wallet\WalletException;
use App\Domain\Wallet\WalletService;
use App\Events\Rooms\EnvelopeClaimed;
use App\Events\Rooms\EnvelopeCreated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomRedEnvelope;
use App\Models\RoomRedEnvelopeClaim;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Red packets in a room. The sender pays the whole amount up front; it is split between
 * `count` people, first come first served — each opener gets one random share (at least a
 * coin), credited to their wallet in the same transaction as the claim. After `count` shares
 * the packet is gone for everyone. Whatever nobody claimed within {@see self::LIFETIME_MINUTES}
 * goes back to the sender.
 */
class RoomEnvelopeService
{
    public const LIFETIME_MINUTES = 10;
    public const MAX_COUNT = 100;

    public function __construct(protected WalletService $wallets)
    {
    }

    /** @throws RoomException */
    public function create(Room $room, User $sender, int $coins, int $count): RoomRedEnvelope
    {
        if ($room->isClosed()) {
            throw new RoomException('ROOM_CLOSED', 'This room has ended.', 410);
        }

        $this->assertMember($room, $sender);

        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new RoomException('VALIDATION_ERROR', 'A red packet is for 1 to '.self::MAX_COUNT.' people.', 422);
        }

        if ($coins < $count) {
            throw new RoomException('VALIDATION_ERROR', 'Each person needs at least one coin.', 422);
        }

        $envelope = DB::transaction(function () use ($room, $sender, $coins, $count) {
            try {
                $this->wallets->debitSystem($sender, Wallet::COIN, $coins, 'red_envelope_send');
            } catch (WalletException $e) {
                throw new RoomException('INSUFFICIENT_COINS', 'Not enough coins for that red packet.', 402);
            }

            return RoomRedEnvelope::create([
                'uuid'            => (string) Str::uuid(),
                'room_id'         => $room->id,
                'sender_id'       => $sender->id,
                'total_coins'     => $coins,
                'total_count'     => $count,
                'remaining_coins' => $coins,
                'remaining_count' => $count,
                'expires_at'      => now()->addMinutes(self::LIFETIME_MINUTES),
            ]);
        });

        $envelope->setRelation('sender', $sender);
        EnvelopeCreated::dispatch($room, $envelope);

        return $envelope;
    }

    /**
     * Opens a packet for `$user`.
     *
     * @return array{amount: int, envelope: RoomRedEnvelope, claims: \Illuminate\Support\Collection}
     *
     * @throws RoomException
     */
    public function claim(Room $room, string $envelopeUuid, User $user): array
    {
        $this->assertMember($room, $user);
        $this->refundExpired($room);

        [$envelope, $amount] = DB::transaction(function () use ($room, $envelopeUuid, $user) {
            $envelope = RoomRedEnvelope::query()
                ->where('room_id', $room->id)
                ->where('uuid', $envelopeUuid)
                ->lockForUpdate()
                ->first();

            if ($envelope === null) {
                throw new RoomException('NOT_FOUND', 'That red packet is gone.', 404);
            }

            // The sender's coins go to the room, not back to the sender.
            if ((int) $envelope->sender_id === (int) $user->id) {
                throw new RoomException('OWN_ENVELOPE', 'You cannot open your own red packet.', 403);
            }

            if (RoomRedEnvelopeClaim::where('envelope_id', $envelope->id)->where('user_id', $user->id)->exists()) {
                throw new RoomException('ALREADY_CLAIMED', 'You have already received this red packet.', 409);
            }

            if ($envelope->remaining_count < 1) {
                throw new RoomException('ENVELOPE_EMPTY', 'Already received — all the shares of this red packet are taken.', 410);
            }

            $amount = $this->share((int) $envelope->remaining_coins, (int) $envelope->remaining_count);

            RoomRedEnvelopeClaim::create(['envelope_id' => $envelope->id, 'user_id' => $user->id, 'amount' => $amount]);
            $envelope->decrement('remaining_count');
            $envelope->decrement('remaining_coins', $amount);
            $envelope->refresh();

            $this->wallets->creditSystem($user, Wallet::COIN, $amount, 'red_envelope_receive');

            return [$envelope, $amount];
        });

        EnvelopeClaimed::dispatch($room, $envelope, $user, $amount);

        return ['amount' => $amount, 'envelope' => $envelope, 'claims' => $this->claimsOf($envelope)];
    }

    /** Packets still open that this person has not opened and did not send, oldest first — what a late arrival should see. */
    public function openFor(Room $room, User $viewer)
    {
        $this->refundExpired($room);

        return RoomRedEnvelope::query()
            ->where('room_id', $room->id)
            ->where('remaining_count', '>', 0)
            ->where('sender_id', '!=', $viewer->id)
            ->whereDoesntHave('claims', fn ($q) => $q->where('user_id', $viewer->id))
            ->with('sender.profile:id,user_id,display_name,avatar_url,gender,country')
            ->orderBy('id')
            ->get();
    }

    public function claimsOf(RoomRedEnvelope $envelope)
    {
        return $envelope->claims()->with('user.profile:id,user_id,display_name,avatar_url,gender,country')->orderBy('id')->get();
    }

    /** Random share: 1 .. about twice the average, always leaving one coin for each person still to come. */
    protected function share(int $coinsLeft, int $peopleLeft): int
    {
        if ($peopleLeft <= 1) {
            return $coinsLeft;
        }

        $max = $coinsLeft - ($peopleLeft - 1);
        $ceiling = min($max, (int) floor(2 * $coinsLeft / $peopleLeft));

        return random_int(1, max(1, $ceiling));
    }

    /** Unclaimed coins of packets past their time go back to whoever sent them. */
    protected function refundExpired(Room $room): void
    {
        $expired = RoomRedEnvelope::query()
            ->where('room_id', $room->id)
            ->where('remaining_count', '>', 0)
            ->where('expires_at', '<', now())
            ->pluck('id');

        foreach ($expired as $id) {
            DB::transaction(function () use ($id) {
                $envelope = RoomRedEnvelope::whereKey($id)->lockForUpdate()->first();

                if ($envelope === null || $envelope->remaining_count < 1) {
                    return;
                }

                $left = (int) $envelope->remaining_coins;
                $envelope->forceFill(['remaining_count' => 0, 'remaining_coins' => 0])->save();

                if ($left > 0) {
                    $this->wallets->creditSystem($envelope->sender, Wallet::COIN, $left, 'red_envelope_refund');
                }
            });
        }
    }

    /** @throws RoomException */
    protected function assertMember(Room $room, User $user): void
    {
        $inside = RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if (! $inside) {
            throw new RoomException('NOT_A_MEMBER', 'Join the room first.', 409);
        }
    }
}
