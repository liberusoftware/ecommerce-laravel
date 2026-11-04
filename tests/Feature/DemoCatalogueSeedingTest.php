<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A site with its own catalogue seeds the platform without the sample one. */
class DemoCatalogueSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_catalogue_can_be_switched_off(): void
    {
        config(['seeding.admin_password' => 'a-known-password', 'seeding.demo_catalogue' => false]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertGreaterThan(0, DB::table('menu_items')->count());
    }
}
