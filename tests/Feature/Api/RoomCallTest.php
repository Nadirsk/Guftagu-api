<?php

namespace Tests\Feature\Api;

use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Models\Wallet;
use PHPUnit\Framework\Attributes\Test;

/** Private calls in a solo live video: prices, request, answer (billing + guest seat), hang up. */
class RoomCallTest extends MobileTestCase
{
    protected string $base = '/api/v1';

    /** @return array{0: User, 1: User, 2: string} host, viewer (with coins), room uuid */
    protected function liveWithViewer(int $coins = 100): array
    {
        $host = $this->makeUser('Host');
        $this->actingAsUser($host);
        $uuid = $this->postJson("{$this->base}/rooms", ['name' => 'Live', 'kind' => 'video'])->json('data.uuid');

        $viewer = $this->makeUser('Viewer');
        app(WalletService::class)->creditSystem($viewer, Wallet::COIN, $coins, 'test_grant');
        $this->actingAsUser($viewer);
        $this->postJson("{$this->base}/rooms/{$uuid}/join")->assertOk();

        return [$host, $viewer, $uuid];
    }

    #[Test]
    public function only_the_host_sets_prices_and_the_viewer_sees_them_in_the_state(): void
    {
        [$host, , $uuid] = $this->liveWithViewer();

        $this->patchJson("{$this->base}/rooms/{$uuid}/call-settings", ['voice_price' => 5, 'video_price' => 9])->assertStatus(403);

        $this->actingAsUser($host);
        $this->patchJson("{$this->base}/rooms/{$uuid}/call-settings", ['voice_price' => 5, 'video_price' => 9, 'sound' => true])
            ->assertOk()->assertJsonPath('data.video', 9);

        $this->getJson("{$this->base}/rooms/{$uuid}/state")
            ->assertJsonPath('data.room.call_prices.voice', 5)
            ->assertJsonPath('data.room.call_prices.sound', true);
    }

    #[Test]
    public function a_call_is_billed_when_answered_and_the_caller_gets_a_guest_seat_until_hang_up(): void
    {
        [$host, $viewer, $uuid] = $this->liveWithViewer(100);
        $this->actingAsUser($host);
        $this->patchJson("{$this->base}/rooms/{$uuid}/call-settings", ['voice_price' => 0, 'video_price' => 30])->assertOk();

        $this->actingAsUser($viewer);
        $id = $this->postJson("{$this->base}/rooms/{$uuid}/calls", ['type' => 'video'])
            ->assertCreated()->assertJsonPath('data.status', 'applying')->assertJsonPath('data.price', 30)->json('data.id');
        $this->assertSame(100, $viewer->fresh()->wallet->coin_balance, 'nothing is charged for a request');

        $this->postJson("{$this->base}/rooms/{$uuid}/calls", ['type' => 'video'])->assertStatus(409);

        $this->actingAsUser($host);
        $this->postJson("{$this->base}/rooms/{$uuid}/calls/{$id}/accept")->assertOk()->assertJsonPath('data.status', 'ongoing');
        $this->assertSame(70, $viewer->fresh()->wallet->coin_balance);
        $this->assertSame(30, $host->fresh()->wallet->diamond_balance);

        $this->getJson("{$this->base}/rooms/{$uuid}/state")
            ->assertJsonPath('data.seats.1.user.uuid', $viewer->uuid)
            ->assertJsonPath('data.seats.1.camera_on', true);

        // the viewer hangs up: the seat is freed
        $this->actingAsUser($viewer);
        $this->postJson("{$this->base}/rooms/{$uuid}/calls/{$id}/end")->assertOk()->assertJsonPath('data.status', 'ended');
        $this->getJson("{$this->base}/rooms/{$uuid}/state")->assertJsonPath('data.seats.1.user', null);
    }

    #[Test]
    public function a_viewer_without_enough_coins_cannot_request_and_the_host_can_decline(): void
    {
        [$host, $viewer, $uuid] = $this->liveWithViewer(5);
        $this->actingAsUser($host);
        $this->patchJson("{$this->base}/rooms/{$uuid}/call-settings", ['voice_price' => 50, 'video_price' => 50])->assertOk();

        $this->actingAsUser($viewer);
        $this->postJson("{$this->base}/rooms/{$uuid}/calls", ['type' => 'voice'])->assertStatus(402);

        $this->actingAsUser($host);
        $this->patchJson("{$this->base}/rooms/{$uuid}/call-settings", ['voice_price' => 0, 'video_price' => 0])->assertOk();
        $this->actingAsUser($viewer);
        $id = $this->postJson("{$this->base}/rooms/{$uuid}/calls", ['type' => 'voice'])->assertCreated()->json('data.id');

        $this->actingAsUser($host);
        $this->postJson("{$this->base}/rooms/{$uuid}/calls/{$id}/decline")->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->getJson("{$this->base}/rooms/{$uuid}/calls")->assertJsonCount(0, 'data.calls');
    }

    #[Test]
    public function only_one_call_at_a_time_and_ending_the_live_ends_it(): void
    {
        [$host, $viewer, $uuid] = $this->liveWithViewer();
        $second = $this->makeUser('Second');
        $this->actingAsUser($second);
        $this->postJson("{$this->base}/rooms/{$uuid}/join")->assertOk();
        $b = $this->postJson("{$this->base}/rooms/{$uuid}/calls", ['type' => 'voice'])->assertCreated()->json('data.id');
        $this->actingAsUser($viewer);
        $a = $this->postJson("{$this->base}/rooms/{$uuid}/calls", ['type' => 'voice'])->assertCreated()->json('data.id');

        $this->actingAsUser($host);
        $this->postJson("{$this->base}/rooms/{$uuid}/calls/{$a}/accept")->assertOk();
        $this->postJson("{$this->base}/rooms/{$uuid}/calls/{$b}/accept")->assertStatus(409)->assertJsonPath('error.code', 'CALL_BUSY');

        $this->postJson("{$this->base}/rooms/{$uuid}/end")->assertOk();
        $this->assertDatabaseMissing('room_calls', ['status' => 'ongoing']);
        $this->assertDatabaseMissing('room_calls', ['status' => 'applying']);
    }
}
