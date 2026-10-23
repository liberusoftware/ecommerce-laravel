<?php

namespace Tests\Feature\Concerns;

use App\Models\Product;
use App\Models\ProductOptionChoice;
use App\Models\ProductOptionGroup;

trait BuildsOptionGroups
{
    /**
     * @param  array<string, float>  $choices  name => price adjustment, in display order
     * @param  list<Product>  $products
     */
    protected function optionGroup(string $name, int $min, ?int $max, array $choices, array $products = [], array $attributes = []): ProductOptionGroup
    {
        $group = ProductOptionGroup::create(['name' => $name, 'min_select' => $min, 'max_select' => $max]);
        if (array_key_exists('store_id', $attributes)) {
            $group->forceFill(['store_id' => $attributes['store_id']])->save();
        }

        $position = 0;
        foreach ($choices as $choice => $adjustment) {
            $group->choices()->create(['name' => $choice, 'price_adjustment' => $adjustment, 'position' => $position++]);
        }

        foreach ($products as $product) {
            $product->optionGroups()->attach($group->id, ['position' => $product->optionGroups()->count()]);
        }

        return $group->load('choices');
    }

    protected function choice(ProductOptionGroup $group, string $name): ProductOptionChoice
    {
        return $group->choices()->where('name', $name)->sole();
    }

    /** @return list<int> */
    protected function choiceIds(ProductOptionGroup $group, string ...$names): array
    {
        return array_map(fn (string $name) => $this->choice($group, $name)->id, $names);
    }
}
