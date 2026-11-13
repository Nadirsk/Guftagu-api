<?php

namespace App\Domain\Rooms;

use App\Domain\Wallet\WalletService;
use App\Events\Rooms\RoomCallUpdated;
use App\Events\Rooms\SeatOccupied;
use App\Events\Rooms\SeatVacated;
use App\Models\Room;
use App\Models\RoomCall;
use App\Models\RoomSeat;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Private calls inside a solo live video. A viewer asks for a voice or video call at the
 * host's price; the host answers one at a time. Answering charges the caller's coins once
 * (the price is "per time"), pays the host the same amount in diamonds, and gives the caller
 * a guest seat on the stage so their media joins the room's peer mesh. Ending frees the seat.
 */
class RoomCallService
{
    public const GUEST_SEAT = 2;

    public function __construct(protected WalletService $wallets)
    {
    }

    /** The host's prices and the sound switch. @throws RoomException */
    public function saveSettings(Room $room, User $actor, int $voice, int $video, bool $sound): Room
    {
        $this->assertOwner($room, $actor);
        $room->forceFill(['call_voice_price' => $voice, 'call_video_price' => $video, 'call_sound' => $sound])->save();

        return $room;
    }

    /** @throws RoomException */
    public function request(Room $room, User $caller, string $type): RoomCall
    {
        $this->assertLive($room);

        if ($room->owner_id === $caller->id) {
            throw new RoomException('BAD_REQUEST', 'You cannot call yourself.', 400);
        }

        $price = $type === 'video' ? $room->call_video_price : $room->call_voice_price;

        if ($price > 0 && $this->wallets->forUser($caller)->coin_balance < $price) {
            throw new RoomException('INSUFFICIENT_COINS', 'You do not have enough coins for this call.', 402);
        }

        $call = DB::transaction(function () use ($room, $caller, $type, $price) {
            $open = RoomCall::query()->where('room_id', $room->id)->where('caller_id', $caller->id)
                ->whereIn('status', [RoomCall::APPLYING, RoomCall::ONGOING])->lockForUpdate()->exists();

            if ($open) {
                throw new RoomException('CALL_EXISTS', 'You already have a call request in this live.', 409);
            }

            return RoomCall::create(['room_id' => $room->id, 'caller_id' => $caller->id, 'type' => $type, 'price' => $price, 'status' => RoomCall::APPLYING]);
        });

        RoomCallUpdated::dispatch($room, $call);

        return $call;
    }

    /** The host answers a request. @throws RoomException */
    public function accept(Room $room, User $actor, RoomCall $call): RoomCall
    {
        $this->assertOwner($room, $actor);
        $this->assertLive($room);
        $this->assertBelongs($room, $call);

        if ($call->status !== RoomCall::APPLYING) {
            throw new RoomException('BAD_REQUEST', 'That request is no longer waiting.', 409);
        }

        $call = DB::transaction(function () use ($room, $call) {
            $busy = RoomCall::query()->where('room_id', $room->id)->where('status', RoomCall::ONGOING)->lockForUpdate()->exists();

            if ($busy) {
                throw new RoomException('CALL_BUSY', 'You are already on a call. End it first.', 409);
            }

            $caller = $call->caller;

            if ($call->price > 0) {
                $this->wallets->debitSystem($caller, Wallet::COIN, $call->price, 'call_send');
                $this->wallets->creditSystem($room->owner, Wallet::DIAMOND, $call->price, 'call_receive');
                $room->increment('total_diamonds_received', $call->price);
            }

            $seat = RoomSeat::query()->firstOrCreate(
                ['room_id' => $room->id, 'seat_number' => self::GUEST_SEAT],
                ['is_locked' => false],
            );
            $seat->forceFill([
                'user_id'       => $caller->id,
                'occupied_at'   => now(),
                'is_self_muted' => false,
                'is_camera_on'  => $call->type === 'video',
            ])->save();

            $call->forceFill(['status' => RoomCall::ONGOING, 'seat_number' => self::GUEST_SEAT, 'started_at' => now()])->save();

            SeatOccupied::dispatch($room, $seat->refresh(), $caller);

            return $call;
        });

        RoomCallUpdated::dispatch($room, $call);

        return $call;
    }

