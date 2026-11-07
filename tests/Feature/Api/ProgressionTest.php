<?php

namespace Tests\Feature\Api;

use App\Models\Wallet;
use App\Models\WealthCharmLevel;
use PHPUnit\Framework\Attributes\Test;

/** D.7b — the Level screen's top card: both ends of the progress bar and where the user sits. */
class ProgressionTest extends MobileTestCase
{
    #[Test]
    public function it_reports_the_current_levels_start_and_the_next_threshold(): void
    {
        foreach ([[1, 0], [2, 10_000], [3, 50_000]] as [$level, $threshold]) {
            WealthCharmLevel::create(['type' => 'wealth', 'level' => $level, 'name_en' => "W{$level}", 'threshold' => $threshold]);
            WealthCharmLevel::create(['type' => 'charm', 'level' => $level, 'name_en' => "C{$level}", 'threshold' => $threshold / 2]);
        }

        $user = $this->actingAsUser($this->makeUser());
        Wallet::create(['user_id' => $user->id, 'lifetime_gift_coins_sent' => 12_000, 'lifetime_gift_coins_received' => 100]);

        $this->getJson($this->base.'/progression')
            ->assertOk()
            ->assertJsonPath('data.wealth.level', 2)
            ->assertJsonPath('data.wealth.threshold', 10_000)
            ->assertJsonPath('data.wealth.lifetime_value', 12_000)
            ->assertJsonPath('data.wealth.next_level.threshold', 50_000)
            ->assertJsonPath('data.charm.level', 1)
            ->assertJsonPath('data.charm.threshold', 0)
            ->assertJsonPath('data.charm.next_level.level', 2);
    }
}
