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
            ['name' => 'Toyota Camry', 'brand_id' => 1], // Toyota
            ['name' => 'Honda Accord', 'brand_id' => 2], // Honda
            ['name' => 'Nissan Altima', 'brand_id' => 3], // Nissan
            ['name' => 'Hyundai Sonata', 'brand_id' => 4], // Hyundai
            ['name' => 'Mazda 6', 'brand_id' => 5], // Mazda
            ['name' => 'Ford Explorer', 'brand_id' => 6], // Ford
            ['name' => 'Jeep Wrangler', 'brand_id' => 7], // Jeep
            ['name' => 'Toyota RAV4', 'brand_id' => 1], // Toyota
            ['name' => 'Honda CR-V', 'brand_id' => 2], // Honda
            ['name' => 'Chevrolet Tahoe', 'brand_id' => 8], // Chevrolet
            ['name' => 'Ford F-150', 'brand_id' => 6], // Ford
            ['name' => 'Chevrolet Silverado', 'brand_id' => 8], // Chevrolet
            ['name' => 'Ram 1500', 'brand_id' => 9], // Ram
            ['name' => 'Toyota Tundra', 'brand_id' => 1], // Toyota
            ['name' => 'GMC Sierra', 'brand_id' => 10], // GMC
            ['name' => 'Tesla Model 3', 'brand_id' => 11], // Tesla
            ['name' => 'Nissan Leaf', 'brand_id' => 3], // Nissan
            ['name' => 'Chevrolet Bolt EV', 'brand_id' => 8], // Chevrolet
            ['name' => 'Mercedes-Benz S-Class', 'brand_id' => 12], // Mercedes-Benz
            ['name' => 'BMW 7 Series', 'brand_id' => 13], // BMW
            ['name' => 'Audi A8', 'brand_id' => 14], // Audi
            ['name' => 'Lexus LS', 'brand_id' => 15], // Lexus
            ['name' => 'Harley-Davidson Sportster', 'brand_id' => 16], // Harley-Davidson
            ['name' => 'Yamaha YZF-R3', 'brand_id' => 17], // Yamaha
        ];

        foreach ($vehicleModels as $model) {
            VehicleModel::create([
                'name' => $model['name'],
                'brand_id' => $model['brand_id'],
            ]);
        }
    }
}
