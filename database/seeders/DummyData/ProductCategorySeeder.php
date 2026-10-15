<?php

namespace Database\Seeders\DummyData;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    /**
     * Define the product categories to seed.
     * Each category can be easily customized or extended.
     */
    private array $categories = [
        [
            'name' => 'Electronics',
            'slug' => 'electronics',
            'description' => 'Gadgets, devices, and home appliances.',
        ],
        [
            'name' => 'Clothing',
            'slug' => 'clothing',
            'description' => 'Men\'s, women\'s, and kids\' fashion.',
        ],
        [
            'name' => 'Books',
            'slug' => 'books',
            'description' => 'Fiction, non-fiction, and educational books.',
        ],
        [
            'name' => 'Home & Living',
            'slug' => 'home',
            'description' => 'Furniture, decor, and essentials for every room.',
        ],
        [
            'name' => 'Beauty',
            'slug' => 'beauty',
            'description' => 'Skincare, cosmetics, and personal care products.',
        ],
        [
            'name' => 'Sports',
            'slug' => 'sports',
            'description' => 'Sporting goods, fitness gear, and outdoor equipment.',
        ],
        [
            'name' => 'Toys',
            'slug' => 'toys',
            'description' => 'Kids toys, games, and family entertainment.',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->categories as $category) {
            if (ProductCategory::where('slug', $category['slug'])->exists()) {
                continue;
            }

            ProductCategory::factory()->create($category);
        }
    }
}
