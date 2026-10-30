<?php

namespace App\Services;

use App\Exceptions\InvalidProductConfigurationException;
use App\Models\Product;
use App\Models\ProductOptionChoice;
use App\Models\ProductOptionGroup;
use App\Models\ProductVariant;
use App\Support\ProductConfiguration;

/**
 * The only place shopper choices become a price. Every cart door and both
 * checkout paths resolve through here; a client never supplies a price.
 *
 * The base is the variant's price when the product has variants, otherwise the
 * product's; option choices adjust that base.
 */
class ProductOptionResolver
{
    /** @param  array<int|string>  $choiceIds */
    public function resolve(Product $product, array $choiceIds, int|string|null $variantId = null): ProductConfiguration
    {
        $variant = $this->variantOf($product, $variantId === null || $variantId === '' ? null : (int) $variantId);

        $choiceIds = array_map('intval', array_values($choiceIds));

        if (count($choiceIds) !== count(array_unique($choiceIds))) {
            throw new InvalidProductConfigurationException('The same option was chosen more than once.');
        }

        $groups = $this->groupsOf($product);
        $offered = [];
        foreach ($groups as $group) {
            foreach ($group->choices as $choice) {
                $offered[$choice->id] = [$group, $choice];
            }
        }

        $chosen = [];
        foreach ($choiceIds as $id) {
            if (! isset($offered[$id])) {
                throw new InvalidProductConfigurationException("That option is not offered for {$product->name}.");
            }

            [$group, $choice] = $offered[$id];
            if (! $choice->is_available) {
                throw new InvalidProductConfigurationException("{$choice->name} is not available at the moment.");
            }

            $chosen[$group->id][] = $choice;
        }

        $options = [];
        $adjustment = 0.0;
        foreach ($groups as $group) {
            $picked = $chosen[$group->id] ?? [];
            $this->assertWithinLimits($group, count($picked));

            usort($picked, fn (ProductOptionChoice $a, ProductOptionChoice $b) => [$a->position, $a->id] <=> [$b->position, $b->id]);
            foreach ($picked as $choice) {
                $options[] = [
                    'choice_id' => $choice->id,
                    'group' => $group->name,
                    'name' => $choice->name,
                    'price_adjustment' => (float) $choice->price_adjustment,
                ];
                $adjustment += (float) $choice->price_adjustment;
            }
        }

        sort($choiceIds);
        $base = $variant?->sellingPrice() ?? $product->sellingPrice();

        return new ProductConfiguration(
            options: $options,
            unitPrice: round(max(0.0, $base + $adjustment), 2),
            key: $this->key($variant, $choiceIds),
            variant: $variant,
        );
    }

    /** @param  list<int>  $choiceIds  sorted */
    private function key(?ProductVariant $variant, array $choiceIds): string
    {
        if ($variant === null) {
            return $choiceIds === [] ? '' : sha1(implode(',', $choiceIds));
        }

        return sha1('variant:'.$variant->id.'|'.implode(',', $choiceIds));
    }

    private function variantOf(Product $product, ?int $variantId): ?ProductVariant
    {
        if (! $product->hasVariants()) {
            if ($variantId !== null) {
                throw new InvalidProductConfigurationException("{$product->name} has no variants to choose from.");
            }

            return null;
        }

        $names = implode(' and ', array_column($product->variant_attributes ?? [], 'name')) ?: 'an option';
        if ($variantId === null) {
            throw new InvalidProductConfigurationException("Choose {$names} for {$product->name}.");
        }

        $variant = $product->variants()->whereKey($variantId)->first();
        if ($variant === null) {
            throw new InvalidProductConfigurationException("That {$names} is not offered for {$product->name}.");
        }

        if (! $variant->is_active) {
            throw new InvalidProductConfigurationException("{$product->name} ({$variant->label()}) is not available at the moment.");
        }

        return $variant;
    }

    /** Pay-what-you-want products take no options: the shopper names the whole price. */
    private function groupsOf(Product $product)
    {
        if ($product->isDonationBased()) {
            return collect();
        }

        return $product->optionGroups()
            ->where('product_option_groups.store_id', $product->store_id)
            ->with('choices')
            ->get();
    }

    private function assertWithinLimits(ProductOptionGroup $group, int $count): void
    {
        if ($count < $group->min_select) {
            throw new InvalidProductConfigurationException($group->min_select === 1
                ? "Choose an option for {$group->name}."
                : "Choose at least {$group->min_select} options for {$group->name}.");
        }

        if ($group->max_select !== null && $count > $group->max_select) {
            throw new InvalidProductConfigurationException($group->max_select === 1
                ? "Choose only one option for {$group->name}."
                : "Choose no more than {$group->max_select} options for {$group->name}.");
        }
    }
}
