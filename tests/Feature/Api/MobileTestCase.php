<?php

namespace Tests\Feature\Api;

use App\Models\Follow;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shared setup for the mobile group.
 *
 * `Sanctum::actingAs` is how a token is obtained here: OTP login is epic D.1 and does not
 * exist yet, so these tests authenticate on the `sanctum` guard directly. That guard is
 * the same one the routes use, so everything downstream of authentication — `user.active`,
 * ownership, the block graph — is exercised for real.
 */
abstract class MobileTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        // Every mobile route sits behind throttle:mobile-api. Left on, the suite's own
        // volume trips it and the failures look like application bugs.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    protected function makeUser(string $name = 'Test User', string $status = User::STATUS_ACTIVE): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198765'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => $status,
            'agora_uid'  => 900000 + $seq,
            'last_active_at' => now(),
        ]);

        UserProfile::create([
            'user_id'      => $user->id,
            'display_name' => $name.' '.$seq,
        ]);

        return $user->fresh('profile');
    }

    protected function actingAsUser(User $user): User
    {
        Sanctum::actingAs($user, ['*'], 'sanctum');

        return $user;
    }

    protected function follow(User $follower, User $following): void
    {
        Follow::firstOrCreate(['follower_id' => $follower->id, 'following_id' => $following->id]);
    }

    /**
     * Make two people friends — which, since a friend is a mutual follow, means following in
     * both directions. Direct messaging is gated on this, so most chat tests start here.
     */
    protected function befriend(User $a, User $b): void
    {
        $this->follow($a, $b);
        $this->follow($b, $a);
    }
}
