<?php

namespace App\Domain\Users;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\User;
use App\Models\UserSanction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GFT-028 — suspend, ban and unban, each with a mandatory reason (A.3c).
 *
 * A sanction is two things that must not drift apart: the user's `status`, which gates
 * every request, and a `user_sanctions` row, which is the history someone will read months
 * later. Both are written in one transaction.
 */
class SanctionService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /**
     * @throws SanctionException
     */
    public function suspend(User $user, string $reason, ?string $until, AdminUser $actor): UserSanction
    {
        $expiresAt = $this->parseUntil($until);

        return $this->apply(
            $user,
            UserSanction::TEMP_BAN,
            User::STATUS_SUSPENDED,
            $reason,
            $expiresAt,
            $actor,
            'user.suspend',
        );
    }

    /**
     * @throws SanctionException
     */
    public function ban(User $user, string $reason, AdminUser $actor): UserSanction
    {
        return $this->apply(
            $user,
            UserSanction::PERMANENT_BAN,
            User::STATUS_BANNED,
            $reason,
            null,
            $actor,
            'user.ban',
        );
    }

    /**
     * @throws SanctionException
     */
    public function unban(User $user, string $reason, AdminUser $actor): void
    {
        if ($user->status === User::STATUS_ACTIVE) {
            throw new SanctionException('BAD_REQUEST', 'That account is already active.', 400);
        }

        $this->requireReason($reason);

        DB::transaction(function () use ($user, $reason, $actor) {
            UserSanction::query()
                ->where('user_id', $user->id)
                ->active()
                ->update([
                    'is_active'  => false,
                    'revoked_by' => $actor->id,
                    'revoked_at' => now(),
                ]);

            $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
        });

        $this->audit->log(
            $actor,
            'user.unban',
            'users',
            User::class,
            $user->id,
            ['status' => $user->getOriginal('status')],
            ['status' => User::STATUS_ACTIVE, 'reason' => $reason],
        );
    }

    /**
     * @throws SanctionException
     */
    protected function apply(
        User $user,
        string $type,
        string $status,
        string $reason,
        ?Carbon $expiresAt,
        AdminUser $actor,
        string $auditAction,
    ): UserSanction {
        $this->requireReason($reason);

        $before = $user->status;

        $sanction = DB::transaction(function () use ($user, $type, $status, $reason, $expiresAt, $actor) {
            // Supersede whatever was in force, so a user is never under two live sanctions
            // and the history reads as a sequence rather than a pile.
            UserSanction::query()
                ->where('user_id', $user->id)
                ->active()
                ->update(['is_active' => false, 'revoked_by' => $actor->id, 'revoked_at' => now()]);

            $sanction = UserSanction::create([
                'user_id'    => $user->id,
                'type'       => $type,
                'scope'      => 'global',
                'reason'     => trim($reason),
                'issued_by'  => $actor->id,
                'starts_at'  => now(),
                'expires_at' => $expiresAt,
                'is_active'  => true,
            ]);

            $user->forceFill(['status' => $status])->save();

            // Ends every live session immediately — a banned user must not keep working
            // off a token they already hold.
            $user->tokens()->delete();

            return $sanction;
        });

        $this->audit->log(
            $actor,
            $auditAction,
            'users',
            User::class,
            $user->id,
            ['status' => $before],
            [
                'status'     => $status,
                'reason'     => $sanction->reason,
                'expires_at' => $expiresAt?->toIso8601ZuluString(),
            ],
        );

        return $sanction;
    }

    /**
     * @throws SanctionException
     */
    protected function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new SanctionException('VALIDATION_ERROR', 'A reason is required.', 422);
        }
    }

    /**
     * @throws SanctionException
     */
    protected function parseUntil(?string $until): ?Carbon
    {
        if ($until === null || $until === '') {
            return null;
        }

        try {
            $when = Carbon::parse($until);
        } catch (\Throwable) {
            throw new SanctionException('VALIDATION_ERROR', 'That is not a valid date.', 422);
        }

        if ($when->isPast()) {
            throw new SanctionException('VALIDATION_ERROR', 'The end of a suspension must be in the future.', 422);
        }

        return $when;
    }
}