    /** The caller withdraws, or the host turns a request down. @throws RoomException */
    public function decline(Room $room, User $actor, RoomCall $call): RoomCall
    {
        $this->assertBelongs($room, $call);

        $isHost = $room->owner_id === $actor->id;

        if (! $isHost && $call->caller_id !== $actor->id) {
            throw new RoomException('FORBIDDEN', 'That is not your call request.', 403);
        }

        if ($call->status !== RoomCall::APPLYING) {
            throw new RoomException('BAD_REQUEST', 'That request is no longer waiting.', 409);
        }

        $call->forceFill(['status' => $isHost ? RoomCall::REJECTED : RoomCall::CANCELLED, 'ended_at' => now()])->save();
        RoomCallUpdated::dispatch($room, $call);

        return $call;
    }

    /** Either side hangs up. @throws RoomException */
    public function end(Room $room, User $actor, RoomCall $call): RoomCall
    {
        $this->assertBelongs($room, $call);

        if ($room->owner_id !== $actor->id && $call->caller_id !== $actor->id) {
            throw new RoomException('FORBIDDEN', 'That is not your call.', 403);
        }

        if ($call->status !== RoomCall::ONGOING) {
            throw new RoomException('BAD_REQUEST', 'That call is not in progress.', 409);
        }

        $this->finish($room, $call);

        return $call;
    }

    /** Every call still open ends when the live does, or when the caller leaves the room. */
    public function endAllFor(Room $room, ?User $caller = null): void
    {
        RoomCall::query()->where('room_id', $room->id)
            ->when($caller, fn ($q) => $q->where('caller_id', $caller->id))
            ->whereIn('status', [RoomCall::APPLYING, RoomCall::ONGOING])
            ->get()
            ->each(function (RoomCall $call) use ($room) {
                if ($call->status === RoomCall::ONGOING) {
                    $this->finish($room, $call);
                } else {
                    $call->forceFill(['status' => RoomCall::CANCELLED, 'ended_at' => now()])->save();
                    RoomCallUpdated::dispatch($room, $call);
                }
            });
    }

    /** @return Collection<int, RoomCall> */
    public function open(Room $room): Collection
    {
        return RoomCall::query()->where('room_id', $room->id)
            ->whereIn('status', [RoomCall::APPLYING, RoomCall::ONGOING])
            ->with('caller.profile:id,user_id,display_name,avatar_url,gender')
            ->orderBy('id')->get();
    }

    protected function finish(Room $room, RoomCall $call): void
    {
        DB::transaction(function () use ($room, $call) {
            $seat = $room->seats()->where('seat_number', $call->seat_number ?? self::GUEST_SEAT)->first();

            if ($seat !== null && $seat->user_id === $call->caller_id) {
                $seat->forceFill(['user_id' => null, 'occupied_at' => null, 'is_self_muted' => false, 'is_camera_on' => false])->save();
                SeatVacated::dispatch($room, $seat->seat_number, $call->caller);
            }

            $call->forceFill(['status' => RoomCall::ENDED, 'ended_at' => now()])->save();
        });

        RoomCallUpdated::dispatch($room, $call);
    }

    protected function assertOwner(Room $room, User $actor): void
    {
        if ($room->owner_id !== $actor->id) {
            throw new RoomException('FORBIDDEN', 'Only the host can do that.', 403);
        }
    }

    protected function assertLive(Room $room): void
    {
        if ($room->kind !== Room::KIND_VIDEO) {
            throw new RoomException('BAD_REQUEST', 'Private calls are only in a solo live video.', 400);
        }

        if ($room->isClosed()) {
            throw new RoomException('ROOM_CLOSED', 'This live has ended.', 410);
        }
    }

    protected function assertBelongs(Room $room, RoomCall $call): void
    {
        if ($call->room_id !== $room->id) {
            throw new RoomException('NOT_FOUND', 'That call is not in this live.', 404);
        }
    }
}
