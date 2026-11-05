<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a product's variation attributes and variants. The Filament form and
 * the REST API both go through here, so a combination, a SKU and a price are
 * validated the same way whichever door they come in by.
 *
 * Variants are matched to existing rows by id, then by combination, so editing
 * a price keeps the variant's identity and stock. A combination no longer
 * offered is soft-deleted: carts and orders that name it keep their record.
 */
class ProductVariantService
{
    public const MAX_VARIANTS = 100;

    /**
     * Trimmed, de-duplicated, empty entries dropped.
     *
     * @return list<array{name: string, values: list<string>}>
     *
     * @throws ValidationException
     */
    public function normaliseAttributes(?array $attributes): array
    {
        $normalised = [];
        $names = [];

        foreach (array_values($attributes ?? []) as $i => $attribute) {
            $name = trim((string) ($attribute['name'] ?? ''));
            $values = [];
            foreach ((array) ($attribute['values'] ?? []) as $value) {
                $value = trim((string) $value);
                if ($value !== '' && ! in_array(mb_strtolower($value), array_map('mb_strtolower', $values), true)) {
                    $values[] = $value;
                }
            }

            if ($name === '' && $values === []) {
                continue;
            }
            if ($name === '') {
                throw ValidationException::withMessages(["variant_attributes.{$i}.name" => 'Name this attribute, for example Colour or Size.']);
            }
            if ($values === []) {
                throw ValidationException::withMessages(["variant_attributes.{$i}.values" => "Enter at least one value for {$name}."]);
            }
            if (in_array(mb_strtolower($name), $names, true)) {
                throw ValidationException::withMessages(["variant_attributes.{$i}.name" => "{$name} is listed twice."]);
            }

            $names[] = mb_strtolower($name);
            $normalised[] = ['name' => $name, 'values' => $values];
        }

        return $normalised;
    }

