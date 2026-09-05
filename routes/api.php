<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminPermissionController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DevHelperController;
use App\Http\Controllers\Admin\EconomyController;
use App\Http\Controllers\Admin\AgencyController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\BannedWordController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\GiftController;
use App\Http\Controllers\Admin\GiftTargetController;
use App\Http\Controllers\Admin\LevelController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RankingController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RoomCatalogueController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\HostController;
use App\Http\Controllers\Admin\ReportCentreController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\SettlementController;
use App\Http\Controllers\Admin\StoreItemController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\SystemLogController;
use App\Http\Controllers\Admin\TranslateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserWalletController;
use App\Http\Controllers\Admin\VipTierController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PostCommentController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\VisitorController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| docs/03 §"Two consumers, two route groups, two middleware stacks":
|
|   /api/v1/…         Flutter app    auth:sanctum + user.active
|   /api/v1/admin/…   Vue panel      auth:sanctum-admin + permission:…
|
| The mobile group below covers epics D.3 (social graph, search, moments), D.4 (messaging)
| and D.9c (blocks). **Token issuance is not here** — OTP login lands with D.1; until then
| a token is obtained through `Sanctum::actingAs` in tests or minted by hand in tinker.
|
| Every admin route carries an explicit permission. The default is deny: a route with
| no `permission:` middleware is reachable by any authenticated admin, so anything
| beyond self-service must name its key.
|
| Mobile routes carry no permission keys — there are no roles in the app. What gates them
| is ownership and the block graph, enforced in the domain services rather than in
| middleware, because "may I see this post" depends on the post.
*/

/*
|--------------------------------------------------------------------------
| Mobile — social, moments, search and chat (epics D.3, D.4, D.9c)
|--------------------------------------------------------------------------
| docs/03 §8. Identifiers are uuids throughout (docs/03 §2.4); `{profile}` is bound to a
| User by uuid just below, since the model's own route key stays numeric for the admin
| routes that address users by id.
*/

Route::bind('profile', fn (string $value) => User::where('uuid', $value)->firstOrFail());

Route::prefix('v1')->name('app.')->middleware(['auth:sanctum', 'user.active', 'throttle:mobile-api'])->group(function () {

    // ---- search (D.3a). Its own throttle — docs/03 §16 caps search at 30/min/user.
    Route::middleware('throttle:search')->group(function () {
        Route::get('search', [SearchController::class, 'index'])->name('search');
    });

    // Static segments before any wildcard, or `history` resolves as a history uuid.
    Route::get('search/history', [SearchController::class, 'history'])->name('search.history');
    Route::post('search/history', [SearchController::class, 'storeHistory'])->name('search.history.store');
    Route::delete('search/history', [SearchController::class, 'clearHistory'])->name('search.history.clear');
    Route::delete('search/history/{uuid}', [SearchController::class, 'destroyHistory'])->name('search.history.destroy');

    // ---- moments (D.3d)
    Route::get('feed', [PostController::class, 'feed'])->name('feed');
    Route::post('posts', [PostController::class, 'store'])->name('posts.store');
    Route::get('posts/{post}', [PostController::class, 'show'])->name('posts.show');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
    Route::post('posts/{post}/like', [PostController::class, 'like'])->name('posts.like');
    Route::delete('posts/{post}/like', [PostController::class, 'unlike'])->name('posts.unlike');
    Route::get('posts/{post}/comments', [PostCommentController::class, 'index'])->name('posts.comments.index');
    Route::post('posts/{post}/comments', [PostCommentController::class, 'store'])->name('posts.comments.store');
    Route::delete('posts/{post}/comments/{comment}', [PostCommentController::class, 'destroy'])
        ->name('posts.comments.destroy');

    // ---- follow graph, friends, blocks, visitors (D.3b, D.9c)
    // A friend is a mutual follow, so there is no request/accept flow to route: adding a
    // friend is `POST /users/{uuid}/follow`, removing one is the matching DELETE.
    Route::get('friends', [FriendController::class, 'index'])->name('friends.index');

    Route::get('blocks', [BlockController::class, 'index'])->name('blocks.index');

    Route::post('users/{profile}/follow', [FollowController::class, 'follow'])->name('users.follow');
    Route::delete('users/{profile}/follow', [FollowController::class, 'unfollow'])->name('users.unfollow');
    Route::get('users/{profile}/followers', [FollowController::class, 'followers'])->name('users.followers');
    Route::get('users/{profile}/following', [FollowController::class, 'following'])->name('users.following');
    Route::post('users/{profile}/block', [BlockController::class, 'store'])->name('users.block');
    Route::delete('users/{profile}/block', [BlockController::class, 'destroy'])->name('users.unblock');
    Route::post('users/{profile}/visit', [VisitorController::class, 'store'])->name('users.visit');
    Route::get('users/{profile}/visitors', [VisitorController::class, 'index'])->name('users.visitors');
    Route::get('users/{profile}/posts', [PostController::class, 'forUser'])->name('users.posts');

    // ---- messaging (D.4)
    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->name('conversations.read');
    // Delivery receipts — the second tick. `delivered` is per thread, called when a
    // `message.new` frame lands; `messages/delivered` is the app-resume sweep over all of
    // them. Static segment first, or `messages` would resolve as a conversation uuid.
    Route::post('messages/delivered', [ConversationController::class, 'deliveredAll'])->name('messages.delivered');
    Route::post('conversations/{conversation}/delivered', [ConversationController::class, 'delivered'])->name('conversations.delivered');
    Route::post('conversations/{conversation}/mute', [ConversationController::class, 'mute'])->name('conversations.mute');
    Route::post('conversations/{conversation}/typing', [ConversationController::class, 'typing'])->name('conversations.typing');
    Route::post('conversations/{conversation}/leave', [ConversationController::class, 'leave'])->name('conversations.leave');
    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])->name('conversations.messages.index');
    Route::delete('conversations/{conversation}/messages/{message}', [MessageController::class, 'destroy'])
        ->name('conversations.messages.destroy');

    // Sending is throttled harder than reading — docs/03 §16, "DM send 30/min/user".
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->middleware('throttle:dm-send')
        ->name('conversations.messages.store');
});

