<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One account per role, for exercising the delegation UI.
 *
 * Deliberately NOT part of DatabaseSeeder: these are fixtures with known passwords, and
 * they must never appear in a real environment. Run it explicitly:
 *
 *     php artisan db:seed --class=DemoAdminsSeeder
 *
 * Why it exists: nobody can grant permissions to themselves, so a single Super Admin
 * makes the whole A.11 surface untestable — there is no target to grant to.
 */
class DemoAdminsSeeder extends Seeder
{
    public const PASSWORD = 'Guftagu@2026';

    public const ACCOUNTS = [
        ['Ops Admin', 'admin@guftagu.local', Role::ADMIN],
        ['Ops Manager', 'manager@guftagu.local', Role::MANAGER],
        ['Night Moderator', 'moderator@guftagu.local', Role::MODERATOR],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('DemoAdminsSeeder refuses to run outside local/testing.');

            return;
        }

        $superAdminId = AdminUser::query()
            ->whereRelation('role', 'key', Role::SUPER_ADMIN)
            ->value('id');

        foreach (self::ACCOUNTS as [$name, $email, $roleKey]) {
            $roleId = Role::query()->where('key', $roleKey)->value('id');

            if ($roleId === null) {
                $this->command->warn("Role {$roleKey} is missing — run RoleSeeder first.");

                continue;
            }

            $existing = AdminUser::query()->where('email', $email)->first();

            if ($existing !== null) {
                $this->command->warn("{$email} already exists — left alone.");

                continue;
            }

            AdminUser::create([
                'name'       => $name,
                'email'      => $email,
                'password'   => self::PASSWORD,
                'role_id'    => $roleId,
                'status'     => 'active',
                'created_by' => $superAdminId,
                // No per-account opt-in. Note this does NOT disable MFA: the role policy
                // governs and can only be added to, so `admin` still gets a challenge
                // because `security.mfa_required.admin` is seeded true.
                'mfa_enabled' => false,
            ]);

            $mfa = $roleKey === Role::ADMIN ? 'MFA by role policy' : 'no MFA';

            $this->command->info(sprintf('%-18s %-26s %-10s %s', $name, $email, $roleKey, $mfa));
        }

        $this->command->info('Password for all of the above: '.self::PASSWORD);
        $this->command->info('Where MFA applies, read the code from GET /api/v1/admin/dev/last-otp.');
    }
}