    /**
     * Every combination of one value per attribute, in attribute order.
     *
     * @param  list<array{name: string, values: list<string>}>  $attributes
     * @return list<list<array{name: string, value: string}>>
     */
    public function combinations(array $attributes): array
    {
        if ($attributes === []) {
            return [];
        }

        $combinations = [[]];
        foreach ($attributes as $attribute) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($attribute['values'] as $value) {
                    $next[] = [...$combination, ['name' => $attribute['name'], 'value' => $value]];
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * Identifies a combination whatever its order or letter case.
     *
     * @param  list<array{name: string, value: string}>  $values
     */
    public function key(array $values): string
    {
        $pairs = array_map(fn (array $v) => mb_strtolower(trim($v['name']))."\x1f".mb_strtolower(trim($v['value'])), $values);
        sort($pairs);

        return sha1(implode("\x1e", $pairs));
    }

    /**
     * The variant rows for a set of attributes: a row already listed for a
     * combination is kept as it is, a new combination gets the defaults, and a
     * combination that no longer exists is dropped.
     *
     * @param  array<array-key, array>  $rows
     * @param  array{price?: mixed, track_inventory?: bool}  $defaults
     * @return array<array-key, array>
     */
    public function generate(?array $attributes, array $rows, array $defaults = []): array
    {
        $attributes = $this->normaliseAttributes($attributes);
        $combinations = $this->combinations($attributes);

        if (count($combinations) > self::MAX_VARIANTS) {
            throw ValidationException::withMessages(['variant_attributes' => 'These values make '.count($combinations).' combinations; a product can have at most '.self::MAX_VARIANTS.' variants.']);
        }

        $existing = [];
        foreach ($rows as $rowKey => $row) {
            $existing[$this->key($row['attribute_values'] ?? [])] = [$rowKey, $row];
        }

        $generated = [];
        foreach ($combinations as $i => $values) {
            [$rowKey, $row] = $existing[$this->key($values)] ?? ['new-'.$i, [
                'id' => null,
                'sku' => null,
                'price' => $defaults['price'] ?? null,
                'track_inventory' => $defaults['track_inventory'] ?? true,
                'inventory_count' => 0,
                'is_active' => true,
            ]];

            $generated[$rowKey] = ['attribute_values' => $values, 'label' => ProductVariant::labelFor($values)] + $row;
        }

        return $generated;
    }

    /**
     * Replace the product's attributes and variants with these. Stock is set
     * only when a variant is created; afterwards it moves through adjustments.
     * Errors are keyed `variant_attributes…` and `variants.{row key}.{field}`.
     *
     * @param  array<array-key, array{id?: ?int, attribute_values?: array, sku?: ?string, price?: mixed, track_inventory?: ?bool, inventory_count?: ?int, is_active?: ?bool}>  $rows
     *
     * @throws ValidationException
     */
    public function sync(Product $product, ?array $attributes, array $rows): void
    {
        $attributes = $this->normaliseAttributes($attributes);
        $current = $product->variants()->get()->keyBy('id');

        if ($attributes === [] && $rows === []) {
            DB::transaction(function () use ($product, $current) {
                $current->each->delete();
                $product->forceFill(['variant_attributes' => null])->save();
            });
            $product->unsetRelation('variants');

            return;
        }

        $rows = $this->validated($product, $attributes, $rows, $current);

        // Rows naming an id claim it; the rest are matched to an unclaimed variant by combination.
        $claimed = array_filter(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $row['id'] ??= $current->except($claimed)->firstWhere('combination_key', $row['combination_key'])?->id;
            $claimed[] = $row['id'];
        }
        unset($row);

        DB::transaction(function () use ($product, $attributes, $rows, $current) {
            $kept = array_values(array_filter(array_column($rows, 'id')));

            $current->except($kept)->each->delete();
            // Cleared first, so rows may trade combinations or SKUs without tripping the unique indexes.
            ProductVariant::withoutGlobalScopes()->whereKey($kept)->update(['combination_key' => null, 'sku' => null]);
            // The loaded models must agree, or an unchanged SKU looks clean and is never written back.
            $current->only($kept)->each(fn (ProductVariant $variant) => $variant->setRawAttributes(['combination_key' => null, 'sku' => null] + $variant->getAttributes(), true));

            foreach (array_values($rows) as $position => $row) {
                $fields = [
                    'attribute_values' => $row['attribute_values'],
                    'combination_key' => $row['combination_key'],
                    'sku' => $row['sku'],
                    'price' => $row['price'],
                    'track_inventory' => $row['track_inventory'],
                    'is_active' => $row['is_active'],
                    'position' => $position,
                ];

                if ($row['id'] === null) {
                    $product->variants()->create($fields + ['inventory_count' => $row['inventory_count']]);
                } else {
                    $current->get($row['id'])->update($fields);
                }
            }

            // Listings filter and sort on products.price: keep it at the lowest price on sale.
            $variants = $product->variants()->get();
            $priced = $variants->where('is_active', true)->whenEmpty(fn () => $variants);
            $product->forceFill([
                'variant_attributes' => $attributes,
                'price' => $priced->min('price'),
                'sale_price' => null,
            ])->save();
        });

        $product->unsetRelation('variants');
    }

    /**
     * @param  Collection<int, ProductVariant>  $current
     * @return array<array-key, array>
     */
    private function validated(Product $product, array $attributes, array $rows, Collection $current): array
    {
        if (($product->pricing_type ?? 'fixed') !== 'fixed') {
            throw ValidationException::withMessages(['variant_attributes' => 'Only fixed-price products can have variants.']);
        }
        if ($attributes === []) {
            throw ValidationException::withMessages(['variant_attributes' => 'Add the attributes the variants differ by, such as Colour or Size.']);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['variants' => 'Generate the variants, or remove the attributes to sell this as a single product.']);
        }
        if (count($rows) > self::MAX_VARIANTS) {
            throw ValidationException::withMessages(['variants' => 'A product can have at most '.self::MAX_VARIANTS.' variants.']);
        }

        $errors = [];
        $keys = [];
        $skus = [];
        $clean = [];

        foreach ($rows as $rowKey => $row) {
            $at = "variants.{$rowKey}";
            $id = isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null;
            if ($id !== null && ! $current->has($id)) {
                $errors["{$at}.id"] = 'That variant does not belong to this product.';

                continue;
            }

            $values = $this->orderedValues($attributes, (array) ($row['attribute_values'] ?? []));
            if ($values === null) {
                $errors["{$at}.attribute_values"] = 'Each variant needs exactly one listed value for every attribute.';

                continue;
            }

            $key = $this->key($values);
            if (isset($keys[$key])) {
                $errors["{$at}.attribute_values"] = ProductVariant::labelFor($values).' is listed more than once.';

                continue;
            }
            $keys[$key] = true;

            $price = $row['price'] ?? null;
            if (! is_numeric($price) || (float) $price < 0 || (float) $price > 99999999) {
                $errors["{$at}.price"] = 'Enter a price of 0 or more.';
            }

            $stock = $row['inventory_count'] ?? 0;
            if ($id === null && (filter_var($stock, FILTER_VALIDATE_INT) === false || (int) $stock < 0)) {
                $errors["{$at}.inventory_count"] = 'Enter a whole number of 0 or more.';
            }

            $sku = isset($row['sku']) ? trim((string) $row['sku']) : '';
            if (mb_strlen($sku) > 100) {
                $errors["{$at}.sku"] = 'A SKU can be at most 100 characters.';
            } elseif ($sku !== '') {
                if (isset($skus[mb_strtolower($sku)])) {
                    $errors["{$at}.sku"] = 'Two variants have this SKU.';
                } elseif ($this->skuTaken($product, $sku, $current)) {
                    $errors["{$at}.sku"] = 'Another product or variant in this store already has this SKU.';
                }
                $skus[mb_strtolower($sku)] = true;
            }

            $clean[$rowKey] = [
                'id' => $id,
                'attribute_values' => $values,
                'combination_key' => $key,
                'sku' => $sku === '' ? null : $sku,
                'price' => is_numeric($price) ? round((float) $price, 2) : 0,
                'track_inventory' => (bool) ($row['track_inventory'] ?? true),
                'inventory_count' => (int) $stock,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /** @return list<array{name: string, value: string}>|null the values in attribute order, or null if they do not fit */
    private function orderedValues(array $attributes, array $values): ?array
    {
        $given = [];
        foreach ($values as $value) {
            $given[mb_strtolower(trim((string) ($value['name'] ?? '')))] = trim((string) ($value['value'] ?? ''));
        }

        if (count($given) !== count($values) || count($given) !== count($attributes)) {
            return null;
        }

        $ordered = [];
        foreach ($attributes as $attribute) {
            $value = $given[mb_strtolower($attribute['name'])] ?? null;
            $match = $value === null ? null : collect($attribute['values'])->first(fn ($v) => mb_strtolower($v) === mb_strtolower($value));
            if ($match === null) {
                return null;
            }
            $ordered[] = ['name' => $attribute['name'], 'value' => $match];
        }

        return $ordered;
    }

    /** SKUs are unique within a store across products and variants, removed ones included. */
    private function skuTaken(Product $product, string $sku, Collection $current): bool
    {
        $productTaken = Product::withoutGlobalScopes()->withTrashed()
            ->where('store_id', $product->store_id)
            ->where('sku', $sku)
            ->exists();

        $variantTaken = ProductVariant::withoutGlobalScopes()->withTrashed()
            ->where('store_id', $product->store_id)
            ->where('sku', $sku)
            ->whereKeyNot($current->keys()->all())
            ->exists();

        return $productTaken || $variantTaken;
    }
}