Route::prefix('v1')->group(function () {
    Route::prefix('admin')->name('admin.')->group(function () {

        // ---------------------------------------------------------------- public
        // A.1a — login and the MFA step. Throttles per docs/01 §6 (login 5/min/IP).
        Route::post('auth/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:admin-login')
            ->name('auth.login');

        Route::post('auth/mfa/verify', [AdminAuthController::class, 'verifyMfa'])
            ->middleware('throttle:admin-mfa')
            ->name('auth.mfa.verify');

        // Local-only test affordance. Registered inside the environment check rather than
        // behind a permission, so outside local the route does not exist at all — there is
        // no gate to misconfigure. Lets Swagger's "Try it out" get past the MFA step.
        if (app()->environment('local')) {
            Route::get('dev/last-otp', [DevHelperController::class, 'lastOtp'])->name('dev.last-otp');
        }

        // --------------------------------------------------------- authenticated
        Route::middleware(['auth:sanctum-admin', 'admin.active', 'admin.idle', 'throttle:admin-api'])->group(function () {

            // ---- self-service: no permission key, every admin may manage their own account
            Route::get('auth/me', [AdminAuthController::class, 'me'])->name('auth.me');
            Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');
            Route::patch('auth/profile', [AdminAuthController::class, 'updateProfile'])->name('auth.profile');
            Route::post('auth/password', [AdminAuthController::class, 'changePassword'])->name('auth.password');

            // GFT-122 — MFA re-entry before a high-risk grant
            Route::post('auth/mfa/reauth', [AdminAuthController::class, 'requestReauth'])
                ->middleware('throttle:admin-mfa')->name('auth.mfa.reauth');
            Route::post('auth/mfa/reauth/verify', [AdminAuthController::class, 'verifyReauth'])
                ->middleware('throttle:admin-mfa')->name('auth.mfa.reauth.verify');

            // A convenience for the bilingual name fields (categories, gifts, levels, VIP
            // tiers) — not a translated-strings feature, just a starting draft the admin
            // can correct. No permission key: any admin filling in a form may use it.
            Route::post('translate', [TranslateController::class, 'translate'])
                ->middleware('throttle:admin-translate')->name('translate');

            // ---- security policy (A.1c, A.1d)
            Route::middleware('permission:settings.manage')->group(function () {
                Route::post('auth/mfa/toggle/{roleKey}', [AdminAuthController::class, 'toggleRoleMfa'])
                    ->name('auth.mfa.toggle');
                Route::patch('settings/session-timeout', [AdminAuthController::class, 'updateSessionTimeout'])
                    ->name('settings.session-timeout');
            });

            // ---- roles — IT Admin login only, same reasoning as system logs: neither
            // Super Admin's blanket permission bypass nor a direct grant of
            // `access.role_manage` is enough, only actually being IT Admin.
            Route::middleware(['permission:access.role_manage', 'role:it_admin'])->group(function () {
                Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
                Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
                Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
                Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
                Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
            });

            // ---- permission catalogue (GFT-119)
            Route::middleware('permission:access.permission_grant')->group(function () {
                // `grantable` before any wildcard, so it is never swallowed by one later.
                Route::get('permissions/grantable', [PermissionController::class, 'grantable'])->name('permissions.grantable');
                Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
            });

            // ---- panel users (GFT-127)
            Route::middleware('permission:access.admin_manage')->group(function () {
                Route::get('admins', [AdminUserController::class, 'index'])->name('admins.index');
                Route::post('admins', [AdminUserController::class, 'store'])->name('admins.store');
                Route::get('admins/{admin}', [AdminUserController::class, 'show'])->name('admins.show');
                Route::patch('admins/{admin}', [AdminUserController::class, 'update'])->name('admins.update');
                Route::post('admins/{admin}/status', [AdminUserController::class, 'setStatus'])->name('admins.status');
            });

            // ---- delegation (A.11)
            Route::middleware('permission:access.permission_grant')->group(function () {
                Route::get('admins/{admin}/permissions', [AdminPermissionController::class, 'show'])->name('admins.permissions.show');
                Route::post('admins/{admin}/permissions', [AdminPermissionController::class, 'grant'])->name('admins.permissions.grant');
                Route::delete('admins/{admin}/permissions', [AdminPermissionController::class, 'revoke'])->name('admins.permissions.revoke');
                Route::post('admins/{admin}/permissions/deny', [AdminPermissionController::class, 'deny'])->name('admins.permissions.deny');
            });

            Route::get('admins/{admin}/permission-log', [AdminPermissionController::class, 'log'])
                ->middleware('permission:access.audit_view')
                ->name('admins.permission-log');

            // ---- dashboard (epic A.2)
            Route::middleware('permission:dashboard.view')->group(function () {
                Route::get('dashboard/kpis', [DashboardController::class, 'kpis'])->name('dashboard.kpis');
                Route::get('dashboard/revenue', [DashboardController::class, 'revenue'])->name('dashboard.revenue');
                Route::get('dashboard/engagement', [DashboardController::class, 'engagement'])->name('dashboard.engagement');
            });

            Route::middleware('permission:dashboard.export')->group(function () {
                Route::post('dashboard/export', [DashboardController::class, 'export'])->name('dashboard.export');
                Route::get('dashboard/exports', [DashboardController::class, 'exports'])->name('dashboard.exports');
                Route::get('dashboard/exports/{export}/download', [DashboardController::class, 'download'])
                    ->name('dashboard.exports.download');
            });

            // ---- rooms (epic A.4)
            Route::get('rooms', [RoomController::class, 'index'])
                ->middleware('permission:rooms.view')->name('rooms.index');
            // `live` before `{room}`, or the wildcard swallows it.
            Route::get('rooms/live', [RoomController::class, 'live'])
                ->middleware('permission:rooms.monitor_live')->name('rooms.live');
            Route::get('rooms/{room}', [RoomController::class, 'show'])
                ->middleware('permission:rooms.view')->name('rooms.show');
            Route::post('rooms/{room}/close', [RoomController::class, 'close'])
                ->middleware('permission:rooms.force_close')->name('rooms.close');
            // B.3c — bulk promotion across a set of rooms.
            Route::post('rooms/feature-bulk', [RoomController::class, 'featureBulk'])
                ->middleware('permission:rooms.feature')->name('rooms.feature_bulk');
            Route::post('rooms/{room}/feature', [RoomController::class, 'feature'])
                ->middleware('permission:rooms.feature')->name('rooms.feature');
            Route::post('rooms/{room}/pin', [RoomController::class, 'pin'])
                ->middleware('permission:rooms.pin')->name('rooms.pin');
            Route::patch('rooms/{room}/category', [RoomController::class, 'categorise'])
                ->middleware('permission:rooms.categorise')->name('rooms.categorise');
            Route::patch('rooms/{room}/seat-template', [RoomController::class, 'setSeatTemplate'])
                ->middleware('permission:rooms.seat_template_assign')->name('rooms.seat_template');
            Route::post('rooms/{room}/seats/{seat}/lock', [RoomController::class, 'lockSeat'])
                ->middleware('permission:rooms.seat_lock')->name('rooms.seats.lock');
            Route::post('rooms/{room}/seats/{seat}/vip', [RoomController::class, 'vipSeat'])
                ->middleware('permission:rooms.seat_vip')->name('rooms.seats.vip');

            // ---- live-room enforcement (C.1b, C.2a–c)
            Route::post('rooms/{room}/silent-join', [RoomController::class, 'silentJoin'])
                ->middleware('permission:rooms.join_silent')->name('rooms.silent_join');
            Route::post('rooms/{room}/seats/{seat}/mute', [RoomController::class, 'muteSeat'])
                ->middleware('permission:moderation.mute_user')->name('rooms.seats.mute');
            Route::post('rooms/{room}/seats/{seat}/unmute', [RoomController::class, 'unmuteSeat'])
                ->middleware('permission:moderation.mute_user')->name('rooms.seats.unmute');
            Route::post('rooms/{room}/members/{user}/kick', [RoomController::class, 'kickMember'])
                ->middleware('permission:moderation.kick_user')->name('rooms.members.kick');
            Route::post('rooms/{room}/warn', [RoomController::class, 'warnMember'])
                ->middleware('permission:moderation.warn_user')->name('rooms.warn');

            // ---- room catalogue (A.4d)
            // Reading the catalogue only needs rooms.view — every screen that shows a room
            // needs its category name. Changing it needs rooms.theme_manage.
            Route::get('room-categories', [RoomCatalogueController::class, 'categories'])
                ->middleware('permission:rooms.view')->name('room-categories.index');
            Route::get('room-themes', [RoomCatalogueController::class, 'themes'])
                ->middleware('permission:rooms.view')->name('room-themes.index');
            Route::get('room-seat-templates', [RoomCatalogueController::class, 'seatTemplates'])
                ->middleware('permission:rooms.view')->name('room-seat-templates.index');

            Route::middleware('permission:rooms.theme_manage')->group(function () {
                Route::post('room-categories', [RoomCatalogueController::class, 'storeCategory'])->name('room-categories.store');
                Route::patch('room-categories/{category}', [RoomCatalogueController::class, 'updateCategory'])->name('room-categories.update');
                Route::delete('room-categories/{category}', [RoomCatalogueController::class, 'destroyCategory'])->name('room-categories.destroy');
                Route::post('room-themes', [RoomCatalogueController::class, 'storeTheme'])->name('room-themes.store');
                Route::patch('room-themes/{theme}', [RoomCatalogueController::class, 'updateTheme'])->name('room-themes.update');
                Route::delete('room-themes/{theme}', [RoomCatalogueController::class, 'destroyTheme'])->name('room-themes.destroy');
                Route::post('room-themes/background', [RoomCatalogueController::class, 'uploadThemeBackground'])->name('room-themes.background');
                Route::post('room-themes/preview', [RoomCatalogueController::class, 'uploadThemePreview'])->name('room-themes.preview');
                Route::post('room-seat-templates', [RoomCatalogueController::class, 'storeSeatTemplate'])->name('room-seat-templates.store');
                Route::patch('room-seat-templates/{template}', [RoomCatalogueController::class, 'updateSeatTemplate'])->name('room-seat-templates.update');
                Route::delete('room-seat-templates/{template}', [RoomCatalogueController::class, 'destroySeatTemplate'])->name('room-seat-templates.destroy');
            });

            // ---- gifts & store (epic A.6)
            Route::get('gifts', [GiftController::class, 'index'])
                ->middleware('permission:gifts.view')->name('gifts.index');
            Route::get('gift-categories', [GiftController::class, 'categories'])
                ->middleware('permission:gifts.view')->name('gift-categories.index');

            Route::middleware('permission:gifts.manage')->group(function () {
                Route::post('gifts', [GiftController::class, 'store'])->name('gifts.store');
                Route::patch('gifts/{gift}', [GiftController::class, 'update'])->name('gifts.update');
                Route::delete('gifts/{gift}', [GiftController::class, 'destroy'])->name('gifts.destroy');
                Route::post('gifts/animation', [GiftController::class, 'uploadAnimation'])->name('gifts.animation');
                Route::post('gifts/thumbnail', [GiftController::class, 'uploadThumbnail'])->name('gifts.thumbnail');
            });

            // Restocking a limited drop is its own key — pricing a gift and deciding how
            // many exist are different decisions.
            Route::post('gifts/{gift}/restock', [GiftController::class, 'restock'])
                ->middleware('permission:gifts.drop_manage')->name('gifts.restock');

            Route::middleware('permission:gifts.category_manage')->group(function () {
                Route::post('gift-categories', [GiftController::class, 'storeCategory'])->name('gift-categories.store');
                Route::patch('gift-categories/{category}', [GiftController::class, 'updateCategory'])->name('gift-categories.update');
                Route::post('gift-categories/icon', [GiftController::class, 'uploadCategoryIcon'])->name('gift-categories.icon');
            });

            // ---- wealth/charm levels — docs/00 §7, GFT-027's ladder
            Route::get('levels', [LevelController::class, 'index'])
                ->middleware('permission:levels.view')->name('levels.index');
            Route::middleware('permission:levels.manage')->group(function () {
                Route::post('levels', [LevelController::class, 'store'])->name('levels.store');
                Route::patch('levels/{level}', [LevelController::class, 'update'])->name('levels.update');
                Route::post('levels/badge', [LevelController::class, 'uploadBadge'])->name('levels.badge');
            });

            // ---- VIP & cosmetics (A.6c, A.6d)
            Route::get('vip-tiers', [VipTierController::class, 'index'])
                ->middleware('permission:vip.view')->name('vip-tiers.index');
            // Badges only now — frames/bubbles/entry banners/entrance effects moved to
            // store-items below, since they are purchasable catalogue items, not earned ones.
            Route::get('cosmetics', [VipTierController::class, 'cosmetics'])
                ->middleware('permission:vip.view')->name('cosmetics.index');

            Route::middleware('permission:vip.manage')->group(function () {
                Route::post('vip-tiers', [VipTierController::class, 'store'])->name('vip-tiers.store');
                Route::patch('vip-tiers/{tier}', [VipTierController::class, 'update'])->name('vip-tiers.update');
                Route::delete('vip-tiers/{tier}', [VipTierController::class, 'destroy'])->name('vip-tiers.destroy');
                Route::post('cosmetics/badges', [VipTierController::class, 'storeBadge'])->name('cosmetics.badges.store');
            });

            // ---- store items — the app's "Mall": frames, bubbles, entry banners, entrance
            // effects. Reuses the vip.* permissions since these were vip.manage-gated
            // already as frames/effects, before the store_items unification.
            Route::get('store-items', [StoreItemController::class, 'index'])
                ->middleware('permission:vip.view')->name('store-items.index');

            Route::middleware('permission:vip.manage')->group(function () {
                Route::post('store-items', [StoreItemController::class, 'store'])->name('store-items.store');
                Route::patch('store-items/{storeItem}', [StoreItemController::class, 'update'])->name('store-items.update');
                Route::delete('store-items/{storeItem}', [StoreItemController::class, 'destroy'])->name('store-items.destroy');
                Route::post('store-items/image', [StoreItemController::class, 'uploadImage'])->name('store-items.image');
            });

            // ---- economy (epic A.7)
            Route::get('economy/rates', [EconomyController::class, 'rates'])
                ->middleware('permission:economy.ledger_view')->name('economy.rates');
            Route::patch('economy/rates', [EconomyController::class, 'setRate'])
                ->middleware('permission:economy.rates_manage')->name('economy.rates.set');

            Route::get('economy/packages', [EconomyController::class, 'packages'])
                ->middleware('permission:economy.ledger_view')->name('economy.packages');
            Route::middleware('permission:economy.packages_manage')->group(function () {
                Route::post('economy/packages', [EconomyController::class, 'storePackage'])->name('economy.packages.store');
                Route::patch('economy/packages/{package}', [EconomyController::class, 'updatePackage'])->name('economy.packages.update');
            });

            Route::get('economy/commission-slabs', [EconomyController::class, 'slabs'])
                ->middleware('permission:economy.ledger_view')->name('economy.slabs');
            Route::middleware('permission:economy.commission_manage')->group(function () {
                Route::post('economy/commission-slabs', [EconomyController::class, 'storeSlab'])->name('economy.slabs.store');
                Route::delete('economy/commission-slabs/{slab}', [EconomyController::class, 'destroySlab'])->name('economy.slabs.destroy');
            });

            Route::get('economy/ledger', [EconomyController::class, 'ledger'])
                ->middleware('permission:economy.ledger_view')->name('economy.ledger');

            Route::middleware('permission:economy.reconcile')->group(function () {
                Route::get('economy/reconciliation', [EconomyController::class, 'reconciliation'])->name('economy.reconciliation');
                Route::post('economy/reconciliation/run', [EconomyController::class, 'runReconciliation'])->name('economy.reconciliation.run');
            });

            // ---- withdrawals (A.7b)
            // `summary` before `{withdrawal}`, or the wildcard swallows it.
            Route::get('withdrawals/summary', [WithdrawalController::class, 'summary'])
                ->middleware('permission:payouts.view')->name('withdrawals.summary');
            Route::get('withdrawals', [WithdrawalController::class, 'index'])
                ->middleware('permission:payouts.view')->name('withdrawals.index');
            Route::get('withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])
                ->middleware('permission:payouts.view')->name('withdrawals.show');
            Route::post('withdrawals/{withdrawal}/approve', [WithdrawalController::class, 'approve'])
                ->middleware('permission:payouts.approve')->name('withdrawals.approve');
            Route::post('withdrawals/{withdrawal}/reject', [WithdrawalController::class, 'reject'])
                ->middleware('permission:payouts.reject')->name('withdrawals.reject');
            Route::patch('withdrawal-settings', [WithdrawalController::class, 'updateSettings'])
                ->middleware('permission:settings.manage')->name('withdrawals.settings');

            // ---- events (epic A.9a/b)
            Route::get('events', [EventController::class, 'index'])
                ->middleware('permission:events.view')->name('events.index');
            Route::get('events/{event}', [EventController::class, 'show'])
                ->middleware('permission:events.view')->name('events.show');

            Route::middleware('permission:events.manage')->group(function () {
                Route::post('events', [EventController::class, 'store'])->name('events.store');
                Route::patch('events/{event}', [EventController::class, 'update'])->name('events.update');
                // Deliberately outside the events.manage group below it in intent: the
                // route carries its own stricter permission so a Manager who may build an
                // event cannot also push it live (B.3a).
                Route::post('events/{event}/publish', [EventController::class, 'publish'])
                    ->middleware('permission:events.approve')->name('events.publish');
                Route::post('events/{event}/cancel', [EventController::class, 'cancel'])->name('events.cancel');
                Route::post('events/{event}/draw', [EventController::class, 'runDraw'])->name('events.draw');
            });

            // Anyone who can see an event can check a published draw — that is the point
            // of provable fairness.
            Route::get('events/{event}/draw/verify', [EventController::class, 'verifyDraw'])
                ->middleware('permission:events.view')->name('events.draw.verify');

            // Configuring and handing out rewards is its own key: scheduling an event and
            // giving away coins are different decisions.
            Route::middleware('permission:events.reward_manage')->group(function () {
                Route::post('events/{event}/rewards', [EventController::class, 'addReward'])->name('events.rewards.store');
                Route::delete('events/{event}/rewards/{reward}', [EventController::class, 'removeReward'])->name('events.rewards.destroy');
                Route::post('events/{event}/distribute', [EventController::class, 'distribute'])->name('events.distribute');
            });

            // ---- rankings (A.9c/d)
            Route::get('ranking-rules', [RankingController::class, 'index'])
                ->middleware('permission:rankings.view')->name('rankings.index');
            Route::get('ranking-rules/{rule}/board', [RankingController::class, 'board'])
                ->middleware('permission:rankings.view')->name('rankings.board');
            Route::get('ranking-rules/{rule}/snapshots', [RankingController::class, 'snapshots'])
                ->middleware('permission:rankings.view')->name('rankings.snapshots');
            Route::get('ranking-rules/{rule}/rewards', [RankingController::class, 'rewards'])
                ->middleware('permission:rankings.view')->name('rankings.rewards');

            Route::middleware('permission:rankings.rules_manage')->group(function () {
                Route::post('ranking-rules', [RankingController::class, 'store'])->name('rankings.store');
                Route::patch('ranking-rules/{rule}', [RankingController::class, 'update'])->name('rankings.update');
                Route::post('ranking-rules/{rule}/snapshot', [RankingController::class, 'snapshot'])->name('rankings.snapshot');
                Route::post('ranking-rules/{rule}/rewards', [RankingController::class, 'addReward'])->name('rankings.rewards.store');
                Route::patch('ranking-rules/{rule}/rewards/{reward}', [RankingController::class, 'updateReward'])->name('rankings.rewards.update');
                Route::delete('ranking-rules/{rule}/rewards/{reward}', [RankingController::class, 'removeReward'])->name('rankings.rewards.destroy');
            });

            Route::post('ranking-rules/{rule}/pay-rewards', [RankingController::class, 'payRewards'])
                ->middleware('permission:rankings.reward_payout')->name('rankings.pay');

            // ---- users (epic A.3)
            // Each route names its own key rather than sharing a group: being allowed to
            // view a user is not the same as being allowed to ban them or move their money.
            Route::get('users', [UserController::class, 'index'])
                ->middleware('permission:users.view')->name('users.index');
            Route::post('users', [UserController::class, 'store'])
                ->middleware('permission:users.create')->name('users.store');
            Route::post('users/kyc-documents', [UserController::class, 'uploadKycDocument'])
                ->middleware('permission:users.create')->name('users.kyc_documents.upload');
            Route::get('users/{user}', [UserController::class, 'show'])
                ->middleware('permission:users.view')->name('users.show');
            Route::get('users/{user}/pii', [UserController::class, 'pii'])
                ->middleware('permission:users.view_pii')->name('users.pii');
            Route::patch('users/{user}', [UserController::class, 'update'])
                ->middleware('permission:users.edit')->name('users.update');
            Route::post('users/{user}/suspend', [UserController::class, 'suspend'])
                ->middleware('permission:users.suspend')->name('users.suspend');
            Route::post('users/{user}/ban', [UserController::class, 'ban'])
                ->middleware('permission:users.ban')->name('users.ban');
            Route::post('users/{user}/unban', [UserController::class, 'unban'])
                ->middleware('permission:users.ban')->name('users.unban');
            Route::post('users/{user}/kyc/verify', [UserController::class, 'reviewKyc'])
                ->middleware('permission:users.kyc_verify')->name('users.kyc.verify');
            Route::post('users/{user}/level-override', [UserController::class, 'overrideLevel'])
                ->middleware('permission:users.level_edit')->name('users.level_override');

            // ---- wallet (A.3d)
            Route::get('users/{user}/wallet', [UserWalletController::class, 'show'])
                ->middleware('permission:wallet.view')->name('users.wallet');
            Route::get('users/{user}/wallet/integrity', [UserWalletController::class, 'integrity'])
                ->middleware('permission:wallet.ledger_view')->name('users.wallet.integrity');
            Route::get('users/{user}/transactions', [UserWalletController::class, 'transactions'])
                ->middleware('permission:wallet.ledger_view')->name('users.transactions');
            Route::post('users/{user}/wallet/credit', [UserWalletController::class, 'credit'])
                ->middleware('permission:wallet.manual_credit')->name('users.wallet.credit');
            Route::post('users/{user}/wallet/debit', [UserWalletController::class, 'debit'])
                ->middleware('permission:wallet.manual_debit')->name('users.wallet.debit');
            Route::post('users/{user}/wallet/freeze', [UserWalletController::class, 'freeze'])
                ->middleware('permission:wallet.manual_debit')->name('users.wallet.freeze');

            // ---- content filter (A.5a)
            Route::get('moderation/banned-words', [BannedWordController::class, 'index'])
                ->middleware('permission:moderation.bannedwords_manage')->name('moderation.words.index');
            Route::post('moderation/banned-words', [BannedWordController::class, 'store'])
                ->middleware('permission:moderation.bannedwords_manage')->name('moderation.words.store');
            Route::patch('moderation/banned-words/{bannedWord}', [BannedWordController::class, 'update'])
                ->middleware('permission:moderation.bannedwords_manage')->name('moderation.words.update');
            Route::delete('moderation/banned-words/{bannedWord}', [BannedWordController::class, 'destroy'])
                ->middleware('permission:moderation.bannedwords_manage')->name('moderation.words.destroy');
            Route::post('moderation/banned-words/import', [BannedWordController::class, 'import'])
                ->middleware('permission:moderation.bannedwords_manage')->name('moderation.words.import');
            // Dry run against the live list. Read-only, so it sits behind logs_view rather
            // than the manage permission — checking what a rule does is not editing it.
            Route::post('moderation/filter-test', [BannedWordController::class, 'test'])
                ->middleware('permission:moderation.logs_view')->name('moderation.filter.test');
            Route::get('moderation/flags', [BannedWordController::class, 'flags'])
                ->middleware('permission:moderation.flags_review')->name('moderation.flags.index');
            Route::post('moderation/flags/{flag}/review', [BannedWordController::class, 'reviewFlag'])
                ->middleware('permission:moderation.flags_review')->name('moderation.flags.review');

            // ---- reports queue (A.5b, C.3)
            Route::get('reports', [ModerationController::class, 'reports'])
                ->middleware('permission:reports.view')->name('reports.index');
            Route::get('reports/summary', [ModerationController::class, 'queueSummary'])
                ->middleware('permission:reports.view')->name('reports.summary');
            Route::get('reports/{report}', [ModerationController::class, 'showReport'])
                ->middleware('permission:reports.view')->name('reports.show');
            Route::post('reports/{report}/assign', [ModerationController::class, 'assign'])
                ->middleware('permission:reports.assign')->name('reports.assign');
            Route::post('reports/{report}/action', [ModerationController::class, 'action'])
                ->middleware('permission:reports.action')->name('reports.action');
            Route::post('reports/{report}/dismiss', [ModerationController::class, 'dismiss'])
                ->middleware('permission:reports.action')->name('reports.dismiss');
            Route::post('reports/{report}/escalate', [ModerationController::class, 'escalate'])
                ->middleware('permission:reports.escalate')->name('reports.escalate');

            // ---- oversight (A.5c, C.4)
            Route::post('moderation/actions/{action}/reverse', [ModerationController::class, 'reverse'])
                ->middleware('permission:moderation.reverse_action')->name('moderation.actions.reverse');
            Route::get('moderation/sanctions', [ModerationController::class, 'sanctions'])
                ->middleware('permission:moderation.logs_view')->name('moderation.sanctions');
            Route::get('moderation/logs', [ModerationController::class, 'logs'])
                ->middleware('permission:moderation.logs_view')->name('moderation.logs');
            Route::get('moderation/stats', [ModerationController::class, 'stats'])
                ->middleware('permission:moderation.stats_view')->name('moderation.stats');
            Route::get('moderation/alerts', [ModerationController::class, 'alerts'])
                ->middleware('permission:reports.view')->name('moderation.alerts');

            // ---- claims, policy and recurring issues (C.3a, C.4b, C.5c)
            Route::post('reports/{report}/claim', [ModerationController::class, 'claim'])
                ->middleware('permission:reports.view')->name('reports.claim');
            Route::delete('reports/{report}/claim', [ModerationController::class, 'release'])
                ->middleware('permission:reports.view')->name('reports.claim.release');
            Route::get('moderation/recurring', [ModerationController::class, 'recurring'])
                ->middleware('permission:reports.view')->name('moderation.recurring');
            // A moderator reads their own log; no extra grant needed to see what you did.
            Route::get('moderation/my-actions', [ModerationController::class, 'myActions'])
                ->middleware('permission:reports.view')->name('moderation.my_actions');
            Route::get('moderation/policy', [ModerationController::class, 'policy'])
                ->middleware('permission:reports.view')->name('moderation.policy');

            // ---- agencies (A.8a)
            Route::get('agencies', [AgencyController::class, 'index'])
                ->middleware('permission:agency.view')->name('agencies.index');
            Route::get('agencies/{agency}', [AgencyController::class, 'show'])
                ->middleware('permission:agency.view')->name('agencies.show');
            Route::post('agencies', [AgencyController::class, 'store'])
                ->middleware('permission:agency.edit')->name('agencies.store');
            Route::patch('agencies/{agency}', [AgencyController::class, 'update'])
                ->middleware('permission:agency.edit')->name('agencies.update');
            Route::post('agencies/{agency}/documents', [AgencyController::class, 'addDocument'])
                ->middleware('permission:agency.edit')->name('agencies.documents');
            Route::post('agencies/{agency}/approve', [AgencyController::class, 'approve'])
                ->middleware('permission:agency.approve')->name('agencies.approve');
            Route::post('agencies/{agency}/reject', [AgencyController::class, 'reject'])
                ->middleware('permission:agency.approve')->name('agencies.reject');
            Route::post('agencies/{agency}/suspend', [AgencyController::class, 'suspend'])
                ->middleware('permission:agency.approve')->name('agencies.suspend');
            Route::post('agencies/{agency}/reinstate', [AgencyController::class, 'reinstate'])
                ->middleware('permission:agency.approve')->name('agencies.reinstate');

            // ---- host applications and hosts (A.8a)
            Route::get('host-applications', [HostController::class, 'applications'])
                ->middleware('permission:hosts.view')->name('hosts.applications');
            Route::post('host-applications/{application}/approve', [HostController::class, 'approveApplication'])
                ->middleware('permission:hosts.approve')->name('hosts.applications.approve');
            Route::post('host-applications/{application}/reject', [HostController::class, 'rejectApplication'])
                ->middleware('permission:hosts.approve')->name('hosts.applications.reject');

            Route::get('hosts', [HostController::class, 'index'])
                ->middleware('permission:hosts.view')->name('hosts.index');
            // Static segments must be registered before {host}, or `targets` is matched as
            // a host id and every target request 404s.
            Route::get('hosts/targets', [HostController::class, 'targets'])
                ->middleware('permission:hosts.target_manage')->name('hosts.targets.index');
            Route::get('hosts/targets/{target}', [HostController::class, 'showTarget'])
                ->middleware('permission:hosts.target_manage')->name('hosts.targets.show');
            Route::post('hosts/targets/{target}/evaluate', [HostController::class, 'evaluateTarget'])
                ->middleware('permission:hosts.target_manage')->name('hosts.targets.evaluate');
            Route::delete('hosts/targets/{target}', [HostController::class, 'cancelTarget'])
                ->middleware('permission:hosts.target_manage')->name('hosts.targets.cancel');

            // ---- monthly gift-target ladder (mehfil's "Policies", separate from the above)
            Route::get('gift-target-policies', [GiftTargetController::class, 'index'])
                ->middleware('permission:hosts.gift_target_manage')->name('gift-target-policies.index');
            Route::post('gift-target-policies', [GiftTargetController::class, 'store'])
                ->middleware('permission:hosts.gift_target_manage')->name('gift-target-policies.store');
            Route::patch('gift-target-policies/{policy}', [GiftTargetController::class, 'update'])
                ->middleware('permission:hosts.gift_target_manage')->name('gift-target-policies.update');
            // Static before {host} again, for the same reason as hosts/targets above.
            Route::get('hosts/gift-targets', [GiftTargetController::class, 'results'])
                ->middleware('permission:hosts.gift_target_manage')->name('hosts.gift-targets.index');
            Route::get('hosts/gift-targets/tracker', [GiftTargetController::class, 'tracker'])
                ->middleware('permission:hosts.gift_target_manage')->name('hosts.gift-targets.tracker');
            Route::post('hosts/gift-targets/evaluate-all', [GiftTargetController::class, 'evaluateAll'])
                ->middleware('permission:hosts.gift_target_manage')->name('hosts.gift-targets.evaluate-all');
            Route::post('hosts/{host}/gift-targets/evaluate', [GiftTargetController::class, 'evaluateHost'])
                ->middleware('permission:hosts.gift_target_manage')->name('hosts.gift-targets.evaluate');

            Route::get('hosts/{host}', [HostController::class, 'show'])
                ->middleware('permission:hosts.view')->name('hosts.show');
            Route::get('hosts/{host}/earnings/verify', [HostController::class, 'verifyEarnings'])
                ->middleware('permission:hosts.earnings_view')->name('hosts.earnings.verify');
            Route::patch('hosts/{host}', [HostController::class, 'update'])
                ->middleware('permission:hosts.approve')->name('hosts.update');
            Route::post('hosts/{host}/agency', [HostController::class, 'assign'])
                ->middleware('permission:hosts.approve')->name('hosts.assign');
            Route::post('hosts/{host}/status', [HostController::class, 'setStatus'])
                ->middleware('permission:hosts.approve')->name('hosts.status');
            Route::post('hosts/{host}/targets', [HostController::class, 'storeTarget'])
                ->middleware('permission:hosts.target_manage')->name('hosts.targets.store');

            // ---- settlements (A.8d)
            Route::get('settlements', [SettlementController::class, 'index'])
                ->middleware('permission:agency.view')->name('settlements.index');
            Route::get('settlements/batches', [SettlementController::class, 'batches'])
                ->middleware('permission:agency.view')->name('settlements.batches');
            Route::get('settlements/{settlement}', [SettlementController::class, 'show'])
                ->middleware('permission:agency.view')->name('settlements.show');
            // Raising a settlement is a Manager job; approving and paying one is not, so
            // the two-person rule survives even when one person holds both screens.
            Route::post('settlements/generate', [SettlementController::class, 'generate'])
                ->middleware('permission:agency.settlement_raise')->name('settlements.generate');
            Route::post('settlements/{settlement}/raise', [SettlementController::class, 'raise'])
                ->middleware('permission:agency.settlement_raise')->name('settlements.raise');
            Route::post('settlements/{settlement}/approve', [SettlementController::class, 'approve'])
                ->middleware('permission:agency.settlement_process')->name('settlements.approve');
            Route::post('settlements/{settlement}/reject', [SettlementController::class, 'reject'])
                ->middleware('permission:agency.settlement_process')->name('settlements.reject');
            Route::post('settlements/batch', [SettlementController::class, 'batch'])
                ->middleware('permission:agency.settlement_process')->name('settlements.batch');
            Route::post('settlements/batches/{batch}/process', [SettlementController::class, 'processBatch'])
                ->middleware('permission:agency.settlement_process')->name('settlements.batch.process');

            // ---- banners (A.10a)
            Route::get('content/banners', [ContentController::class, 'banners'])
                ->middleware('permission:cms.banner_manage')->name('content.banners.index');
            Route::post('content/banners', [ContentController::class, 'storeBanner'])
                ->middleware('permission:cms.banner_manage')->name('content.banners.store');
            Route::post('content/banners/{banner}/approve', [ContentController::class, 'approveBanner'])
                ->middleware('permission:cms.banner_approve')->name('content.banners.approve');
            Route::patch('content/banners/{banner}', [ContentController::class, 'updateBanner'])
                ->middleware('permission:cms.banner_manage')->name('content.banners.update');
            Route::delete('content/banners/{banner}', [ContentController::class, 'destroyBanner'])
                ->middleware('permission:cms.banner_manage')->name('content.banners.destroy');

            // ---- announcements (A.10a)
            Route::get('content/announcements', [ContentController::class, 'announcements'])
                ->middleware('permission:cms.announcement_manage')->name('content.announcements.index');
            Route::post('content/announcements', [ContentController::class, 'storeAnnouncement'])
                ->middleware('permission:cms.announcement_manage')->name('content.announcements.store');
            Route::patch('content/announcements/{announcement}', [ContentController::class, 'updateAnnouncement'])
                ->middleware('permission:cms.announcement_manage')->name('content.announcements.update');
            Route::delete('content/announcements/{announcement}', [ContentController::class, 'destroyAnnouncement'])
                ->middleware('permission:cms.announcement_manage')->name('content.announcements.destroy');

            // ---- pages and FAQs (A.10a)
            Route::get('content/pages', [ContentController::class, 'pages'])
                ->middleware('permission:cms.page_manage')->name('content.pages.index');
            Route::post('content/pages', [ContentController::class, 'storePage'])
                ->middleware('permission:cms.page_manage')->name('content.pages.store');
            Route::get('content/pages/{page}', [ContentController::class, 'showPage'])
                ->middleware('permission:cms.page_manage')->name('content.pages.show');
            Route::patch('content/pages/{page}', [ContentController::class, 'updatePage'])
                ->middleware('permission:cms.page_manage')->name('content.pages.update');
            Route::post('content/pages/{page}/publish', [ContentController::class, 'publishPage'])
                ->middleware('permission:cms.page_manage')->name('content.pages.publish');
            Route::post('content/pages/{page}/unpublish', [ContentController::class, 'unpublishPage'])
                ->middleware('permission:cms.page_manage')->name('content.pages.unpublish');
            Route::post('content/pages/{page}/restore/{version}', [ContentController::class, 'restorePage'])
                ->middleware('permission:cms.page_manage')->name('content.pages.restore');

            Route::get('content/faqs', [ContentController::class, 'faqs'])
                ->middleware('permission:cms.page_manage')->name('content.faqs.index');
            Route::post('content/faqs', [ContentController::class, 'storeFaq'])
                ->middleware('permission:cms.page_manage')->name('content.faqs.store');
            Route::post('content/faqs/reorder', [ContentController::class, 'reorderFaqs'])
                ->middleware('permission:cms.page_manage')->name('content.faqs.reorder');
            Route::patch('content/faqs/{faq}', [ContentController::class, 'updateFaq'])
                ->middleware('permission:cms.page_manage')->name('content.faqs.update');
            Route::delete('content/faqs/{faq}', [ContentController::class, 'destroyFaq'])
                ->middleware('permission:cms.page_manage')->name('content.faqs.destroy');

            // ---- broadcast campaigns (A.10a)
            Route::get('broadcasts', [BroadcastController::class, 'index'])
                ->middleware('permission:cms.announcement_manage')->name('broadcasts.index');
            // Sizing an audience is read-only, so it sits behind the compose permission
            // rather than the send one - an operator drafts before they are allowed to send.
            Route::post('broadcasts/preview', [BroadcastController::class, 'previewAudience'])
                ->middleware('permission:cms.announcement_manage')->name('broadcasts.preview');
            Route::get('broadcasts/{broadcast}', [BroadcastController::class, 'show'])
                ->middleware('permission:cms.announcement_manage')->name('broadcasts.show');
            Route::post('broadcasts', [BroadcastController::class, 'store'])
                ->middleware('permission:cms.announcement_manage')->name('broadcasts.store');
            Route::patch('broadcasts/{broadcast}', [BroadcastController::class, 'update'])
                ->middleware('permission:cms.announcement_manage')->name('broadcasts.update');
            Route::get('broadcasts/{broadcast}/outcome', [BroadcastController::class, 'outcome'])
                ->middleware('permission:cms.announcement_manage')->name('broadcasts.outcome');
            Route::post('broadcasts/{broadcast}/cancel', [BroadcastController::class, 'cancel'])
                ->middleware('permission:cms.announcement_manage')->name('broadcasts.cancel');
            Route::post('broadcasts/{broadcast}/send', [BroadcastController::class, 'send'])
                ->middleware('permission:cms.campaign_send')->name('broadcasts.send');

            // ---- report centre (A.10b, A.10c)
            // The route requires *some* export permission; the per-type check is in the
            // controller, because which one applies depends on the request body.
            Route::get('reports-centre', [ReportCentreController::class, 'index'])
                ->middleware('permission:reports_export.revenue')->name('reports.centre');
            Route::post('reports-centre/preview', [ReportCentreController::class, 'preview'])
                ->middleware('permission:reports_export.users')->name('reports.preview');
            Route::get('reports-centre/reconcile', [ReportCentreController::class, 'reconcile'])
                ->middleware('permission:reports_export.revenue')->name('reports.reconcile');
            Route::post('reports-centre/export', [ReportCentreController::class, 'export'])
                ->middleware('permission:reports_export.users')->name('reports.export');
            Route::get('reports-centre/exports', [ReportCentreController::class, 'exports'])
                ->middleware('permission:reports_export.users')->name('reports.exports');
            Route::get('reports-centre/exports/{uuid}/download', [ReportCentreController::class, 'download'])
                ->middleware('permission:reports_export.users')->name('reports.download');

            // ---- audit trail (A.10d)
            Route::get('audit-logs', [AuditLogController::class, 'index'])
                ->middleware('permission:access.audit_view')->name('audit.index');
            Route::get('audit-logs/filters', [AuditLogController::class, 'filters'])
                ->middleware('permission:access.audit_view')->name('audit.filters');
            Route::get('audit-logs/coverage', [AuditLogController::class, 'coverage'])
                ->middleware('permission:access.audit_view')->name('audit.coverage');
            Route::get('audit-logs/entity', [AuditLogController::class, 'forEntity'])
                ->middleware('permission:access.audit_view')->name('audit.entity');
            Route::get('audit-logs/{log}', [AuditLogController::class, 'show'])
                ->middleware('permission:access.audit_view')->name('audit.show');

            // ---- system logs — IT Admin login only. Deliberately gated by role identity
            // (`role:it_admin`) rather than the permission system alone: Super Admin's
            // blanket permission bypass and any direct grant of `system.logs_view` must
            // NOT be enough to see this screen, only actually being IT Admin.
            Route::get('system/logs/laravel', [SystemLogController::class, 'laravelLog'])
                ->middleware(['permission:system.logs_view', 'role:it_admin'])->name('system.logs.laravel');
            Route::get('system/logs/frontend', [SystemLogController::class, 'frontendIndex'])
                ->middleware(['permission:system.logs_view', 'role:it_admin'])->name('system.logs.frontend.index');
            // Self-service: any authenticated admin may report their own browser error,
            // whether or not they can see the collected list back.
            Route::post('system/logs/frontend', [SystemLogController::class, 'frontendStore'])
                ->name('system.logs.frontend.store');

            // ---- support inbox (epic B.4)
            Route::get('support', [SupportController::class, 'index'])
                ->middleware('permission:support.view')->name('support.index');
            // Static segments before {ticket}, or `summary` resolves as a ticket id.
            Route::get('support/summary', [SupportController::class, 'summary'])
                ->middleware('permission:support.view')->name('support.summary');
            Route::get('support/breaching', [SupportController::class, 'breaching'])
                ->middleware('permission:support.view')->name('support.breaching');
            Route::get('support/canned-replies', [SupportController::class, 'cannedReplies'])
                ->middleware('permission:support.view')->name('support.canned.index');
            Route::post('support/canned-replies', [SupportController::class, 'storeCannedReply'])
                ->middleware('permission:support.manage')->name('support.canned.store');
            Route::delete('support/canned-replies/{cannedReply}', [SupportController::class, 'destroyCannedReply'])
                ->middleware('permission:support.manage')->name('support.canned.destroy');
            Route::post('support/flag-room', [SupportController::class, 'flagRoom'])
                ->middleware('permission:support.flag_room')->name('support.flag_room');

            Route::post('support', [SupportController::class, 'store'])
                ->middleware('permission:support.reply')->name('support.store');
            Route::get('support/{ticket}', [SupportController::class, 'show'])
                ->middleware('permission:support.view')->name('support.show');
            Route::post('support/{ticket}/assign', [SupportController::class, 'assign'])
                ->middleware('permission:support.assign')->name('support.assign');
            Route::post('support/{ticket}/reply', [SupportController::class, 'reply'])
                ->middleware('permission:support.reply')->name('support.reply');
            Route::post('support/{ticket}/resolve', [SupportController::class, 'resolve'])
                ->middleware('permission:support.reply')->name('support.resolve');
            Route::post('support/{ticket}/escalate', [SupportController::class, 'escalate'])
                ->middleware('permission:support.escalate')->name('support.escalate');
        });
    });
});
