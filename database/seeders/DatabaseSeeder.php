<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the super admin from SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD
     * in .env. Safe to run again: it updates the same user.
     */
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL');
        $password = env('SUPER_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD not set; no super admin created.');

            return;
        }

        $admin = User::firstOrNew(['email' => $email]);
        $admin->name = env('SUPER_ADMIN_NAME', 'Super Admin');
        $admin->password = $password;
        $admin->role = Role::SuperAdmin;
        $admin->client_id = null;
        $admin->email_verified_at ??= now();
        $admin->save();
    }
}
