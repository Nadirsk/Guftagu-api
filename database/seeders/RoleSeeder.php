<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * GFT-001 — the four panel roles and their permission baselines.
 *
 * Baselines are transcribed from docs/02 §2.4 "Role baselines". Everything not in a
 * baseline is granted individually through the delegation flow (A.11) — that is the
 * whole point of the model, and why `moderator` is deliberately thin.
 */
class RoleSeeder extends Seeder
{
    public const ROLES = [
        'super_admin' => ['Super Admin', 'Unrestricted access. Cannot be scoped or limited.'],
        'admin'       => ['Admin', 'Full operational access except role management and platform settings.'],
        'manager'     => ['Manager', 'Agency, host and event operations within an assigned scope.'],
        'moderator'   => ['Moderator', 'Live room monitoring and reporting. Enforcement is granted individually.'],
    ];

    /**
     * Permissions the `admin` baseline deliberately excludes (docs/02 §2.4).
     */
    public const ADMIN_EXCLUDES = [
        'access.role_manage',
        'settings.manage',
        'economy.rates_manage',
    ];

    public const MANAGER_BASELINE = [
        'dashboard.view',
        'users.view',
        'rooms.view',
        'rooms.monitor_live',
        // agency.* minus the two approvals. docs/02 §5 loosely says "agency.*", but B.2a
        // gives the testable rule and wins: a Manager onboards an agency and cannot then
        // approve their own submission. Same reason settlement_process is excluded — the
        // two-person rule is the whole point, and a baseline that hands somebody both
        // halves of it quietly removes it.
        'agency.view',
        'agency.edit',
        'agency.target_manage',
        'agency.settlement_raise',
        // hosts.* except approve
        'hosts.view',
        'hosts.target_manage',
        'hosts.earnings_view',
        'events.view',
        'events.manage',
        'cms.banner_manage',
        // B.4 is a Manager epic, so first-level support is baseline for them.
        'support.view',
        'support.reply',
        'support.assign',
        'support.escalate',
        'support.flag_room',
        'reports_export.revenue',
        'reports_export.users',
        'reports_export.hosts',
        'reports_export.transactions',
    ];

    /**
     * Deliberately thin. A Moderator's actual enforcement powers
     * (moderation.mute_user, kick_user, ban_temp, rooms.force_close, reports.action)
     * are granted individually by a Super Admin or an Admin.
     */
    public const MODERATOR_BASELINE = [
        'rooms.view',
        'rooms.monitor_live',
        'rooms.join_silent',
        'reports.view',
        'moderation.logs_view',
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::ROLES as $key => $meta) {
            DB::table('roles')->upsert([[
                'key'         => $key,
                'name'        => $meta[0],
                'description' => $meta[1],
                'is_system'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]], ['key'], ['name', 'description', 'is_system', 'updated_at']);
        }

        $roleIds    = DB::table('roles')->pluck('id', 'key');
        $permIds    = DB::table('permissions')->pluck('id', 'key');
        $allKeys    = PermissionSeeder::keys();

        $baselines = [
            // super_admin gets NO rows — it short-circuits in PermissionResolver.
            // Giving it rows would create a second source of truth that could drift.
            'super_admin' => [],
            'admin'       => array_values(array_diff($allKeys, self::ADMIN_EXCLUDES)),
            'manager'     => self::MANAGER_BASELINE,
            'moderator'   => self::MODERATOR_BASELINE,
        ];

        foreach ($baselines as $roleKey => $keys) {
            $roleId = $roleIds[$roleKey] ?? null;

            if ($roleId === null) {
                continue;
            }

            // Rebuild the baseline wholesale so a changed baseline actually takes effect.
            // Direct grants live in admin_user_permission and are untouched by this.
            DB::table('role_permission')->where('role_id', $roleId)->delete();

            $rows = [];

            foreach ($keys as $key) {
                if (! isset($permIds[$key])) {
                    $this->command->warn("RoleSeeder: unknown permission key '{$key}' — skipped.");

                    continue;
                }

                $rows[] = ['role_id' => $roleId, 'permission_id' => $permIds[$key]];
            }

            if ($rows !== []) {
                DB::table('role_permission')->insert($rows);
            }

            $this->command->info(sprintf('Role %-12s baseline: %d permissions.', $roleKey, count($rows)));
        }
    }
}
