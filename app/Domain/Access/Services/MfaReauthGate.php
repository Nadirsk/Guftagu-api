<?php

namespace App\Domain\Access\Services;

use App\Domain\Settings\SettingsRepository;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Cache;

/**
 * GFT-122 — "granting a `high` risk_level permission requires MFA re-entry" (docs/02 §2.4).
 *
 * A successful `reauth` OTP verification stamps a short-lived marker; the grant action
 * checks it. Kept in Redis rather than on the token so it expires on its own and cannot
 * be replayed after the window.
 */
class MfaReauthGate
{
    public function __construct(protected SettingsRepository $settings)
    {
    }

    public function isRequired(): bool
    {
        return $this->settings->bool('security.mfa_reauth_for_high_risk_grant', true);
    }

    public function isSatisfied(AdminUser $admin): bool
    {
        if (! $this->isRequired()) {
            return true;
        }

        return Cache::get($this->key($admin->id)) !== null;
    }

    public function markSatisfied(AdminUser $admin): void
    {
        $minutes = $this->settings->int('security.mfa_reauth_window_minutes', 5);

        Cache::put($this->key($admin->id), now()->toIso8601ZuluString(), now()->addMinutes($minutes));
    }

    /**
     * Consumed on use would be safer still, but the window is short and a grant call
     * may legitimately cover several permissions in sequence from one UI action.
     */
    public function clear(AdminUser $admin): void
    {
        Cache::forget($this->key($admin->id));
    }

    public function expiresInMinutes(): int
    {
        return $this->settings->int('security.mfa_reauth_window_minutes', 5);
    }

    protected function key(int $adminId): string
    {
        return "mfa:reauth:{$adminId}";
    }
}
