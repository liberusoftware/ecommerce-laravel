<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCatalogueBrowsingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ProductCategory, 1: ProductCategory} */
    private function departmentWithSubcategory(): array
    {
        $department = ProductCategory::factory()->create(['name' => 'Electronics', 'slug' => 'electronics']);
        $audio = ProductCategory::factory()->create(['name' => 'Audio', 'slug' => 'audio', 'parent_category_id' => $department->id]);

        return [$department, $audio];
    }

    public function test_a_department_lists_its_subcategories_products(): void
    {
        [$department, $audio] = $this->departmentWithSubcategory();
        Product::factory()->create(['name' => 'Pulse Headphones', 'category_id' => $audio->id]);

        $this->get(route('categories.show', $department))->assertOk()->assertSee('Pulse Headphones')->assertSee('Audio');
        $this->get(route('categories.products', $department))->assertOk()->assertSee('Pulse Headphones');
    }

    public function test_the_category_index_lists_departments_with_their_subcategories(): void
    {
        [$department, $audio] = $this->departmentWithSubcategory();
        Product::factory()->create(['category_id' => $audio->id]);

        $response = $this->get(route('categories.index'))->assertOk();

        $response->assertSeeInOrder(['Electronics', 'Audio']);
        $this->assertSame(1, substr_count($response->getContent(), '>Audio<'));
    }

    public function test_search_by_department_includes_subcategory_products(): void
    {
        [$department, $audio] = $this->departmentWithSubcategory();
        Product::factory()->create(['name' => 'Pulse Headphones', 'category_id' => $audio->id]);

        $this->get(route('products.search', ['category' => $department->id]))->assertOk()->assertSee('Pulse Headphones');
    }

    public function test_listings_share_the_product_card_and_its_placeholder(): void
    {
        $category = ProductCategory::factory()->create();
        Product::factory()->create(['name' => 'Plain Product', 'category_id' => $category->id, 'featured_image' => null, 'inventory_count' => 10]);

        foreach ([route('products.index'), route('products.search', ['keyword' => 'Plain']), route('categories.products', $category)] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('Plain Product')
                ->assertSee('images/placeholder-product.svg')
                ->assertSee('Add to cart');
        }
    }

    public function test_an_empty_listing_explains_itself(): void
    {
        $this->get(route('products.index', ['keyword' => 'nothing-matches-this']))->assertOk()->assertSee('No products found');
    }
}
