<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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

        $adminPassword = Str::random(12);
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

        // Only ever printed on a developer's own machine. `install.yml` runs
        // `db:seed --force`, so printing unconditionally put a working admin
        // password into a CI log on every push.
        if (app()->environment('local')) {
            $this->command->info("Admin password: {$adminPassword}");
        }
    }
}
