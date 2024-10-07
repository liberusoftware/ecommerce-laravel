<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Liste des marques de véhicules à insérer
        $brands = [
            ['name' => 'BMW'],
            ['name' => 'Toyota'],
            ['name' => 'Nissan'],
            ['name' => 'Ford'],
            ['name' => 'Chevrolet'],
            ['name' => 'Honda'],
            ['name' => 'Mercedes-Benz'],
            ['name' => 'Volkswagen'],
            ['name' => 'Hyundai'],
            ['name' => 'Kia'],
            ['name' => 'Subaru'],
            ['name' => 'Mazda'],
        ];

        DB::table('brands')->insert($brands);
    }
}
