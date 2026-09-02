<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * GFT-006 / GFT-007 — the security settings that drive A.1c and A.1d.
 *
 * These are seeded values, not hardcoded constants: A.1c requires a Super Admin to
 * change the session timeout at runtime, and A.1d requires per-sub-role 2FA toggles.
 * Defaults follow docs/01 §6 ("session timeout configurable, default 60 min idle";
 * "lockout after 5 failures"; "email OTP, 10-min expiry, 5 attempts").
 */
class SettingsSeeder extends Seeder
{
    public const DEFAULTS = [
        // A.1c — idle session expiry
        'security.session_timeout_minutes' => ['60', 'int', 'security', 'Idle minutes before an admin token expires (A.1c).'],

        // A.1a — login lockout
        'security.max_login_attempts' => ['5', 'int', 'security', 'Consecutive failures before lockout (A.1a).'],
        'security.lockout_minutes'    => ['15', 'int', 'security', 'Lockout duration in minutes (A.1a).'],

        // A.1a / GFT-003 — email OTP challenge
        'security.mfa_otp_ttl_minutes' => ['10', 'int', 'security', 'MFA OTP validity in minutes.'],
        'security.mfa_max_attempts'    => ['5', 'int', 'security', 'MFA verification attempts per challenge.'],

        // A.1d — 2FA enforcement per sub-role, toggleable by a Super Admin
        'security.mfa_required.super_admin' => ['1', 'bool', 'security', 'Require MFA at login for Super Admin (A.1d).'],
        'security.mfa_required.admin'       => ['1', 'bool', 'security', 'Require MFA at login for Admin (A.1d).'],
        'security.mfa_required.manager'     => ['0', 'bool', 'security', 'Require MFA at login for Manager (A.1d).'],
        'security.mfa_required.moderator'   => ['0', 'bool', 'security', 'Require MFA at login for Moderator (A.1d).'],

        // A.7b / ⚠ CI-03 — withdrawal policy. Placeholders until the client supplies the
        // real minimum, frequency, KYC threshold and TDS treatment.
        'economy.withdrawal_minimum_diamonds'     => ['1000', 'int', 'economy', 'Fewest diamonds a user may withdraw (CI-03).'],
        'economy.withdrawal_super_approval_paise' => ['5000000', 'int', 'economy', 'Payouts at or above this need a second Super Admin approval (A.7b). 5000000 paise = Rs 50,000.'],

        // GFT-122 — MFA re-entry before granting a `high` risk permission
        'security.mfa_reauth_for_high_risk_grant' => ['1', 'bool', 'security', 'Require MFA re-entry to grant a high-risk permission (GFT-122).'],
        'security.mfa_reauth_window_minutes'      => ['5', 'int', 'security', 'How long an MFA re-auth stays valid.'],
    ];

    public function run(): void
    {
        $now  = now();
        $rows = [];

        foreach (self::DEFAULTS as $key => $meta) {
            [$value, $type, $group, $description] = $meta;

            $rows[] = [
                'key'         => $key,
                'value'       => $value,
                'type'        => $type,
                'group'       => $group,
                'is_public'   => false,
                'description' => $description,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        // Only inserts what is missing — an operator's changed value is never
        // stamped back to the default by a re-seed.
        DB::table('settings')->insertOrIgnore($rows);

        $this->command->info('Settings seeded: '.count($rows).' security keys (existing values preserved).');
    }
}
