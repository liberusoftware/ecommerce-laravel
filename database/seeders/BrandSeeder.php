<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Schema::hasTable('brands')) {
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

            foreach ($brands as $brand) {
                DB::table('brands')->updateOrInsert(['name' => $brand['name']], $brand);
            }
        } else {
            $this->command->error('Table "brands" does not exist!');
        }
    }
}
