<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DefaultTeamSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The seeded super_admin is either usable or absent — never a ghost.
 *
 * `UserSeeder` used to generate a random password and print it, which put a
 * working credential into the `install.yml` log on every run (#940). Gating the
 * print on `local` closed that, but left the other half: off `local` the
 * password was still generated and now never shown, so every staging or demo
 * install ended up with an `admin@example.com` super_admin that nobody could
 * log into and everybody could see.
 *
 * The environment here is `testing`, so these run down the off-`local` branch,
 * which is the one that matters.
 */
class SeededAdminCredentialTest extends TestCase
{
    use RefreshDatabase;

    private function seedPrerequisites(): void
    {
        $this->seed(PermissionsTableSeeder::class);
        $this->seed(RolesSeeder::class);
        $this->seed(DefaultTeamSeeder::class);
    }

    public function test_a_configured_password_produces_an_admin_that_can_be_logged_into(): void
    {
        config(['seeding.admin_password' => 'a-known-password']);
        $this->seedPrerequisites();

        $this->seed(UserSeeder::class);

        $admin = User::where('email', 'admin@example.com')->first();

        $this->assertNotNull($admin, 'No super_admin was created despite a configured password.');
        $this->assertTrue(Hash::check('a-known-password', $admin->password));
        $this->assertTrue($admin->hasRole('super_admin'));
    }

    public function test_no_admin_is_created_when_no_password_is_configured(): void
    {
        config(['seeding.admin_password' => null]);
        $this->seedPrerequisites();

        $this->seed(UserSeeder::class);

        $this->assertNull(
            User::where('email', 'admin@example.com')->first(),
            'A super_admin was created with a password nobody knows.',
        );
    }
}
