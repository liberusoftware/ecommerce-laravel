<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CollectionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Collection write endpoints are admin-gated; these tests act as an admin.
        Role::findOrCreate('super_admin', 'web');
        $this->user = User::factory()->create()->assignRole('super_admin');
        // Products and collections carry team_id with a database default of 1,
        // and the API writes are now ownership-checked (#939) — so the admin
        // acting here has to be in team 1 for the rows these tests create to be
        // theirs to edit.
        Team::factory()->create(['id' => 1, 'user_id' => $this->user->id]);
        Sanctum::actingAs($this->user);
    }

    public function test_can_list_all_collections()
    {
        ProductCollection::factory()->count(3)->create();

        $response = $this->getJson('/api/collections');

        $response->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function test_can_list_paginated_collections()
    {
        ProductCollection::factory()->count(20)->create();

        $response = $this->getJson('/api/collections?per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ])
            ->assertJsonCount(10, 'data');
    }

    public function test_can_create_collection()
    {
        $data = [
            'name' => 'Summer Collection',
            'description' => 'Summer products',
            'price' => 99.99,
        ];

        $response = $this->postJson('/api/collections', $data);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Collection created successfully',
            ])
            ->assertJsonStructure([
                'data' => ['id', 'name', 'slug', 'description', 'price'],
            ]);

        $this->assertDatabaseHas('collections', [
            'name' => 'Summer Collection',
            'slug' => 'summer-collection',
        ]);
    }

    public function test_can_create_collection_with_custom_slug()
    {
        $data = [
            'name' => 'Winter Collection',
            'slug' => 'custom-winter-slug',
            'description' => 'Winter products',
        ];

        $response = $this->postJson('/api/collections', $data);

        $response->assertStatus(201);

        $this->assertDatabaseHas('collections', [
            'name' => 'Winter Collection',
            'slug' => 'custom-winter-slug',
        ]);
    }

    public function test_cannot_create_collection_without_name()
    {
        $data = [
            'description' => 'Test description',
        ];

        $response = $this->postJson('/api/collections', $data);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_show_collection_by_id()
    {
        $collection = ProductCollection::factory()->create([
            'name' => 'Test Collection',
        ]);
        $products = Product::factory()->count(3)->create();
        $collection->products()->attach($products->pluck('id'));

        $response = $this->getJson("/api/collections/{$collection->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $collection->id,
                    'name' => 'Test Collection',
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'products',
                ],
            ])
            ->assertJsonCount(3, 'data.products');
    }

    public function test_can_show_collection_by_slug()
    {
        $collection = ProductCollection::factory()->create([
            'name' => 'Test Collection',
            'slug' => 'test-collection',
        ]);

        $response = $this->getJson('/api/collections/test-collection');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $collection->id,
                    'slug' => 'test-collection',
                ],
            ]);
    }

    public function test_show_returns_404_for_non_existent_collection()
    {
        $response = $this->getJson('/api/collections/999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Collection not found',
            ]);
    }

    public function test_can_update_collection()
    {
        $collection = ProductCollection::factory()->create([
            'name' => 'Original Name',
        ]);

        $data = [
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ];

        $response = $this->putJson("/api/collections/{$collection->id}", $data);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Collection updated successfully',
                'data' => [
                    'name' => 'Updated Name',
                    'description' => 'Updated description',
                ],
            ]);

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_update_returns_404_for_non_existent_collection()
    {
        $response = $this->putJson('/api/collections/999', [
            'name' => 'Test',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Collection not found',
            ]);
    }

    public function test_can_add_products_to_collection()
    {
        $collection = ProductCollection::factory()->create();
        $products = Product::factory()->count(3)->create();

        $data = [
            'product_ids' => $products->pluck('id')->toArray(),
        ];

        $response = $this->postJson("/api/collections/{$collection->id}/products", $data);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Products added to collection successfully',
            ])
            ->assertJsonCount(3, 'data.products');

        $this->assertCount(3, $collection->fresh()->products);
    }

    public function test_can_add_products_with_quantities_to_collection()
    {
        $collection = ProductCollection::factory()->create();
        $products = Product::factory()->count(2)->create();

        $data = [
            'product_ids' => $products->pluck('id')->toArray(),
            'quantities' => [5, 10],
        ];

        $response = $this->postJson("/api/collections/{$collection->id}/products", $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('collection_items', [
            'collection_id' => $collection->id,
            'product_id' => $products[0]->id,
            'quantity' => 5,
        ]);

        $this->assertDatabaseHas('collection_items', [
            'collection_id' => $collection->id,
            'product_id' => $products[1]->id,
            'quantity' => 10,
        ]);
    }

    public function test_add_products_returns_404_for_non_existent_collection()
    {
        $products = Product::factory()->count(2)->create();

        $data = [
            'product_ids' => $products->pluck('id')->toArray(),
        ];

        $response = $this->postJson('/api/collections/999/products', $data);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Collection not found',
            ]);
    }

    public function test_can_remove_products_from_collection()
    {
        $collection = ProductCollection::factory()->create();
        $products = Product::factory()->count(5)->create();
        $collection->products()->attach($products->pluck('id'));

        $productsToRemove = $products->take(2)->pluck('id')->toArray();

        $data = [
            'product_ids' => $productsToRemove,
        ];

        $response = $this->deleteJson("/api/collections/{$collection->id}/products", $data);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Products removed from collection successfully',
            ])
            ->assertJsonCount(3, 'data.products');

        $this->assertCount(3, $collection->fresh()->products);
    }

    public function test_remove_products_returns_404_for_non_existent_collection()
    {
        $products = Product::factory()->count(2)->create();

        $data = [
            'product_ids' => $products->pluck('id')->toArray(),
        ];

        $response = $this->deleteJson('/api/collections/999/products', $data);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Collection not found',
            ]);
    }

    public function test_can_soft_delete_collection()
    {
        $collection = ProductCollection::factory()->create();

        $response = $this->deleteJson("/api/collections/{$collection->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Collection deleted successfully',
            ]);

        $this->assertSoftDeleted('collections', [
            'id' => $collection->id,
        ]);
    }

    public function test_delete_returns_404_for_non_existent_collection()
    {
        $response = $this->deleteJson('/api/collections/999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Collection not found',
            ]);
    }
}
