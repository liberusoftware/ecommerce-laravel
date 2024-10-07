<?php

namespace Database\Seeders;

use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

class VehicleModelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vehicleModels = [
            ['name' => 'Toyota Camry', 'brand_id' => 1],
            ['name' => 'Honda Accord', 'brand_id' => 2],
            ['name' => 'Nissan Altima', 'brand_id' => 3],
            ['name' => 'Hyundai Sonata', 'brand_id' => 4],
            ['name' => 'Mazda 6', 'brand_id' => 5],
            ['name' => 'Ford Explorer', 'brand_id' => 6],
            ['name' => 'Jeep Wrangler', 'brand_id' => 7],
            ['name' => 'Toyota RAV4', 'brand_id' => 1],
            ['name' => 'Honda CR-V', 'brand_id' => 2],
            ['name' => 'Chevrolet Tahoe', 'brand_id' => 8],
            ['name' => 'Ford F-150', 'brand_id' => 6],
            ['name' => 'Chevrolet Silverado', 'brand_id' => 8],
            ['name' => 'Ram 1500', 'brand_id' => 9],
            ['name' => 'Toyota Tundra', 'brand_id' => 1],
            ['name' => 'GMC Sierra', 'brand_id' => 10],
            ['name' => 'Tesla Model 3', 'brand_id' => 11],
            ['name' => 'Nissan Leaf', 'brand_id' => 3],
            ['name' => 'Chevrolet Bolt EV', 'brand_id' => 8],
            ['name' => 'Mercedes-Benz S-Class', 'brand_id' => 12],
            ['name' => 'BMW 7 Series', 'brand_id' => 13],
            ['name' => 'Audi A8', 'brand_id' => 14],
            ['name' => 'Lexus LS', 'brand_id' => 15],
            ['name' => 'Harley-Davidson Sportster', 'brand_id' => 16],
            ['name' => 'Yamaha YZF-R3', 'brand_id' => 17],
        ];

        foreach ($vehicleModels as $model) {
            VehicleModel::create([
                'name' => $model['name'],
                'brand_id' => $model['brand_id'],
            ]);
        }
    }
}
