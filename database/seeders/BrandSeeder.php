<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brands = [
            ['name' => 'Toyota'],
            ['name' => 'Honda'],
            ['name' => 'Nissan'],
            ['name' => 'Hyundai'],
            ['name' => 'Mazda'],
            ['name' => 'Ford'],
            ['name' => 'Jeep'],
            ['name' => 'Chevrolet'],
            ['name' => 'Ram'],
            ['name' => 'GMC'],
            ['name' => 'Tesla'],
            ['name' => 'Mercedes-Benz'],
            ['name' => 'BMW'],
            ['name' => 'Audi'],
            ['name' => 'Lexus'],
            ['name' => 'Harley-Davidson'],
            ['name' => 'Yamaha'],
        ];

        DB::table('brands')->insert($brands);
    }
}
