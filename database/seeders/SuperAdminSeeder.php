<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * GFT-001 — the bootstrap Super Admin.
 *
 * There is no self-signup for panel accounts: every admin is created by another admin
 * (docs/02 §2.2), so the first one has to be seeded. Credentials come from the
 * environment; the fallback is a development-only password that must be changed before
 * anything is deployed.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('SUPER_ADMIN_EMAIL', 'super@guftagu.local');
        $name     = env('SUPER_ADMIN_NAME', 'Super Admin');
        $password = env('SUPER_ADMIN_PASSWORD', 'Guftagu@2026');

        $roleId = DB::table('roles')->where('key', 'super_admin')->value('id');

        if ($roleId === null) {
            $this->command->error('SuperAdminSeeder: role `super_admin` is missing — run RoleSeeder first.');

            return;
        }

        $existing = DB::table('admin_users')->where('email', $email)->first();

        if ($existing !== null) {
            // Never silently reset a password that is already in use.
            DB::table('admin_users')->where('id', $existing->id)->update([
                'role_id'    => $roleId,
                'status'     => 'active',
                'updated_at' => now(),
            ]);

            $this->command->warn("Super Admin {$email} already exists — role/status reasserted, password left alone.");

            return;
        }

        DB::table('admin_users')->insert([
            'name'        => $name,
            'email'       => $email,
            'password'    => Hash::make($password),   // bcrypt cost 12, see config/hashing.php
            'role_id'     => $roleId,
            'mfa_enabled' => true,                    // A.1a — MFA on for Super Admin by default
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->command->info("Super Admin created: {$email}");

        if (env('SUPER_ADMIN_PASSWORD') === null) {
            $this->command->warn("Development password in use: {$password} — change it before any deployment.");
        }
    }
}
