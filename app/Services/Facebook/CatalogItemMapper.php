<?php

namespace App\Services\Facebook;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Money;

/**
 * Maps a Product into Meta Catalog items_batch requests.
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

    /** Ties a product's variant items together, so Meta shows them as one product. */
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
                'availability' => $product->isInStock() ? 'in stock' : 'out of stock',
                'condition' => 'new',
                'price' => self::money($product->price, $product),
                'link' => self::link($product),
                'image_link' => self::image($product),
                'brand' => $product->store?->name ?? config('app.name'),
            ] + ($product->isOnSale() ? ['sale_price' => self::money($product->sale_price, $product)] : []),
        ];
    }

    /** One item per variant: its own price and availability, grouped under the product. */
    public static function forVariant(Product $product, ProductVariant $variant, string $retailerId): array
    {
        return [
            'method' => 'UPDATE',
            'data' => [
                'id' => $retailerId,
                'item_group_id' => self::itemGroupId($product),
                'title' => trim($product->name.' - '.$variant->label(), ' -'),
                'description' => self::description($product),
                'availability' => $variant->isInStock() ? 'in stock' : 'out of stock',
                'condition' => 'new',
                'price' => self::money($variant->sellingPrice(), $product),
                'link' => self::link($product),
                'image_link' => self::image($product),
                'brand' => $product->store?->name ?? config('app.name'),
            ] + self::variantFields($variant),
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

    /** Meta's own variant fields, where an attribute is one of them. */
    private static function variantFields(ProductVariant $variant): array
    {
        $fields = ['colour' => 'color', 'color' => 'color', 'size' => 'size', 'material' => 'material', 'pattern' => 'pattern'];

        return collect($variant->attribute_values ?? [])
            ->mapWithKeys(fn (array $pair) => [$fields[strtolower(trim($pair['name']))] ?? '' => $pair['value']])
            ->except([''])
            ->all();
    }

    private static function description(Product $product): string
    {
        $text = $product->description
            ?? $product->short_description
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
            ? 'https://'.$host.route('products.show', $product, absolute: false)
            : route('products.show', $product);
    }

    private static function image(Product $product): ?string
    {
        $image = $product->image_url;

        if (blank($image)) {
            return null;
        }

        return str_starts_with($image, 'http') ? $image : url($image);
    }

    private static function money(int|float|string $price, Product $product): string
    {
        return number_format((float) $price, 2, '.', '').' '.($product->store?->currency_code ?? Money::currency());
    }
}
