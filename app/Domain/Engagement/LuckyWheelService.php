<?php

namespace App\Domain\Engagement;

use App\Domain\Rooms\RoomException;
use App\Domain\Store\InventoryService;
use App\Domain\Wallet\WalletException;
use App\Domain\Wallet\WalletService;
use App\Models\LuckyWheel;
use App\Models\LuckyWheelPrize;
use App\Models\LuckyWheelSpin;
use App\Models\Room;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lucky Wheel. A spin costs coins up front; each spin then lands on one slot, chosen with
 * probability weight / (sum of the wheel's drawable weights), and pays it straight away —
 * coins/diamonds into the wallet, a cosmetic into the inventory. All in one transaction:
 * either you paid and got every prize, or nothing happened.
 */
class LuckyWheelService
{
    public function __construct(protected WalletService $wallets, protected InventoryService $inventory)
    {
    }

    /** @return Collection<int, LuckyWheel> both wheels with their slots, Classic first */
    public function wheels(): Collection
    {
        return LuckyWheel::query()
            ->with('prizes.storeItem')
            ->get()
            ->sortBy(fn (LuckyWheel $w) => array_search($w->mode, LuckyWheel::MODES, true))
            ->values();
    }

    /**
     * @return array{batch: string, spins: Collection<int, LuckyWheelSpin>, coin_balance: int, diamond_balance: int}
     *
     * @throws RoomException
     */
    public function spin(User $user, string $mode, int $times, ?Room $room = null): array
    {
        $wheel = LuckyWheel::query()->where('mode', $mode)->with('prizes.storeItem')->first();

        if ($wheel === null || ! $wheel->is_active) {
            throw new RoomException('WHEEL_CLOSED', 'This wheel is not available right now.', 410);
        }

        if ($times !== 1 && $times !== $wheel->multi_count) {
            throw new RoomException('VALIDATION_ERROR', "Spin 1 or {$wheel->multi_count} times.", 422);
        }

        $pool = $wheel->prizes->filter(fn (LuckyWheelPrize $p) => $p->isDrawable())->values();

        if ($pool->isEmpty()) {
            throw new RoomException('WHEEL_CLOSED', 'This wheel has no prizes set up yet.', 410);
        }

        $total = $wheel->costFor($times);
        $batch = (string) Str::uuid();

        $spins = DB::transaction(function () use ($user, $wheel, $pool, $times, $total, $batch, $room) {
            try {
                $this->wallets->debitSystem($user, Wallet::COIN, $total, 'lucky_wheel_spin', idempotencyKey: "lucky_wheel:{$batch}");
            } catch (WalletException) {
                throw new RoomException('INSUFFICIENT_COINS', 'Not enough coins to spin.', 402);
            }

            // The bundle price spread over its spins, the odd coins on the first, so the rows add up.
            $each = intdiv($total, $times);
            $spins = collect();
            $won = ['coins' => 0, 'diamonds' => 0];

            for ($i = 0; $i < $times; $i++) {
                $prize = $this->draw($pool);

                $spins->push(LuckyWheelSpin::create([
                    'batch'          => $batch,
                    'user_id'        => $user->id,
                    'lucky_wheel_id' => $wheel->id,
                    'prize_id'       => $prize->id,
                    'room_id'        => $room?->id,
                    'cost'           => $each + ($i === 0 ? $total - $each * $times : 0),
                    'position'       => $prize->position,
                    'label'          => $prize->displayLabel(),
                    'reward_type'    => $prize->reward_type,
                    'reward_value'   => $prize->reward_value,
                    'store_item_id'  => $prize->store_item_id,
                    'duration_days'  => $prize->duration_days,
                ])->setRelation('storeItem', $prize->storeItem));

                if (LuckyWheelPrize::isItemType($prize->reward_type)) {
                    $this->inventory->grantStoreItem($user, $prize->storeItem, $prize->duration_days, 'lucky_wheel');
                } else {
                    $won[$prize->reward_type] += $prize->reward_value;
                }
            }

            // One ledger line per currency per tap, not one per spin.
            if ($won['coins'] > 0) {
                $this->wallets->creditSystem($user, Wallet::COIN, $won['coins'], 'lucky_wheel_win', idempotencyKey: "lucky_wheel_win:{$batch}:coin");
            }

            if ($won['diamonds'] > 0) {
                $this->wallets->creditSystem($user, Wallet::DIAMOND, $won['diamonds'], 'lucky_wheel_win', idempotencyKey: "lucky_wheel_win:{$batch}:diamond");
            }

            return $spins;
        });

        $wallet = $this->wallets->forUser($user)->fresh();

        return [
            'batch'           => $batch,
            'spins'           => $spins,
            'coin_balance'    => (int) $wallet->coin_balance,
            'diamond_balance' => (int) $wallet->diamond_balance,
        ];
    }

    /** Weighted pick: a uniform point on [1, sum of weights], walked through the slots. */
    protected function draw(Collection $pool): LuckyWheelPrize
    {
        $point = random_int(1, (int) $pool->sum('weight'));

        foreach ($pool as $prize) {
            $point -= $prize->weight;

            if ($point <= 0) {
                return $prize;
            }
        }

        return $pool->last();
    }

    /**
     * What the admin sees beside the slots: each slot's chance and how much of every coin
     * spent the wheel is expected to give back (cosmetics at their store price).
     *
     * @return array{chances: array<int, float>, payout_rate: float|null}
     */
    public function odds(LuckyWheel $wheel): array
    {
        $pool = $wheel->prizes->filter(fn (LuckyWheelPrize $p) => $p->isDrawable());
        $sum = (int) $pool->sum('weight');
        $chances = [];
        $expected = 0.0;

        foreach ($wheel->prizes as $prize) {
            $chance = $sum > 0 && $prize->isDrawable() ? $prize->weight / $sum : 0.0;
            $chances[$prize->id] = round($chance * 100, 2);
            $expected += $chance * $prize->coinValue();
        }

        return [
            'chances'     => $chances,
            'payout_rate' => $sum > 0 && $wheel->spin_cost > 0 ? round($expected / $wheel->spin_cost * 100, 1) : null,
        ];
    }
}
