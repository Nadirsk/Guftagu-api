<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Users\SanctionService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserKyc;
use App\Models\WealthCharmLevel;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Epic A.3 — user management. docs/03 §10.
 *
 * Every list and detail response is masked. The unmasked value has its own permission and
 * its own endpoint, so seeing a real phone number is always a deliberate, recorded act.
 */
class UserController extends Controller
{
    protected const SORTABLE = ['id', 'guftagu_id', 'status', 'created_at', 'last_active_at'];

    public function __construct(
        protected WalletService $wallets,
        protected SanctionService $sanctions,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * GET /admin/users — GFT-023.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'status'   => ['sometimes', 'nullable', Rule::in(['active', 'suspended', 'banned', 'deleted'])],
            'kyc'      => ['sometimes', 'nullable', Rule::in(['pending', 'verified', 'rejected', 'none'])],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort'     => ['sometimes', 'string', 'max:50'],
        ]);

        [$column, $direction] = $this->parseSort($data['sort'] ?? '-created_at');

        $query = User::query()
            // kyc columns are table-qualified: the relation uses latestOfMany(), which
            // joins a subquery, and an unqualified `user_id` is then ambiguous.
            ->with([
                'profile:id,user_id,display_name,avatar_url,country',
                'wallet',
                'kyc:user_kyc.id,user_kyc.user_id,user_kyc.status',
                // Feeds effective_status without an EXISTS query per row.
                'activeSanctions:id,user_id,type,expires_at',
            ])
            ->when($data['q'] ?? null, fn ($q, string $term) => $q->search($term))
            ->status($data['status'] ?? null)
            ->when($data['kyc'] ?? null, function ($q, string $kyc) {
                return $kyc === 'none'
                    ? $q->whereDoesntHave('kyc')
                    : $q->whereHas('kyc', fn ($k) => $k->where('status', $kyc));
            })
            ->orderBy($column, $direction);

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 20),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (User $user) => $this->rowPayload($user)
        )->all());
    }

    /**
     * GET /admin/users/{user} — GFT-024, the detail aggregate.
     */
    public function show(User $user): JsonResponse
    {
        $user->load([
            'profile',
            'kyc.reviewer:id,name',
            'devices' => fn ($q) => $q->orderByDesc('last_seen_at')->limit(10),
            'sanctions' => fn ($q) => $q->with('issuer:id,name')->limit(20),
        ]);

        $wallet = $this->wallets->forUser($user);

        return ApiResponse::success([
            'user'    => $this->rowPayload($user),
            'profile' => $user->profile === null ? null : [
                'display_name'  => $user->profile->display_name,
                'bio'           => $user->profile->bio,
                'gender'        => $user->profile->gender,
                'date_of_birth' => $user->profile->date_of_birth?->toDateString(),
                'country'       => $user->profile->country,
                'city'          => $user->profile->city,
                'language'      => $user->profile->language,
                'avatar_url'    => $user->profile->avatar_url,
            ],
            'wallet' => [
                'coin_balance'             => $wallet->coin_balance,
                'diamond_balance'          => $wallet->diamond_balance,
                'frozen_coins'             => $wallet->frozen_coins,
                'frozen_diamonds'          => $wallet->frozen_diamonds,
                'lifetime_coins_purchased' => $wallet->lifetime_coins_purchased,
                'lifetime_coins_spent'     => $wallet->lifetime_coins_spent,
                'lifetime_diamonds_earned' => $wallet->lifetime_diamonds_earned,
                'is_frozen'                => $wallet->is_frozen,
                // Derived from the ladder against the counters above, unless GFT-027
                // overrode it — never a stored, independently-driftable field.
                'wealth_level' => $this->levelPayload($wallet->wealthLevel(), $wallet->wealth_level_override_id !== null),
                'charm_level'  => $this->levelPayload($wallet->charmLevel(), $wallet->charm_level_override_id !== null),
            ],
            'kyc' => $user->kyc === null ? null : [
                'id'               => $user->kyc->id,
                'status'           => $user->kyc->status,
                'full_name'        => $user->kyc->full_name,
                'doc_type'         => $user->kyc->doc_type,
                'doc_number'       => $user->kyc->maskedDocNumber(),
                'doc_front_url'    => $user->kyc->doc_front_url,
                'doc_back_url'     => $user->kyc->doc_back_url,
                'selfie_url'       => $user->kyc->selfie_url,
                'ifsc'             => $user->kyc->ifsc,
                'upi_id'           => $user->kyc->upi_id,
                'reviewed_by'      => $user->kyc->reviewer?->name,
                'reviewed_at'      => $user->kyc->reviewed_at?->toIso8601ZuluString(),
                'rejection_reason' => $user->kyc->rejection_reason,
                'submitted_at'     => $user->kyc->created_at?->toIso8601ZuluString(),
            ],
            'devices' => $user->devices->map(fn ($device) => [
                'platform'     => $device->platform,
                'app_version'  => $device->app_version,
                'os_version'   => $device->os_version,
                'last_seen_at' => $device->last_seen_at?->toIso8601ZuluString(),
                'is_active'    => $device->is_active,
            ]),
            'sanctions' => $user->sanctions->map(fn ($sanction) => [
                'id'         => $sanction->id,
                'type'       => $sanction->type,
                'reason'     => $sanction->reason,
                'issued_by'  => $sanction->issuer?->name,
                'starts_at'  => $sanction->starts_at?->toIso8601ZuluString(),
                'expires_at' => $sanction->expires_at?->toIso8601ZuluString(),
                'revoked_at' => $sanction->revoked_at?->toIso8601ZuluString(),
                // The stored flag, and whether it still bites. A 24-hour ban whose window has
                // passed keeps `is_active = true` until the reconciling job runs, so
                // reporting only the flag showed lapsed bans as live here.
                'is_active'  => $sanction->is_active && $sanction->revoked_at === null,
                'in_force'   => $sanction->is_active
                    && $sanction->revoked_at === null
                    && ($sanction->expires_at === null || $sanction->expires_at->isFuture()),
            ]),
            // Rooms and reports arrive with A.4 and A.5; named here so the panel can show
            // an honest "not built yet" rather than an empty list that looks like no data.
            'pending' => ['rooms' => true, 'reports' => true],
        ]);
    }

    /**
     * GET /admin/users/{user}/pii — GFT-025.
     *
     * The whole point of this endpoint is that it is recorded. Masking everywhere else is
     * only meaningful if the way around it leaves a trail.
     */
    public function pii(Request $request, User $user): JsonResponse
    {
        $this->audit->log(
            $request->user(),
            'user.pii_viewed',
            'users',
            User::class,
            $user->id,
            null,
            ['fields' => ['phone', 'email']],
        );

        return ApiResponse::success([
            'phone' => $user->phone,
            'email' => $user->email,
        ], 'This access has been recorded');
    }

    /**
     * PATCH /admin/users/{user} — GFT-027 covers level/VIP; this is the profile edit.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:50'],
            'bio'          => ['sometimes', 'nullable', 'string', 'max:300'],
            'country'      => ['sometimes', 'nullable', 'string', 'max:80'],
            'city'         => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $profile = $user->profile()->firstOrCreate(
            ['user_id' => $user->id],
            ['display_name' => $user->guftagu_id],
        );

        $before = $profile->only(array_keys($data));
        $profile->fill($data)->save();

        $this->audit->log($request->user(), 'user.update', 'users', User::class, $user->id, $before, $data);

        return ApiResponse::success(null, 'User updated');
    }

    /** POST /admin/users/{user}/suspend — A.3c. */
    public function suspend(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'until'  => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $sanction = $this->sanctions->suspend($user, $data['reason'], $data['until'] ?? null, $request->user());

        return ApiResponse::success([
            'status'     => $user->fresh()->status,
            'expires_at' => $sanction->expires_at?->toIso8601ZuluString(),
        ], 'User suspended');
    }

    /** POST /admin/users/{user}/ban. */
    public function ban(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->sanctions->ban($user, $data['reason'], $request->user());

        return ApiResponse::success(['status' => $user->fresh()->status], 'User banned');
    }

    /** POST /admin/users/{user}/unban. */
    public function unban(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->sanctions->unban($user, $data['reason'], $request->user());

        return ApiResponse::success(['status' => $user->fresh()->status], 'User reinstated');
    }

    /** POST /admin/users/{user}/kyc/verify — A.3b, GFT-026. */
    public function reviewKyc(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'rejected'])],
            'reason'   => ['required_if:decision,rejected', 'nullable', 'string', 'max:500'],
        ]);

        $kyc = $user->kyc;

        if ($kyc === null) {
            return ApiResponse::error('NOT_FOUND', 'This user has not submitted KYC.', null, 404);
        }

        if ($kyc->status !== UserKyc::PENDING) {
            return ApiResponse::error(
                'BAD_REQUEST',
                'That submission has already been reviewed.',
                ['status' => $kyc->status],
                400,
            );
        }

        $before = $kyc->status;

        $kyc->forceFill([
            'status'           => $data['decision'],
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'rejection_reason' => $data['decision'] === UserKyc::REJECTED ? $data['reason'] : null,
        ])->save();

        $this->audit->log(
            $request->user(),
            'user.kyc_review',
            'users',
            UserKyc::class,
            $kyc->id,
            ['status' => $before],
            ['status' => $kyc->status, 'reason' => $kyc->rejection_reason],
        );

        return ApiResponse::success(
            ['status' => $kyc->status],
            $kyc->status === UserKyc::VERIFIED
                ? 'KYC verified — this user can now withdraw'
                : 'KYC rejected',
        );
    }

    /**
     * POST /admin/users/{user}/level-override — GFT-027, the level half. Sending a null
     * `level_id` clears the override, which returns the user to whatever their wallet's
     * lifetime counters resolve to on the current ladder.
     */
    public function overrideLevel(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'type'     => ['required', Rule::in(WealthCharmLevel::TYPES)],
            'level_id' => ['nullable', 'integer', Rule::exists('wealth_charm_levels', 'id')->where('type', $request->input('type'))],
        ]);

        $levelId = $data['level_id'] ?? null;
        $wallet = $this->wallets->setLevelOverride($user, $data['type'], $levelId, $request->user());

        $level = $data['type'] === 'charm' ? $wallet->charmLevel() : $wallet->wealthLevel();
        $isOverride = $data['type'] === 'charm'
            ? $wallet->charm_level_override_id !== null
            : $wallet->wealth_level_override_id !== null;

        return ApiResponse::success(
            $this->levelPayload($level, $isOverride),
            $levelId === null ? 'Override cleared — level is derived again' : 'Level overridden',
        );
    }

    // ----------------------------------------------------------------- internals

    protected function parseSort(string $sort): array
    {
        $descending = str_starts_with($sort, '-');
        $column = ltrim($sort, '-');

        if (! in_array($column, self::SORTABLE, true)) {
            return ['created_at', 'desc'];
        }

        return [$column, $descending ? 'desc' : 'asc'];
    }

    /**
     * Masked by default, always. `users.view_pii` does not widen this payload — it has its
     * own endpoint, so seeing a real phone number is always a deliberate, recorded act.
     */
    protected function rowPayload(User $user): array
    {
        return [
            'id'             => $user->id,
            'uuid'           => $user->uuid,
            'guftagu_id'     => $user->guftagu_id,
            'display_name'   => $user->profile?->display_name,
            'avatar_url'     => $user->profile?->avatar_url,
            'country'        => $user->profile?->country,
            'phone_masked'   => $user->maskedPhone(),
            'email_masked'   => $user->maskedEmail(),
            'status'         => $user->status,
            // What the column says vs what is actually in force. A 24-hour suspension whose
            // window has passed still reads `suspended` until the reconciling job runs, but
            // the person can already log in — showing only the column would have the panel
            // contradict the platform.
            'effective_status' => $user->effectiveStatus(),
            'kyc_status'     => $user->kyc?->status ?? 'none',
            'coin_balance'   => $user->wallet?->coin_balance ?? 0,
            'diamond_balance' => $user->wallet?->diamond_balance ?? 0,
            'wallet_frozen'  => (bool) ($user->wallet?->is_frozen ?? false),
            'last_active_at' => $user->last_active_at?->toIso8601ZuluString(),
            'created_at'     => $user->created_at?->toIso8601ZuluString(),
        ];
    }

    protected function levelPayload(?WealthCharmLevel $level, bool $isOverride): array
    {
        return [
            'id'          => $level?->id,
            'level'       => $level?->level,
            'name_en'     => $level?->name_en,
            'badge_url'   => $level?->badge_url,
            'is_override' => $isOverride,
        ];
    }
}
