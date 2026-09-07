<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Product write endpoints are admin-gated; the CRUD tests below act as an admin.
        Role::findOrCreate('super_admin', 'web');
        $this->user = User::factory()->create()->assignRole('super_admin');
        // Products and collections carry team_id with a database default of 1,
        // and the API writes are now ownership-checked (#939) — so the admin
        // acting here has to be in team 1 for the rows these tests create to be
        // theirs to edit.
        Team::factory()->create(['id' => 1, 'user_id' => $this->user->id]);
    }

    public function test_unauthenticated_cannot_list_products(): void
    {
        $response = $this->getJson('/api/products');

        $response->assertStatus(401);
    }

    public function test_authenticated_can_list_products(): void
    {
        Sanctum::actingAs($this->user);
        Product::factory()->count(3)->create();

        $response = $this->getJson('/api/products');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'per_page', 'current_page']);
    }

    public function test_products_are_paginated(): void
    {
        Sanctum::actingAs($this->user);
        Product::factory()->count(20)->create();

        $response = $this->getJson('/api/products?per_page=5');

        $response->assertStatus(200)
            ->assertJsonPath('per_page', 5);
    }

    public function test_can_filter_products_by_search(): void
    {
        Sanctum::actingAs($this->user);
        Product::factory()->create(['name' => 'Unique Widget Name']);
        Product::factory()->create(['name' => 'Other Product']);

        $response = $this->getJson('/api/products?search=Widget');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Unique Widget Name', $data[0]['name']);
    }

    public function test_can_create_product(): void
    {
        Sanctum::actingAs($this->user);
        $category = ProductCategory::factory()->create();

        $payload = [
            'name' => 'New Product',
            'price' => 19.99,
            'description' => 'A great product',
            'short_description' => 'Short desc',
            'category_id' => $category->id,
        ];

        $response = $this->postJson('/api/products', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Product');
    }

    public function test_can_get_product_by_id(): void
    {
        Sanctum::actingAs($this->user);
        $product = Product::factory()->create();

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $product->id);
    }

    public function test_can_update_product(): void
    {
        Sanctum::actingAs($this->user);
        $product = Product::factory()->create(['name' => 'Old Name']);

        $response = $this->putJson("/api/products/{$product->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_can_delete_product(): void
    {
        Sanctum::actingAs($this->user);
        $product = Product::factory()->create();

        $response = $this->deleteJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_returns_404_for_nonexistent_product(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/products/999999');

        $response->assertStatus(404);
    }
}
