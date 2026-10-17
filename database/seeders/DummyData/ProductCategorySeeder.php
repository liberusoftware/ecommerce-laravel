<?php

namespace Database\Seeders\DummyData;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * The demo category tree from catalogue.json: departments with one level of
 * subcategories. Creates what is missing and never changes existing rows.
 */
class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (DemoCatalogue::load()['categories'] as $department) {
            $parent = $this->ensure($department, null);

            foreach ($department['children'] as $child) {
                $this->ensure($child, $parent->id);
            }
        }
    }

    private function ensure(array $category, ?int $parentId): ProductCategory
    {
        return ProductCategory::where('slug', $category['slug'])->first()
            ?? ProductCategory::create([
                'name' => $category['name'],
                'slug' => $category['slug'],
                'description' => $category['description'],
                'parent_category_id' => $parentId,
            ]);
    }
}
