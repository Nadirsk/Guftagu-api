<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The app's Profile screen (Figma 676:4517 / 595:3620 / 640:5816): the three counts under
 * the name, and the Gift tab's received/sent wall. Everything else on that screen comes
 * from endpoints that already exist (`auth/me`, `wallet`, `users/{uuid}/posts`).
 */
class ProfileController extends Controller
{
    /**
     * GET /users/{profile}/stats — Following / Followers / Visitors.
     *
     * Visitors is private (VisitorController refuses anyone but the owner), so for
     * somebody else's profile it comes back null rather than as a number.
     */
    public function stats(Request $request, User $profile): JsonResponse
    {
        $isSelf = $profile->id === $request->user()->id;

        return ApiResponse::success([
            'following' => DB::table('follows')->where('follower_id', $profile->id)->count(),
            'followers' => DB::table('follows')->where('following_id', $profile->id)->count(),
            'visitors'  => $isSelf ? DB::table('user_visits')->where('profile_id', $profile->id)->count() : null,
        ]);
    }

    /**
     * GET /users/{profile}/gifts?direction=received|sent — one row per gift with how many
     * of it, newest kind first. `earned` is the diamonds those received gifts paid out;
     * `spent` the coins the sent ones cost.
     */
    public function gifts(Request $request, User $profile): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['sometimes', 'in:received,sent'],
        ]);
        $received = ($data['direction'] ?? 'received') === 'received';

        $rows = DB::table('gift_transactions as t')
            ->join('gifts as g', 'g.id', '=', 't.gift_id')
            ->where($received ? 't.receiver_id' : 't.sender_id', $profile->id)
            ->groupBy('g.id', 'g.code', 'g.name_en', 'g.thumbnail_url', 'g.diamond_value')
            ->orderByRaw('MAX(t.id) DESC')
            ->get([
                'g.id', 'g.code', 'g.name_en', 'g.thumbnail_url', 'g.diamond_value',
                DB::raw('SUM(t.quantity) as quantity'),
                DB::raw('SUM(t.coin_value) as coins'),
            ]);

        return ApiResponse::success([
            'direction' => $received ? 'received' : 'sent',
            'earned'    => $received ? (int) $rows->sum(fn ($r) => $r->diamond_value * $r->quantity) : null,
            'spent'     => $received ? null : (int) $rows->sum('coins'),
            'items'     => $rows->map(fn ($r) => [
                'gift'     => [
                    'id'            => $r->id,
                    'code'          => $r->code,
                    'name_en'       => $r->name_en,
                    'thumbnail_url' => $r->thumbnail_url,
                ],
                'quantity' => (int) $r->quantity,
            ])->all(),
        ]);
    }
}
