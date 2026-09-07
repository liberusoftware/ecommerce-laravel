<?php

namespace App\Services\Facebook;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Maps a Product (or its Variants) into Meta Catalog items_batch requests.
 * Field mapping lives here so the jobs stay thin.
 */
class CatalogItemMapper
{
    public static function retailerIdForProduct(Product $product): string
    {
        return 'product-'.$product->id;
    }

    public static function retailerIdForVariant(ProductVariant $variant): string
    {
        return 'variant-'.$variant->id;
    }

    /** The Meta grouping that ties a Product's variant items together. */
    public static function itemGroupId(Product $product): string
    {
        return 'product-'.$product->id;
    }

    /** An idempotent upsert keyed by the stable retailer id. */
    public static function forProduct(Product $product, string $retailerId): array
    {
        return [
            'method' => 'UPDATE',
            'data' => [
                'id' => $retailerId,
                'title' => $product->name,
                'description' => self::description($product),
                'availability' => $product->inventory_count > 0 ? 'in stock' : 'out of stock',
                'condition' => 'new',
                'price' => self::money($product->price),
                'link' => self::link($product),
                'image_link' => self::image($product),
                'brand' => config('app.name'),
            ],
        ];
    }

    /**
     * One item per Variant sharing an item_group_id, so Meta shows them as one
     * product with selectable variations. Price and stock come from the Variant.
     */
    public static function forVariant(Product $product, ProductVariant $variant, string $retailerId, string $itemGroupId): array
    {
        return [
            'method' => 'UPDATE',
            'data' => [
                'id' => $retailerId,
                'item_group_id' => $itemGroupId,
                'title' => trim($product->name.' - '.($variant->title ?? '')),
                'description' => self::description($product),
                'availability' => $variant->inventory_quantity > 0 ? 'in stock' : 'out of stock',
                'condition' => 'new',
                'price' => self::money($variant->price ?? $product->price),
                'link' => self::link($product),
                'image_link' => self::image($product),
                'brand' => config('app.name'),
            ],
        ];
    }

    /** A real unlist — the Catalog item goes away, unlike a sold-out flip. */
    public static function deleteRequest(string $retailerId): array
    {
        return [
            'method' => 'DELETE',
            'data' => ['id' => $retailerId],
        ];
    }

    private static function description(Product $product): string
    {
        $text = $product->description
            ?? $product->short_description
            ?? $product->long_description
            ?? $product->name;

        return trim(strip_tags((string) $text)) ?: $product->name;
    }

    /**
     * The merchant's own storefront hostname, not APP_URL — a queued job has no
     * request to resolve a channel from, so the Store answers instead.
     */
    private static function link(Product $product): string
    {
        $host = $product->store?->channels()->first()?->primaryDomain()?->host;

        return $host
            ? 'https://'.$host.'/products/'.$product->slug
            : url('/products/'.$product->slug);
    }

    private static function image(Product $product): ?string
    {
        $image = $product->featured_image;

        if (blank($image)) {
            return null;
        }

        return str_starts_with($image, 'http') ? $image : url($image);
    }

    private static function money(int|float|string $price): string
    {
        return number_format((float) $price, 2, '.', '').' '.config('ecommerce.default_currency', 'USD');
    }
}
