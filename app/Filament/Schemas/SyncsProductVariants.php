<?php

namespace App\Filament\Schemas;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductVariantService;
use Illuminate\Validation\ValidationException;

/** The product pages' half of the variants section: fill it from the product, save it through the service. */
trait SyncsProductVariants
{
    /** @var array{0: ?array, 1: array}|null */
    private ?array $pendingVariants = null;

    protected function fillVariants(Product $product, array $data): array
    {
        $data['variants'] = $product->variants->mapWithKeys(fn (ProductVariant $v) => ['record-'.$v->id => [
            'id' => $v->id,
            'attribute_values' => $v->attribute_values,
            'label' => $v->label(),
            'sku' => $v->sku,
            'price' => $v->price,
            'track_inventory' => $v->track_inventory,
            'inventory_count' => $v->inventory_count,
            'is_active' => $v->is_active,
        ]])->all();

        return $data;
    }

    /** Keeps the variant fields away from the product's own save. */
    protected function takeVariants(array $data): array
    {
        // Dehydrated rows are a list; keyed back by the form's items, errors land on the right row.
        $rows = array_values($data['variants'] ?? []);
        $keys = array_keys($this->data['variants'] ?? []);
        $this->pendingVariants = [$data['variant_attributes'] ?? null, count($keys) === count($rows) ? array_combine($keys, $rows) : $rows];
        unset($data['variant_attributes'], $data['variants']);

        return $data;
    }

    protected function syncVariants(Product $product): void
    {
        [$attributes, $rows] = $this->pendingVariants ?? [null, []];

        try {
            app(ProductVariantService::class)->sync($product, $attributes, $rows);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        }
    }
}
