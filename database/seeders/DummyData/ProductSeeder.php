<?php

namespace Database\Seeders\DummyData;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The demo products from catalogue.json, with their images on the public disk
 * where merchant uploads live, and the demo collections' contents.
 *
 * Creates what is missing and never changes existing rows or files, so it is
 * safe to run again on a store that already holds merchant data.
 */
class ProductSeeder extends Seeder
{
    /** Stock at or below this shows the low-stock state; zero shows sold out. */
    private const LOW_STOCK_THRESHOLD = 5;

    public function run(): void
    {
        $catalogue = DemoCatalogue::load();

        foreach ($catalogue['products'] as $item) {
            if (Product::where('slug', $item['slug'])->exists()) {
                continue;
            }

            Product::create([
                'name' => $item['name'],
                'slug' => $item['slug'],
                'short_description' => $item['short'],
                'description' => $item['description'],
                'price' => $item['price'],
                'category_id' => ProductCategory::where('slug', $item['category'])->firstOrFail()->id,
                'inventory_count' => $item['stock'],
                'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
                'is_featured' => $item['featured'],
                'featured_image' => $this->image($item['slug']),
            ]);
        }

        foreach ($catalogue['collections'] as $name => $slugs) {
            ProductCollection::where('name', $name)->first()
                ?->products()
                ->syncWithoutDetaching(Product::whereIn('slug', $slugs)->pluck('id'));
        }
    }

    private function image(string $slug): string
    {
        $path = DemoCatalogue::IMAGE_DIRECTORY."/{$slug}.webp";
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $disk->put($path, file_get_contents(DemoCatalogue::imageSource($slug)));
        }

        return $path;
    }
}
