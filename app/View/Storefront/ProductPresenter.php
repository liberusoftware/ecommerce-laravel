<?php

namespace App\View\Storefront;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\TaxCalculator;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * What a storefront view may show about a product, already priced and judged.
 * Themes restyle these values; they never recompute them.
 */
class ProductPresenter
{
    public function __construct(private readonly TaxCalculator $tax) {}

    /** @return array<string, mixed> */
    public function card(Product $product): array
    {
        $stock = (int) $product->inventory_count;
        $isOut = ! $product->isInStock();
        $isLow = ! $isOut && $product->tracksInventory() && $stock <= (int) ($product->low_stock_threshold ?? 0);

        // Controllers eager-load these; the method calls are the fallback.
        $reviewCount = $product->review_count ?? $product->getTotalReviews();
        $rating = (float) (array_key_exists('rating_avg_rating', $product->getAttributes())
            ? $product->rating_avg_rating
            : $product->getAverageRating());

        $summary = $product->short_description ?: $product->description;

        return [
            'url' => route('products.show', $product),
            'add_url' => route('cart.add', $product),
            'name' => $product->name,
            'image' => $product->image_url ?: asset('images/placeholder-product.svg'),
            'summary' => filled($summary) ? Str::limit(strip_tags($summary), 80) : null,
            'price' => Money::format($product->sellingPrice()),
            'from_price' => $product->hasVariantPriceRange(),
            'was_price' => $product->isOnSale() ? Money::format($product->price) : null,
            'stock' => $stock,
            'stock_state' => $isOut ? 'out' : ($isLow ? 'low' : 'in'),
            'rating' => $rating,
            'review_count' => (int) $reviewCount,
            'requires_choice' => $product->requiresChoice(),
        ];
    }

    /** @return array{attributes: list<array{name: string, values: list<string>}>, variants: list<array<string, mixed>>, selected: array<string, ?string>} */
    public function variantPicker(Product $product, mixed $oldVariantId = null): array
    {
        $attributes = collect($product->variant_attributes ?? [])->values()->all();

        $variants = $product->variants->map(fn (ProductVariant $variant) => [
            'id' => $variant->id,
            'values' => array_column($variant->attribute_values, 'value', 'name'),
            'label' => $variant->label(),
            'amount' => round($this->tax->displayAmount($product, $variant->sellingPrice()), 2),
            'price' => Money::format($this->tax->displayAmount($product, $variant->sellingPrice())),
            'available' => $variant->isPurchasable(),
            'stock' => $variant->tracksInventory() ? $variant->inventory_count : null,
            'sku' => $variant->sku,
        ])->values();

        $preselected = $variants->firstWhere('id', (int) $oldVariantId);
        $selected = [];
        foreach ($attributes as $attribute) {
            $selected[$attribute['name']] = $preselected['values'][$attribute['name']]
                ?? (count($attribute['values']) === 1 ? $attribute['values'][0] : null);
        }

        return ['attributes' => $attributes, 'variants' => $variants->all(), 'selected' => $selected];
    }

    /** @return list<int> */
    public function defaultChoiceIds(Product $product): array
    {
        return $product->optionGroups->flatMap->choices
            ->where('is_default', true)->where('is_available', true)
            ->pluck('id')->all();
    }
}
