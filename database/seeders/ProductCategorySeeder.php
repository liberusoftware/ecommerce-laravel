<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ProductCategory::firstOrCreate([
            'slug' => 'cars'
        ], [
            'name' => 'Cars',
            'description' => 'Category for all types of cars',
            'parent_category_id' => null,
        ]);

        ProductCategory::firstOrCreate([
            'slug' => 'motorbikes'
        ], [
            'name' => 'Motorbikes',
            'description' => 'Category for all types of motorbikes',
            'parent_category_id' => null,
        ]);

        ProductCategory::firstOrCreate([
            'slug' => 'vans'
        ], [
            'name' => 'Vans',
            'description' => 'Category for vans',
            'parent_category_id' => null,
        ]);

        ProductCategory::firstOrCreate([
            'slug' => 'trucks'
        ], [
            'name' => 'Trucks',
            'description' => 'Category for trucks',
            'parent_category_id' => null,
        ]);

        ProductCategory::firstOrCreate([
            'slug' => 'bicycles'
        ], [
            'name' => 'Bicycles',
            'description' => 'Category for bicycles',
            'parent_category_id' => null,
        ]);
        
        ProductCategory::firstOrCreate([
            'slug' => 'suvs'
        ], [
            'name' => 'SUVs',
            'description' => 'Category for SUVs',
            'parent_category_id' => null,
        ]);

        ProductCategory::firstOrCreate([
            'slug' => 'electric-cars'
        ], [
            'name' => 'Electric Cars',
            'description' => 'Category for electric vehicles',
            'parent_category_id' => null,
        ]);
    }
}
