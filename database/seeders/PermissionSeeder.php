<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * GFT-114 — the permission catalogue.
 *
 * Source of truth: docs/01 §5.4, with `users.view_pii` added from docs/01 §6
 * ("PII minimisation"), where it is required but missing from the §5.4 extract.
 *
 * Adding a key here is a seeder change. REMOVING one requires a data migration
 * that revokes it everywhere first (docs/02 §2.4) — do not just delete the line.
 *
 * risk_level drives GFT-122: granting a `high` permission requires MFA re-entry.
 */
class PermissionSeeder extends Seeder
{
    /**
     * module => [ action => [display name, risk_level] ]
     */
    public const CATALOGUE = [
        'dashboard' => [
            'view'   => ['View dashboard', 'low'],
            'export' => ['Export dashboard data', 'medium'],
        ],
        'users' => [
            'view'       => ['View users', 'low'],
            'view_pii'   => ['Reveal unmasked phone/email', 'high'],
            'edit'       => ['Edit user profile', 'medium'],
            'suspend'    => ['Suspend a user', 'medium'],
            'ban'        => ['Ban a user', 'high'],
            'kyc_verify' => ['Review and verify KYC', 'high'],
            'level_edit' => ['Adjust user level', 'medium'],
            'vip_edit'   => ['Grant or revoke VIP', 'medium'],
        ],
        'wallet' => [
            'view'          => ['View wallet balances', 'low'],
            'manual_credit' => ['Manually credit a wallet', 'high'],
            'manual_debit'  => ['Manually debit a wallet', 'high'],
            'ledger_view'   => ['View wallet ledger', 'low'],
        ],
        'rooms' => [
            'view'         => ['View rooms', 'low'],
            'monitor_live' => ['Monitor live rooms', 'low'],
            'join_silent'  => ['Join a room invisibly', 'medium'],
            'feature'      => ['Feature a room', 'medium'],
            'pin'          => ['Pin a room', 'medium'],
            'categorise'   => ['Change room category', 'medium'],
            'seat_template_assign' => ['Assign a room\'s seat template', 'medium'],
            'force_close'  => ['Force-close a room', 'high'],
            'seat_lock'    => ['Lock or unlock a seat', 'medium'],
            'seat_vip'     => ['Mark or unmark a seat as VIP', 'medium'],
            'theme_manage' => ['Manage room themes', 'medium'],
        ],
        'moderation' => [
            'live'               => ['Live moderation console', 'low'],
            'mute_user'          => ['Mute a user', 'medium'],
            'kick_user'          => ['Kick a user from a room', 'medium'],
            'warn_user'          => ['Issue a warning', 'medium'],
            'ban_temp'           => ['Temporarily ban a user', 'high'],
            'ban_permanent'      => ['Permanently ban a user', 'high'],
            'bannedwords_manage' => ['Manage banned words', 'medium'],
            'logs_view'          => ['View moderation logs', 'low'],
            'flags_review'       => ['Review filter-flagged content', 'medium'],
            'stats_view'         => ['View moderator activity stats', 'medium'],
            // Undoing another moderator's decision is oversight, not moderation — it is
            // deliberately not in MODERATOR_BASELINE (A.5c).
            'reverse_action'     => ['Reverse a moderation action', 'high'],
        ],
        'reports' => [
            'view'     => ['View reports queue', 'low'],
            'action'   => ['Action a report', 'medium'],
            'escalate' => ['Escalate a report', 'medium'],
            'assign'   => ['Assign a report', 'medium'],
        ],
        'gifts' => [
            'view'            => ['View gift catalogue', 'low'],
            'manage'          => ['Create and edit gifts', 'medium'],
            'category_manage' => ['Manage gift categories', 'medium'],
            'drop_manage'     => ['Manage gift drops', 'medium'],
        ],
        'vip' => [
            'view'   => ['View VIP tiers', 'low'],
            'manage' => ['Manage VIP tiers', 'medium'],
        ],
        'levels' => [
            'view'   => ['View wealth/charm levels', 'low'],
            'manage' => ['Manage wealth/charm levels', 'medium'],
        ],
        'economy' => [
            'rates_manage'      => ['Manage conversion rates', 'high'],
            'packages_manage'   => ['Manage recharge packages', 'medium'],
            'commission_manage' => ['Manage commission slabs', 'high'],
            'ledger_view'       => ['View the economy ledger', 'low'],
            'reconcile'         => ['Run reconciliation', 'high'],
        ],
        'payouts' => [
            'view'          => ['View payouts', 'low'],
            'approve'       => ['Approve a payout', 'high'],
            'reject'        => ['Reject a payout', 'high'],
            'batch_process' => ['Process a payout batch', 'high'],
        ],
        'agency' => [
            'view'               => ['View agencies', 'low'],
            'approve'            => ['Approve an agency', 'medium'],
            'edit'               => ['Edit an agency', 'medium'],
            'target_manage'      => ['Manage agency targets', 'medium'],
            // Raising a settlement is a Manager's job (docs/02 §5); approving and paying
            // one is not — the two-person rule needs them to be separate keys.
            'settlement_raise'   => ['Generate and raise an agency settlement', 'medium'],
            'settlement_process' => ['Approve, batch and pay agency settlements', 'high'],
        ],
        'hosts' => [
            'view'          => ['View hosts', 'low'],
            'approve'       => ['Approve a host application', 'medium'],
            'target_manage' => ['Manage host targets', 'medium'],
            'gift_target_manage' => ['Manage the monthly gift-target ladder and run evaluations', 'medium'],
            'earnings_view' => ['View host earnings', 'low'],
        ],
        'events' => [
            'view'          => ['View events', 'low'],
            'manage'        => ['Create and manage events', 'medium'],
            // B.3a: a Manager schedules a tournament and it stays a draft until an Admin
            // approves it, so publishing is a separate key from creating.
            'approve'       => ['Publish an event to the app', 'high'],
            'reward_manage' => ['Manage event rewards', 'medium'],
        ],
        'rankings' => [
            'view'          => ['View rankings', 'low'],
            'rules_manage'  => ['Manage ranking rules', 'medium'],
            'reward_payout' => ['Pay out ranking rewards', 'high'],
        ],
        'cms' => [
            'banner_manage'       => ['Manage banners', 'medium'],
            // B.3b: a Manager prepares a banner; going live needs an Admin.
            'banner_approve'      => ['Approve a banner for the app', 'high'],
            'announcement_manage' => ['Manage announcements', 'medium'],
            'campaign_send'       => ['Send a push campaign', 'high'],
            'page_manage'         => ['Manage CMS pages', 'medium'],
        ],
        'support' => [
            'view'     => ['View support tickets', 'low'],
            'reply'    => ['Reply to a support ticket', 'medium'],
            'assign'   => ['Assign a support ticket', 'medium'],
            'escalate' => ['Escalate a ticket to an Admin', 'medium'],
            'flag_room' => ['Flag a room to moderation', 'medium'],
            'manage'   => ['Manage saved replies', 'medium'],
        ],
        'reports_export' => [
            'revenue'      => ['Export revenue reports', 'medium'],
            'users'        => ['Export user reports', 'medium'],
            'hosts'        => ['Export host reports', 'medium'],
            'transactions' => ['Export transaction reports', 'medium'],
        ],
        'access' => [
            'admin_manage'     => ['Create and manage panel users', 'high'],
            'role_manage'      => ['Create and manage roles', 'high'],
            'permission_grant' => ['Grant and revoke permissions', 'high'],
            'audit_view'       => ['View audit logs', 'low'],
        ],
        'settings' => [
            'view'   => ['View platform settings', 'low'],
            'manage' => ['Change platform settings', 'high'],
        ],
        // IT Admin's reason to exist: Laravel debug logs and reported frontend errors can
        // carry stack traces, query fragments and request payloads, so this sits behind its
        // own key rather than piggy-backing on `access.audit_view` (which is about who did
        // what, not what broke). Deliberately excluded from the `admin` baseline in
        // RoleSeeder — this is IT Admin / Super Admin territory, not general operations.
        'system' => [
            'logs_view' => ['View debug logs and frontend error reports', 'medium'],
        ],
    ];

    /**
     * Every permission key in the catalogue, flat.
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::CATALOGUE as $module => $actions) {
            foreach (array_keys($actions) as $action) {
                $keys[] = $module.'.'.$action;
            }
        }

        return $keys;
    }

    public function run(): void
    {
        $now = now();
        $rows = [];

        foreach (self::CATALOGUE as $module => $actions) {
            foreach ($actions as $action => $meta) {
                $rows[] = [
                    'key'        => $module.'.'.$action,
                    'module'     => $module,
                    'action'     => $action,
                    'name'       => $meta[0],
                    'risk_level' => $meta[1],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Idempotent by design: re-running updates metadata without reassigning ids,
        // so existing rows in admin_user_permission keep pointing at the same permission.
        DB::table('permissions')->upsert(
            $rows,
            ['key'],
            ['module', 'action', 'name', 'risk_level', 'updated_at']
        );

        $this->command->info('Permissions seeded: '.count($rows).' keys across '.count(self::CATALOGUE).' modules.');
    }
}
