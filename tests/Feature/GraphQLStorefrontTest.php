<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GraphQLStorefrontTest extends TestCase
{
    use RefreshDatabase;

    /** @return array the decoded GraphQL response */
    private function gql(string $query, array $variables = []): array
    {
        return $this->postJson('/api/graphql', ['query' => $query, 'variables' => $variables])->json();
    }

    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'price' => 10.0, 'inventory_count' => 5, 'is_downloadable' => false,
        ], $overrides));
    }

    // --- Queries ---------------------------------------------------------

    public function test_products_query_returns_a_paginated_catalogue(): void
    {
        $this->product(['name' => 'Alpha']);
        $this->product(['name' => 'Beta']);
        $this->product(['name' => 'Gamma']);

        $data = $this->gql('{ products(perPage: 2) { data { id name price } total currentPage lastPage perPage } }');

        $this->assertSame(3, $data['data']['products']['total']);
        $this->assertSame(2, $data['data']['products']['lastPage']);
        $this->assertCount(2, $data['data']['products']['data']);
        $this->assertEqualsWithDelta(10.0, $data['data']['products']['data'][0]['price'], 0.001);
    }

    public function test_products_query_search_filters_by_name(): void
    {
        $this->product(['name' => 'Blue Widget']);
        $this->product(['name' => 'Red Gadget']);

        $data = $this->gql('{ products(search: "Widget") { data { name } total } }');

        $this->assertSame(1, $data['data']['products']['total']);
        $this->assertSame('Blue Widget', $data['data']['products']['data'][0]['name']);
    }

    public function test_product_by_slug_and_by_id(): void
    {
        $product = $this->product(['name' => 'Findable', 'slug' => 'findable']);

        $bySlug = $this->gql('{ product(slug: "findable") { id name } }');
        $this->assertSame('Findable', $bySlug['data']['product']['name']);

        $byId = $this->gql('query($id: Int!) { product(id: $id) { slug } }', ['id' => $product->id]);
        $this->assertSame('findable', $byId['data']['product']['slug']);
    }

    public function test_product_query_is_null_when_missing(): void
    {
        $data = $this->gql('{ product(slug: "nope") { id } }');

        $this->assertNull($data['data']['product']);
    }

    public function test_collections_query_exposes_their_products(): void
    {
        $collection = ProductCollection::create(['name' => 'Summer', 'slug' => 'summer']);
        $collection->products()->attach($this->product(['name' => 'Sunhat'])->id);

        $data = $this->gql('{ collections { name products { name } } }');

        $this->assertSame('Summer', $data['data']['collections'][0]['name']);
        $this->assertSame('Sunhat', $data['data']['collections'][0]['products'][0]['name']);
    }

    public function test_me_is_null_for_guests_and_set_for_authenticated_users(): void
    {
        $this->assertNull($this->gql('{ me { id } }')['data']['me']);

        Sanctum::actingAs($user = User::factory()->create());
        $data = $this->gql('{ me { id email } }');
        $this->assertSame($user->email, $data['data']['me']['email']);
    }

    public function test_orders_are_scoped_to_the_authenticated_user(): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();
        Order::create(['user_id' => $mine->id, 'customer_email' => 'a@b.com', 'total_amount' => 50, 'status' => 'paid']);
        Order::create(['user_id' => $other->id, 'customer_email' => 'c@d.com', 'total_amount' => 99, 'status' => 'paid']);

        Sanctum::actingAs($mine);
        $data = $this->gql('{ orders { id totalAmount } }');

        $this->assertCount(1, $data['data']['orders']);
        $this->assertEqualsWithDelta(50.0, $data['data']['orders'][0]['totalAmount'], 0.001);
    }

    public function test_orders_are_empty_for_guests(): void
    {
        Order::create(['user_id' => User::factory()->create()->id, 'customer_email' => 'a@b.com', 'total_amount' => 50, 'status' => 'paid']);

        $this->assertSame([], $this->gql('{ orders { id } }')['data']['orders']);
    }

    // --- Mutations -------------------------------------------------------

    public function test_add_to_cart_requires_authentication(): void
    {
        $product = $this->product();

        $data = $this->gql('mutation($p: Int!) { addToCart(productId: $p, quantity: 1) { id } }', ['p' => $product->id]);

        // webonyx omits the data key when a non-null root field errors; only errors remain.
        $this->assertSame('Unauthenticated.', $data['errors'][0]['message']);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_add_to_cart_persists_an_item(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $product = $this->product(['inventory_count' => 10]);

        $data = $this->gql('mutation($p: Int!) { addToCart(productId: $p, quantity: 2) { quantity lineTotal product { name } } }', ['p' => $product->id]);

        $this->assertSame(2, $data['data']['addToCart']['quantity']);
        $this->assertEqualsWithDelta(20.0, $data['data']['addToCart']['lineTotal'], 0.001);
        $this->assertDatabaseHas('cart_items', ['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 2]);
    }

    public function test_add_to_cart_rejects_quantity_over_stock(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $product = $this->product(['inventory_count' => 1]);

        $data = $this->gql('mutation($p: Int!) { addToCart(productId: $p, quantity: 5) { id } }', ['p' => $product->id]);

        $this->assertStringContainsString('exceeds available stock', $data['errors'][0]['message']);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_update_cart_item_changes_quantity(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $product = $this->product(['inventory_count' => 10]);
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 10]);

        $this->gql('mutation($p: Int!) { updateCartItem(productId: $p, quantity: 4) { quantity } }', ['p' => $product->id]);

        $this->assertDatabaseHas('cart_items', ['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 4]);
    }

    public function test_cart_mutations_are_user_scoped(): void
    {
        $owner = User::factory()->create();
        $product = $this->product(['inventory_count' => 10]);
        CartItem::create(['user_id' => $owner->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 10]);

        // A different user cannot touch the owner's line item.
        Sanctum::actingAs(User::factory()->create());
        $data = $this->gql('mutation($p: Int!) { updateCartItem(productId: $p, quantity: 9) { id } }', ['p' => $product->id]);

        $this->assertStringContainsString('not in your cart', $data['errors'][0]['message']);
        $this->assertDatabaseHas('cart_items', ['user_id' => $owner->id, 'product_id' => $product->id, 'quantity' => 1]);
    }

    public function test_remove_cart_item(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $product = $this->product();
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 10]);

        $data = $this->gql('mutation($p: Int!) { removeCartItem(productId: $p) }', ['p' => $product->id]);

        $this->assertTrue($data['data']['removeCartItem']);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_empty_query_is_rejected(): void
    {
        $this->postJson('/api/graphql', ['query' => ''])->assertStatus(400);
    }
}
