<?php

namespace Tests\Feature\Concerns;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductVariantService;

trait BuildsVariants
{
    /**
     * A product with variants, written through the service the form and API use.
     *
     * @param  array<string, list<string>>  $attributes  name => values
     * @param  list<array>  $rows  from row()
     */
    protected function variableProduct(array $attributes, array $rows, array $product = []): Product
    {
        $product = Product::factory()->create($product + ['price' => 10, 'inventory_count' => 0]);

        app(ProductVariantService::class)->sync($product, $this->attributes($attributes), $rows);

        return $product->fresh();
    }

    /** @param  array<string, list<string>>  $attributes */
    protected function attributes(array $attributes): array
    {
        return array_map(fn ($name, $values) => ['name' => $name, 'values' => $values], array_keys($attributes), $attributes);
    }

    /** @param  array<string, string>  $values  name => value */
    protected function row(array $values, float $price, int $stock = 10, array $extra = []): array
    {
        return [
            'attribute_values' => array_map(fn ($name, $value) => ['name' => $name, 'value' => $value], array_keys($values), $values),
            'price' => $price,
            'inventory_count' => $stock,
        ] + $extra;
    }

    /** The variant whose values read like "Black / 128 GB". */
    protected function variantOf(Product $product, string $label): ProductVariant
    {
        return $product->variants()->get()->first(fn (ProductVariant $v) => $v->label() === $label)
            ?? $this->fail("No variant {$label}");
    }
}
