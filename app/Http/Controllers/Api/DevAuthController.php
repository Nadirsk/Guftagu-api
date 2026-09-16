<?php

namespace App\Http\Controllers\Api;

use App\Domain\Onboarding\Services\UserAuthService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;

/**
 * Local-development convenience, same shape as Admin\DevHelperController: the route is
 * registered inside an `app()->environment('local')` guard in routes/api.php, so outside
 * local it does not exist at all.
 *
 * Why this exists: real sign-in is OTP or social, and DemoUsersSeeder's fixtures have no
 * password or phone inbox to receive an OTP at. Testing the room/seat/WebRTC flow across
 * two browser tabs needs two real Sanctum tokens for two different seeded users, without
 * standing up an SMS provider first.
 */
class DevAuthController extends Controller
{
    public function __construct(protected UserAuthService $auth)
    {
    }

    /** GET /dev/login-as/{user} */
    public function loginAs(User $user): JsonResponse
    {
        $token = $this->auth->issueToken($user, null);

        return ApiResponse::success([
            ...$token,
            'user' => SocialPresenter::user($user),
        ], 'Dev token issued');
    }
}
