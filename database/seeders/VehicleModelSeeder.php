<?php

namespace Database\Seeders;

use App\Models\Product; 
use Illuminate\Database\Seeder;

class VehicleModelSeeder extends Seeder
{
    public function run()
    {
      
        Product::firstOrCreate(['slug' => 'bmw-v3'], [
            'name' => 'BMW V3',
            'description' => 'Description for BMW V3',
            'price' => 30000,
            'category_id' => 1, 
            'featured_image' => 'path/to/bmw_v3.jpg',
            'inventory_count' => 10,
            'low_stock_threshold' => 5,
        ]);

        Product::firstOrCreate(['slug' => 'bmw-v8'], [
            'name' => 'BMW V8',
            'description' => 'Description for BMW V8',
            'price' => 50000,
            'category_id' => 1, 
            'featured_image' => 'path/to/bmw_v8.jpg', // Remplacez par le chemin d'image correct
            'inventory_count' => 8,
            'low_stock_threshold' => 3,
        ]);

        Product::firstOrCreate(['slug' => 'audi-a4'], [
            'name' => 'Audi A4',
            'description' => 'Description for Audi A4',
            'price' => 35000,
            'category_id' => 1,
            'featured_image' => 'path/to/audi_a4.jpg',
            'inventory_count' => 12,
            'low_stock_threshold' => 4,
        ]);

        Product::firstOrCreate(['slug' => 'mercedes-benz-c-class'], [
            'name' => 'Mercedes-Benz C-Class',
            'description' => 'Description for Mercedes-Benz C-Class',
            'price' => 40000,
            'category_id' => 1,
            'featured_image' => 'path/to/mercedes_benz_c_class.jpg',
            'inventory_count' => 7,
            'low_stock_threshold' => 2,
        ]);

        Product::firstOrCreate(['slug' => 'toyota-corolla'], [
            'name' => 'Toyota Corolla',
            'description' => 'Description for Toyota Corolla',
            'price' => 20000,
            'category_id' => 2,
            'featured_image' => 'path/to/toyota_corolla.jpg',
            'inventory_count' => 15,
            'low_stock_threshold' => 6,
        ]);

        Product::firstOrCreate(['slug' => 'nissan-altima'], [
            'name' => 'Nissan Altima',
            'description' => 'Description for Nissan Altima',
            'price' => 25000,
            'category_id' => 2,
            'featured_image' => 'path/to/nissan_altima.jpg',
            'inventory_count' => 9,
            'low_stock_threshold' => 4,
        ]);

        Product::firstOrCreate(['slug' => 'ford-fusion'], [
            'name' => 'Ford Fusion',
            'description' => 'Description for Ford Fusion',
            'price' => 23000,
            'category_id' => 2,
            'featured_image' => 'path/to/ford_fusion.jpg',
            'inventory_count' => 11,
            'low_stock_threshold' => 5,
        ]);

        Product::firstOrCreate(['slug' => 'honda-civic'], [
            'name' => 'Honda Civic',
            'description' => 'Description for Honda Civic',
            'price' => 22000,
            'category_id' => 2,
            'featured_image' => 'path/to/honda_civic.jpg',
            'inventory_count' => 13,
            'low_stock_threshold' => 5,
        ]);

        Product::firstOrCreate(['slug' => 'chevrolet-malibu'], [
            'name' => 'Chevrolet Malibu',
            'description' => 'Description for Chevrolet Malibu',
            'price' => 21000,
            'category_id' => 2,
            'featured_image' => 'path/to/chevrolet_malibu.jpg',
            'inventory_count' => 10,
            'low_stock_threshold' => 5,
        ]);

        Product::firstOrCreate(['slug' => 'hyundai-sonata'], [
            'name' => 'Hyundai Sonata',
            'description' => 'Description for Hyundai Sonata',
            'price' => 24000,
            'category_id' => 2,
            'featured_image' => 'path/to/hyundai_sonata.jpg',
            'inventory_count' => 14,
            'low_stock_threshold' => 5,
        ]);
    }
}
