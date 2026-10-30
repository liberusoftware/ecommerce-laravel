<?php

namespace Database\Seeders\DummyData;

use App\Models\ProductOptionGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The demo store's product options from catalogue.json, attached to the demo
 * products they suit. A group already in the store, by name, is left as the
 * merchant has it, attachments included.
 */
class ProductOptionGroupSeeder extends Seeder
{
    public function run(): void
    {
        $storeId = DemoCatalogue::storeId();

        foreach (DemoCatalogue::load()['option_groups'] as $spec) {
            $exists = ProductOptionGroup::withoutGlobalScopes()
                ->where('store_id', $storeId)
                ->where('name', $spec['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            DemoCatalogue::inStore($storeId, fn () => DB::transaction(fn () => $this->create($storeId, $spec)));
        }
    }

    private function create(?int $storeId, array $spec): void
    {
        $group = ProductOptionGroup::create(['name' => $spec['name'], 'min_select' => $spec['min'], 'max_select' => $spec['max']]);

        foreach ($spec['choices'] as $position => $choice) {
            $group->choices()->create([
                'name' => $choice['name'],
                'price_adjustment' => $choice['price'],
                'is_default' => $choice['default'] ?? false,
                'position' => $position,
            ]);
        }

        foreach ($spec['products'] as $slug) {
            $product = DemoCatalogue::product($storeId, $slug);
            $product?->optionGroups()->attach($group->id, ['position' => $product->optionGroups()->count()]);
        }
    }
}
