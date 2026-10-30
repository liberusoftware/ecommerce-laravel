<?php

namespace Database\Seeders\DummyData;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductVariantService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Variants for the demo products in catalogue.json: every combination of the
 * product's attributes, priced as the base plus each value's premium, written
 * through the service the product form uses. A product that has, or has ever
 * had, variants is left as the merchant has it.
 */
class ProductVariantSeeder extends Seeder
{
    public function run(): void
    {
        $storeId = DemoCatalogue::storeId();

        foreach (DemoCatalogue::load()['variants'] as $slug => $spec) {
            $product = DemoCatalogue::product($storeId, $slug);

            if ($product === null || $product->variant_attributes !== null
                || ProductVariant::withoutGlobalScopes()->withTrashed()->where('product_id', $product->id)->exists()) {
                continue;
            }

            DemoCatalogue::inStore($storeId, fn () => $this->seed($product, $spec));
        }
    }

    private function seed(Product $product, array $spec): void
    {
        $service = app(ProductVariantService::class);
        $attributes = array_map(fn ($name, $values) => ['name' => $name, 'values' => $values], array_keys($spec['attributes']), $spec['attributes']);

        $rows = array_map(function (array $values) use ($product, $spec) {
            $label = ProductVariant::labelFor($values);
            $chosen = array_column($values, 'value');

            return [
                'attribute_values' => $values,
                'sku' => implode('-', [$product->sku ?? strtoupper($product->slug), ...array_map([$this, 'code'], $chosen)]),
                'price' => round($spec['price']['base'] + array_sum(array_map(fn ($v) => $spec['price'][$v] ?? 0, $chosen)), 2),
                'inventory_count' => $spec['stock'][$label] ?? $spec['stock']['default'] ?? throw new RuntimeException("No demo stock for {$product->slug} {$label}."),
                'track_inventory' => true,
                'is_active' => ! in_array($label, $spec['unavailable'] ?? [], true),
            ];
        }, $service->combinations($attributes));

        $service->sync($product, $attributes, $rows);
    }

    /** "Sea Blue" -> "SEABLU": short, and distinct within each attribute of the demo data. */
    private function code(string $value): string
    {
        return substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($value)), 0, 6);
    }
}
