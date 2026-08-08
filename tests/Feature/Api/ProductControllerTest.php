<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Product write endpoints are admin-gated; these CRUD tests act as an admin.
        Role::findOrCreate('super_admin', 'web');
        $this->user = User::factory()->create()->assignRole('super_admin');
        // Products and collections carry team_id with a database default of 1,
        // and the API writes are now ownership-checked (#939) — so the admin
        // acting here has to be in team 1 for the rows these tests create to be
        // theirs to edit.
        Team::factory()->create(['id' => 1, 'user_id' => $this->user->id]);
    }

    /**
     * Test index endpoint returns paginated products
     */
    public function test_index_returns_paginated_products()
    {
        Product::factory()->count(25)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'price',
                        'short_description',
                        'long_description',
                        'category_id',
                        'featured_image',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'current_page',
                'per_page',
                'total',
                'last_page',
            ]);

        $this->assertEquals(15, count($response->json('data')));
    }

    /**
     * Test index endpoint with custom per_page parameter
     */
    public function test_index_with_custom_per_page()
    {
        Product::factory()->count(30)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products?per_page=10');

        $response->assertStatus(200);
        $this->assertEquals(10, count($response->json('data')));
    }

    /**
     * Test index endpoint with search filter
     */
    public function test_index_with_search_filter()
    {
        Product::factory()->create(['name' => 'Unique Product Name']);
        Product::factory()->count(5)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products?search=Unique');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data')));
        $this->assertStringContainsString('Unique', $response->json('data.0.name'));
    }

    /**
     * Test index endpoint with price range filters
     */
    public function test_index_with_price_filters()
    {
        Product::factory()->create(['price' => 50]);
        Product::factory()->create(['price' => 150]);
        Product::factory()->create(['price' => 250]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products?price_min=100&price_max=200');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(1, count($data));
        $this->assertEquals(150, $data[0]['price']);
    }

    /**
     * Test index endpoint with sorting
     */
    public function test_index_with_sorting()
    {
        Product::factory()->create(['name' => 'Product C', 'price' => 300]);
        Product::factory()->create(['name' => 'Product A', 'price' => 100]);
        Product::factory()->create(['name' => 'Product B', 'price' => 200]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products?sort_by=price&sort_order=asc&per_page=100');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(100, $data[0]['price']);
        $this->assertEquals(200, $data[1]['price']);
        $this->assertEquals(300, $data[2]['price']);
    }

    /**
     * Test index requires authentication
     */
    public function test_index_requires_authentication()
    {
        $response = $this->getJson('/api/products');
        $response->assertStatus(401);
    }

    /**
     * Test show endpoint returns product by ID
     */
    public function test_show_returns_product_by_id()
    {
        $product = Product::factory()->create([
            'name' => 'Test Product',
            'price' => 99.99,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $product->id,
                    'name' => 'Test Product',
                    'price' => '99.99',
                ],
            ]);
    }

    /**
     * Test show endpoint returns product by slug
     */
    public function test_show_returns_product_by_slug()
    {
        $product = Product::factory()->create([
            'name' => 'Test Product Name',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products/test-product-name');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $product->id,
                    'name' => 'Test Product Name',
                ],
            ]);
    }

    /**
     * Test show endpoint returns 404 for non-existent product
     */
    public function test_show_returns_404_for_non_existent_product()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products/999999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Product not found',
            ]);
    }

    /**
     * Test show requires authentication
     */
    public function test_show_requires_authentication()
    {
        $product = Product::factory()->create();
        $response = $this->getJson("/api/products/{$product->id}");
        $response->assertStatus(401);
    }

    /**
     * Test store endpoint creates a new product
     */
    public function test_store_creates_product()
    {
        $category = ProductCategory::factory()->create();

        $productData = [
            'name' => 'New Product',
            'short_description' => 'Short description',
            'long_description' => 'Long description',
            'price' => 149.99,
            'category_id' => $category->id,
            'featured_image' => 'https://example.com/image.jpg',
            'inventory_count' => 100,
            'pricing_type' => 'fixed',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/products', $productData);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => [
                    'name' => 'New Product',
                    'price' => '149.99',
                ],
            ]);

        $this->assertDatabaseHas('products', [
            'name' => 'New Product',
            'price' => 149.99,
        ]);
    }

    /**
     * Test store endpoint validates required fields
     */
    public function test_store_validates_required_fields()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/products', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonValidationErrors(['name', 'short_description', 'price', 'category_id']);
    }

    /**
     * Test store endpoint validates price is numeric
     */
    public function test_store_validates_price_is_numeric()
    {
        $category = ProductCategory::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Test Product',
                'short_description' => 'Test',
                'price' => 'invalid',
                'category_id' => $category->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['price']);
    }

    /**
     * Test store requires authentication
     */
    public function test_store_requires_authentication()
    {
        $response = $this->postJson('/api/products', []);
        $response->assertStatus(401);
    }

    /**
     * Test update endpoint updates a product
     */
    public function test_update_updates_product()
    {
        $product = Product::factory()->create([
            'name' => 'Original Name',
            'price' => 100,
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'price' => 200,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/products/{$product->id}", $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => [
                    'name' => 'Updated Name',
                    'price' => '200.00',
                ],
            ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Name',
            'price' => 200,
        ]);
    }

    /**
     * Test update endpoint with PATCH method
     */
    public function test_update_works_with_patch_method()
    {
        $product = Product::factory()->create(['name' => 'Original']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/products/{$product->id}", [
                'name' => 'Patched Name',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Patched Name',
        ]);
    }

    /**
     * Test update endpoint returns 404 for non-existent product
     */
    public function test_update_returns_404_for_non_existent_product()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/products/999999', [
                'name' => 'Updated Name',
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Product not found',
            ]);
    }

    /**
     * Test update requires authentication
     */
    public function test_update_requires_authentication()
    {
        $product = Product::factory()->create();
        $response = $this->putJson("/api/products/{$product->id}", []);
        $response->assertStatus(401);
    }

    /**
     * Test destroy endpoint soft deletes a product
     */
    public function test_destroy_soft_deletes_product()
    {
        $product = Product::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product deleted successfully',
            ]);

        // Check that product is soft deleted
        $this->assertSoftDeleted('products', [
            'id' => $product->id,
        ]);

        // Verify product still exists in database but with deleted_at set
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
        ]);

        // Verify product is not returned in normal queries
        $this->assertNull(Product::find($product->id));
    }

    /**
     * Test destroy endpoint returns 404 for non-existent product
     */
    public function test_destroy_returns_404_for_non_existent_product()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/products/999999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Product not found',
            ]);
    }

    /**
     * Test destroy requires authentication
     */
    public function test_destroy_requires_authentication()
    {
        $product = Product::factory()->create();
        $response = $this->deleteJson("/api/products/{$product->id}");
        $response->assertStatus(401);
    }

    /**
     * Test index endpoint with category filter
     */
    public function test_index_with_category_filter()
    {
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();

        Product::factory()->count(3)->create(['category_id' => $category1->id]);
        Product::factory()->count(2)->create(['category_id' => $category2->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/products?category_id={$category1->id}");

        $response->assertStatus(200);
        $this->assertEquals(3, count($response->json('data')));
    }

    /**
     * Test that deleted products are not shown in index
     */
    public function test_index_does_not_show_deleted_products()
    {
        $product1 = Product::factory()->create(['name' => 'Active Product']);
        $product2 = Product::factory()->create(['name' => 'Deleted Product']);

        $product2->delete();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products?per_page=100');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(1, count($data));
        $this->assertEquals('Active Product', $data[0]['name']);
    }
}
