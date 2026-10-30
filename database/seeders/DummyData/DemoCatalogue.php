<?php

namespace Database\Seeders\DummyData;

use App\Models\Product;
use App\Services\StoreContext;

/**
 * The demo store's catalogue, shared with tools/generate-demo-product-images.py.
 */
final class DemoCatalogue
{
    public const IMAGE_DIRECTORY = 'products/demo';

    public static function load(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/catalogue.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function imageSource(string $slug): string
    {
        return __DIR__."/images/{$slug}.webp";
    }

    /** The store ProductSeeder put the demo products in; null when they are not there. */
    public static function storeId(): ?int
    {
        $slugs = array_column(self::load()['products'], 'slug');

        $storeId = Product::withoutGlobalScopes()
            ->whereIn('slug', $slugs)
            ->groupBy('store_id')
            ->orderByRaw('count(*) desc')
            ->value('store_id');

        return $storeId === null ? null : (int) $storeId;
    }

    /** A demo product in the demo store, by its stable slug. */
    public static function product(?int $storeId, string $slug): ?Product
    {
        return Product::withoutGlobalScopes()->whereNull('deleted_at')->where('store_id', $storeId)->where('slug', $slug)->first();
    }

    public static function inStore(?int $storeId, callable $callback): mixed
    {
        return $storeId === null ? $callback() : StoreContext::writingTo($storeId, $callback);
    }
}
