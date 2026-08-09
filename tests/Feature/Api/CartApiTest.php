<?php

namespace Tests\Feature\Api;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartApiTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 10, float $price = 25): Product
    {
        return Product::factory()->create(['inventory_count' => $stock, 'price' => $price]);
    }

    public function test_cart_endpoints_require_authentication(): void
    {
        $this->getJson('/api/cart')->assertUnauthorized();
        $this->postJson('/api/cart', [])->assertUnauthorized();
    }

    public function test_index_returns_only_the_users_own_items_with_subtotal(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $p1 = $this->product(price: 10);
        $p2 = $this->product(price: 5);
        CartItem::create(['user_id' => $user->id, 'product_id' => $p1->id, 'quantity' => 2, 'price' => 10]);
        CartItem::create(['user_id' => $user->id, 'product_id' => $p2->id, 'quantity' => 1, 'price' => 5]);
        CartItem::create(['user_id' => $other->id, 'product_id' => $p1->id, 'quantity' => 9, 'price' => 10]);

        $this->actingAs($user)->getJson('/api/cart')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('subtotal', 25);
    }

    public function test_store_adds_an_item(): void
    {
        $user = User::factory()->create();
        $product = $this->product(price: 12);

        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated();

        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 12,
        ]);
    }

    public function test_store_increments_quantity_for_an_existing_line(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 25]);

        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 3])
            ->assertCreated();

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 4]);
    }

    public function test_store_rejects_quantity_beyond_available_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 3);

        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 5])
            ->assertStatus(422);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_update_sets_the_quantity(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 10);
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 25]);

        $this->actingAs($user)->putJson("/api/cart/{$product->id}", ['quantity' => 6])
            ->assertOk();

        $this->assertDatabaseHas('cart_items', ['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 6]);
    }

    public function test_update_rejects_quantity_beyond_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 2);
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 25]);

        $this->actingAs($user)->putJson("/api/cart/{$product->id}", ['quantity' => 9])
            ->assertStatus(422);
    }

    public function test_update_404s_for_an_item_not_in_the_users_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->actingAs($user)->putJson("/api/cart/{$product->id}", ['quantity' => 2])
            ->assertNotFound();
    }

    public function test_destroy_removes_the_users_line(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 25]);

        $this->actingAs($user)->deleteJson("/api/cart/{$product->id}")->assertOk();

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_destroy_cannot_touch_another_users_line(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $product = $this->product();
        CartItem::create(['user_id' => $other->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 25]);

        $this->actingAs($user)->deleteJson("/api/cart/{$product->id}")->assertOk();

        // The other user's line is untouched — scoping prevents cross-user deletes.
        $this->assertDatabaseHas('cart_items', ['user_id' => $other->id, 'product_id' => $product->id]);
    }

    public function test_clear_empties_only_the_users_cart(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $product = $this->product();
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 25]);
        CartItem::create(['user_id' => $other->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 25]);

        $this->actingAs($user)->deleteJson('/api/cart')->assertOk();

        $this->assertDatabaseMissing('cart_items', ['user_id' => $user->id]);
        $this->assertDatabaseHas('cart_items', ['user_id' => $other->id]);
    }
}
