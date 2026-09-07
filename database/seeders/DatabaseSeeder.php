<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use Database\Seeders\DummyData\DummyDataSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
        $this->call([
//            SiteSettingsSeeder::class,
            PermissionsTableSeeder::class,
            RolesSeeder::class,
            DefaultTeamSeeder::class,
            UserSeeder::class,
        ]);

        // Sample products, orders and customers. This sat in the baseline
        // chain, so `db:seed --force` created demo data in production.
        if (! app()->isProduction()) {
            $this->call(DummyDataSeeder::class);
        }

        $this->call(MenuSeeder::class);
    }
}
