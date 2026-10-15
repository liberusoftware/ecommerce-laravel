<?php

namespace Database\Seeders\DummyData;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Stock at or below this shows the "Low Stock" badge; above it, plain
     * in-stock. Zero would make the badge unreachable.
     */
    private const LOW_STOCK_THRESHOLD = 5;

    /**
     * The demo catalogue, keyed by category slug.
     *
     * Columns: name, price, short description, inventory count, featured.
     *
     * The storefront renders three distinct stock states (in stock / low stock
     * / sold out — see components/product-card.blade.php), so the demo data
     * deliberately covers all three: the large majority in stock with varied
     * counts, two hot items sold out, and two running low. A catalogue where
     * everything has 50 in stock never shows two thirds of the UI.
     */
    private const CATALOGUE = [
        'electronics' => [
            ['Smartphone', 699.99, 'Latest model smartphone with 5G.', 42, true],
            ['Laptop', 999.99, 'Lightweight laptop for everyday use.', 18, true],
            ['Bluetooth Speaker', 49.99, 'Portable speaker with excellent sound.', 64, false],
        ],
        'clothing' => [
            ['T-Shirt', 19.99, 'Cotton T-shirt available in various colors.', 120, false],
            ['Jeans', 39.99, 'Slim-fit denim jeans.', 37, false],
            ['Jacket', 79.99, 'Water-resistant windbreaker.', 3, false],
        ],
        'books' => [
            ['Science Fiction Novel', 12.99, 'Best-selling sci-fi adventure.', 85, false],
            ['Cookbook', 29.99, 'Recipes for healthy eating.', 24, false],
            ["Children's Storybook", 9.99, 'A fun and educational children’s book.', 56, false],
        ],
        'home' => [
            ['Blender', 49.99, 'High-powered blender for smoothies.', 31, false],
            ['Non-stick Pan', 29.99, 'Durable non-stick frying pan.', 48, false],
            ['Vacuum Cleaner', 99.99, 'Compact vacuum cleaner for small spaces.', 12, false],
        ],
        'sports' => [
            ['Yoga Mat', 19.99, 'Eco-friendly non-slip yoga mat.', 73, true],
            ['Dumbbells Set', 49.99, 'Adjustable dumbbell set for home workouts.', 15, false],
            ['Tennis Racket', 79.99, 'Professional tennis racket.', 0, false],
        ],
        'beauty' => [
            ['Moisturizer Cream', 24.99, 'Hydrating facial moisturizer.', 66, false],
            ['Shampoo', 14.99, 'Sulfate-free shampoo for healthy hair.', 94, false],
            ['Electric Toothbrush', 39.99, 'Rechargeable electric toothbrush.', 2, false],
        ],
        'toys' => [
            ['Building Blocks Set', 29.99, 'Creative building blocks for kids.', 40, false],
            ['Remote Control Car', 49.99, 'High-speed remote control car.', 0, false],
            ['Board Game', 19.99, 'Fun family board game.', 27, true],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as $slug => $products) {
            // Look categories up by slug rather than hardcoding IDs. firstOrFail
            // so a missing category is a clear error, not products with a null
            // category_id.
            $categoryId = ProductCategory::where('slug', $slug)->firstOrFail()->id;

            foreach ($products as [$name, $price, $shortDescription, $stock, $isFeatured]) {
                if (Product::where('name', $name)->exists()) {
                    continue;
                }

                Product::factory()->create([
                    'name' => $name,
                    'category_id' => $categoryId,
                    'price' => $price,
                    'short_description' => $shortDescription,
                    'inventory_count' => $stock,
                    'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
                    'is_featured' => $isFeatured,
                ]);
            }
        }
    }
}
