<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $configured = config('seeding.admin_password');

        // A generated password is only usable if it is printed, and printing it
        // outside a developer's own machine writes a working super_admin
        // credential into a shared log — `install.yml` runs `db:seed --force`
        // (#940). So off `local` the password has to be configured, and without
        // one there is no account. That is the honest outcome: the alternative
        // is a super_admin nobody can log into, sitting in the database looking
        // like a working account.
        if (blank($configured) && ! app()->environment('local')) {
            $this->command?->warn('UserSeeder: no super_admin created. Set SEED_ADMIN_PASSWORD to create one outside local.');

            return;
        }

        $adminPassword = $configured ?: Str::random(12);

        $adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make($adminPassword),
            'email_verified_at' => now(),
        ]);

        $team = Team::firstOrFail();
        $adminUser->teams()->syncWithoutDetaching([$team->id]);

        $role = Role::where('name', 'super_admin')->firstOrFail();
        $adminUser->assignRole($role);

        // Only what this seeder generated, and only on `local`. A configured
        // password is already known to whoever set it, so printing it would
        // leak it without telling anyone anything.
        if (blank($configured)) {
            $this->command?->info("Admin password: {$adminPassword}");
        }
    }
}
