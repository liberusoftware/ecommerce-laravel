<?php

namespace Database\Seeders\DummyData;

use App\Models\ProductCollection;
use Illuminate\Database\Seeder;

class ProductCollectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ProductCollection::firstOrCreate(['name' => 'Summer Sale'], ['description' => 'Discounted products for the summer season.']);
        ProductCollection::firstOrCreate(['name' => 'Best Sellers'], ['description' => 'Our most popular products.']);
        ProductCollection::firstOrCreate(['name' => 'New Arrivals'], ['description' => 'Latest products added to our store.']);
        ProductCollection::firstOrCreate(['name' => 'Gift Ideas'], ['description' => 'Perfect gifts for any occasion.']);
    }
}
