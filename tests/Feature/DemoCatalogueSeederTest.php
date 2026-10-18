<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use Database\Seeders\DummyData\DummyDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoCatalogueSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(DummyDataSeeder::class);
    }

    public function test_categories_form_a_two_level_tree_with_products_in_subcategories(): void
    {
        $departments = ProductCategory::topLevel()->with('children')->get();

        $this->assertGreaterThanOrEqual(5, $departments->count());
        foreach ($departments as $department) {
            $this->assertNotEmpty($department->children, "{$department->name} has no subcategories.");
            $this->assertTrue(
                Product::whereIn('category_id', $department->listingCategoryIds())->exists(),
                "{$department->name} has no products."
            );
        }
    }

    public function test_every_product_has_a_real_description_and_a_local_image(): void
    {
        foreach (Product::all() as $product) {
            $this->assertNotEmpty($product->short_description);
            $this->assertNotEmpty($product->description);
            $this->assertStringStartsNotWith('http', $product->featured_image, "{$product->name} uses an external image.");
            Storage::disk('public')->assertExists($product->featured_image);
        }
    }

    public function test_demo_collections_are_populated(): void
    {
        foreach (ProductCollection::withCount('products')->get() as $collection) {
            $this->assertGreaterThan(0, $collection->products_count, "{$collection->name} is empty.");
        }
    }

    public function test_seeding_again_keeps_existing_rows_and_merchant_edits(): void
    {
        $product = Product::where('slug', 'nimbus-14-laptop')->firstOrFail();
        $product->update(['price' => 999.00]);
        Storage::disk('public')->put($product->featured_image, 'merchant image');
        $counts = [Product::count(), ProductCategory::count()];

        $this->seed(DummyDataSeeder::class);

        $this->assertSame($counts, [Product::count(), ProductCategory::count()]);
        $this->assertEquals(999.00, $product->fresh()->price);
        $this->assertSame('merchant image', Storage::disk('public')->get($product->featured_image));
    }
}
