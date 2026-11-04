<?php

namespace Tests\Feature\Frontend;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    // Test that no categories exist
    public function test_list_categories_when_none_exist()
    {
        $response = $this->get(route('categories.index'));

        $response->assertStatus(200);
        $response->assertViewIs('categories.index');
        $response->assertViewHas('categories', function ($categories) {
            return $categories->isEmpty();
        });
    }

    // Test that exactly one category exists
    public function test_list_categories_with_one_category()
    {
        $categoryName = 'Test Category';
        $category = ProductCategory::factory()->create([
            'name' => $categoryName,
        ]);

        $response = $this->get(route('categories.index'));

        $response->assertStatus(200);
        $response->assertViewIs('categories.index');
        $response->assertViewHas('categories', function ($categories) use ($category, $categoryName) {
            return
                $categories->count() === 1
                && $categories->first()->id === $category->id
                && $categories->first()->name === $categoryName;
        });
    }

    // Test that multiple categories exist
    public function test_list_categories_with_multiple_categories()
    {
        $categories = ProductCategory::factory()->count(3)->sequence(
            ['name' => 'Category 1'],
            ['name' => 'Category 2'],
            ['name' => 'Category 3']
        )->create();

        $response = $this->get(route('categories.index'));

        $response->assertStatus(200);
        $response->assertViewIs('categories.index');
        $response->assertViewHas('categories', function ($viewCategories) use ($categories) {
            if ($viewCategories->count() !== 3) {
                return false;
            }

            $viewCategoryNames = $viewCategories->pluck('name');
            $categoryNames = $categories->pluck('name');

            return $categoryNames->every(fn ($name) => $viewCategoryNames->contains($name));
        });
    }

    // Test that searching returns no results for a non-existent category
    public function test_search_returns_no_results_for_non_existent_category()
    {
        ProductCategory::factory()->count(3)->sequence(
            ['name' => 'Category 1'],
            ['name' => 'Category 2'],
            ['name' => 'Category 3']
        )->create();

        // URL: GET /categories?filter[name]=NonExistentCategory
        $response = $this->get(route('categories.index', ['filter[name]' => 'NonExistentCategory']));

        $response->assertStatus(200);
        $response->assertViewIs('categories.index');
        $response->assertViewHas('categories', function ($categories) {
            return $categories->isEmpty();
        });
    }

    // Test that searching returns a single result for an exact match
    public function test_search_returns_matching_category()
    {
        $categoryName = 'CategoryMatch';
        ProductCategory::factory()->count(3)->sequence(
            ['name' => 'Category 1'],
            ['name' => 'Category 2'],
            ['name' => $categoryName]
        )->create();

        // URL: GET /categories?filter[name]=CategoryMatch
        $response = $this->get(route('categories.index', ['filter[name]' => $categoryName]));

        $response->assertStatus(200);
        $response->assertViewIs('categories.index');
        $response->assertViewHas('categories', function ($categories) use ($categoryName) {
            return $categories->count() === 1
                && $categories->first()->name === $categoryName;
        });
    }

    // Test that searching returns multiple results for a partial match
    public function test_search_returns_multiple_results_for_partial_match()
    {
        $categoryName = 'CategoryMatch';
        ProductCategory::factory()->count(3)->sequence(
            ['name' => $categoryName.' 1'],
            ['name' => 'OtherCategory 1'],
            ['name' => $categoryName.' 2'],
            ['name' => 'OtherCategory 2'],
            ['name' => 'OtherCategory 3'],
        )->create();

        // URL: GET /categories?filter[name]=CategoryMatch
        $response = $this->get(route('categories.index', ['filter[name]' => $categoryName]));

        $response->assertStatus(200);
        $response->assertViewIs('categories.index');
        $response->assertViewHas('categories', function ($categories) use ($categoryName) {
            $expectedNames = [$categoryName.' 1', $categoryName.' 2'];

            return $categories->count() === 2
                && $categories->pluck('name')->intersect($expectedNames)->count() === 2;
        });
    }

    // Test that categories are sorted in ascending order
    public function test_categories_are_sorted_in_ascending_order()
    {
        ProductCategory::factory()->count(3)->sequence(
            ['name' => 'Category 3'],
            ['name' => 'Category 1'],
            ['name' => 'Category 2']
        )->create();

        $response = $this->get(route('categories.index', ['sort' => 'name']));

        $response->assertStatus(200);
        $response->assertViewIs('categories.index');
        $response->assertViewHas('categories', function ($viewCategories) {
            return $viewCategories->pluck('name')->toArray() === ['Category 1', 'Category 2', 'Category 3'];
        });
    }

    // Test that categories are sorted in descending order
    public function test_categories_are_sorted_in_descending_order()
    {
        ProductCategory::factory()->count(3)->sequence(
            ['name' => 'Category 3'],
            ['name' => 'Category 1'],
            ['name' => 'Category 2']
        )->create();

        $response = $this->get(route('categories.index', ['sort' => '-name']));

        $response->assertStatus(200);
        $response->assertViewIs('categories.index');
        $response->assertViewHas('categories', function ($viewCategories) {
            return $viewCategories->pluck('name')->toArray() === ['Category 3', 'Category 2', 'Category 1'];
        });
    }

    // Test retrieving a category returns 404 for non-existing category
    public function test_retrieve_category_returns_404_for_non_existing()
    {
        $response = $this->get(route('categories.show', ['category' => 999]));
        $response->assertStatus(404);
    }

    // Test showing a single category returns 404 when other categories exist
    public function test_retrieve_category_returns_404_when_other_categories_exist()
    {
        ProductCategory::factory()->count(10)->create();

        $response = $this->get(route('categories.show', ['category' => 999]));
        $response->assertStatus(404);
    }

    // Test retrieving a category
    public function test_can_retrieve_correct_category()
    {
        $categoryName = 'Test Category';
        $category = ProductCategory::factory()->create([
            'name' => $categoryName,
        ]);
        ProductCategory::factory(5)->create();

        $response = $this->get(route('categories.show', ['category' => $category]));

        $response->assertStatus(200);
        $response->assertViewIs('categories.show');
        $response->assertViewHas('category', function ($viewCategory) use ($category, $categoryName) {
            return $viewCategory->id === $category->id
                && $viewCategory->name === $categoryName;
        });
    }

    // Test retrieving a category with one product
    public function test_can_retrieve_category_with_its_product()
    {
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $response = $this->get(route('categories.show', $category->slug));

        $response->assertStatus(200);
        $response->assertViewHas('category', function ($viewCategory) use ($category, $product) {
            return $viewCategory->id === $category->id
                && $viewCategory->products->contains($product);
        });
    }

    // Test can view the category products page
    public function test_can_view_category_products_page()
    {
        $category = ProductCategory::factory()->create();

        $response = $this->get(route('categories.products', ['category' => $category]));

        $response->assertStatus(200);
        $response->assertViewIs('categories.products');
        $response->assertViewHas('products', function ($viewProducts) {
            return $viewProducts->count() === 0;
        });
    }

    // Test category products page shows only products belonging to the specific category
    public function test_category_products_page_shows_only_its_own_products()
    {
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();

        $products = Product::factory()->count(5)->sequence(
            ['name' => 'Product 1', 'category_id' => $category1->id],
            ['name' => 'Product 2', 'category_id' => $category2->id],
            ['name' => 'Product 3', 'category_id' => $category1->id],
            ['name' => 'Product 4', 'category_id' => $category2->id],
            ['name' => 'Product 5', 'category_id' => $category2->id]
        )->create();

        $response = $this->get(route('categories.products', ['category' => $category1]));

        $response->assertStatus(200);
        $response->assertViewIs('categories.products');
        $response->assertViewHas('products', function ($viewProducts) use ($products) {
            $category1Products = collect([$products->get(0), $products->get(2)]);

            return
                $viewProducts->count() === 2 &&
                $viewProducts->pluck('id')->diff($category1Products->pluck('id'))->isEmpty();
        });
    }

    // Test fetching products for a given category with pagination
    public function test_can_view_paginated_products_for_category()
    {
        $category = ProductCategory::factory()->create();
        Product::factory()->count(5)->create(['category_id' => $category->id]);
        config(['pagination.per_page' => 3]);

        $response = $this->get(route('categories.products', ['category' => $category, 'page' => 2]));

        $response->assertStatus(200);
        $response->assertViewIs('categories.products');
        $response->assertViewHas('products', function ($viewProducts) {
            return $viewProducts->count() === 2;
        });
    }
}
