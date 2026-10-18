<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Product status, visibility and sale price as the storefront, cart and checkout see them. */
class ProductCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + ['inventory_count' => 10, 'price' => 40]);
    }

    public function test_draft_and_archived_products_are_not_on_the_storefront(): void
    {
        $draft = $this->product(['name' => 'Draft Lantern', 'status' => Product::STATUS_DRAFT]);
        $archived = $this->product(['name' => 'Archived Lantern', 'status' => Product::STATUS_ARCHIVED]);
        $this->product(['name' => 'Live Lantern', 'is_featured' => true]);

        $this->get(route('products.show', $draft))->assertNotFound();
        $this->get(route('products.show', $archived))->assertNotFound();
        $this->get(route('products.index'))->assertSee('Live Lantern')->assertDontSee('Draft Lantern')->assertDontSee('Archived Lantern');
        $this->get(route('products.search', ['keyword' => 'Lantern']))->assertSee('Live Lantern')->assertDontSee('Draft Lantern');
        $this->get(route('home'))->assertSee('Live Lantern')->assertDontSee('Draft Lantern');
        $this->get('/sitemap.xml')->assertDontSee($draft->slug);
    }

    public function test_a_hidden_product_is_reachable_by_link_but_not_listed(): void
    {
        $hidden = $this->product(['name' => 'Secret Sauce', 'visibility' => Product::VISIBILITY_HIDDEN]);

        $this->get(route('products.show', $hidden))->assertOk()->assertSee('Secret Sauce');
        $this->get(route('products.index'))->assertDontSee('Secret Sauce');
        $this->get(route('categories.products', $hidden->category))->assertDontSee('Secret Sauce');
    }

    public function test_graphql_lists_only_listed_products_and_hides_drafts(): void
    {
        $this->product(['name' => 'Listed One']);
        $draft = $this->product(['name' => 'Draft One', 'status' => Product::STATUS_DRAFT]);

        $this->postJson('/api/graphql', ['query' => '{ products { data { name } } }'])
            ->assertJsonFragment(['name' => 'Listed One'])
            ->assertJsonMissing(['name' => 'Draft One']);

        $this->postJson('/api/graphql', ['query' => '{ product(id: '.$draft->id.') { name } }'])
            ->assertJsonPath('data.product', null);
    }

    public function test_an_inactive_product_cannot_be_added_to_a_cart(): void
    {
        $draft = $this->product(['status' => Product::STATUS_DRAFT]);

        $this->post(route('cart.add', $draft))->assertSessionHas('error', 'This product is not available.');
        $this->assertSame(0, CartItem::count());

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/cart', ['product_id' => $draft->id, 'quantity' => 1])->assertNotFound();
    }

    public function test_the_sale_price_is_what_the_cart_charges_and_the_page_shows(): void
    {
        $product = $this->product(['price' => 50, 'sale_price' => 35]);

        $this->assertTrue($product->isOnSale());
        $this->get(route('products.show', $product))->assertSee('35.00')->assertSee('<s', false)->assertSee('50.00');

        $this->post(route('cart.add', $product), ['quantity' => 2]);

        $this->assertEquals(35.0, CartItem::sole()->price);
    }

    public function test_a_sale_price_at_or_above_the_price_is_ignored(): void
    {
        $product = $this->product(['price' => 50, 'sale_price' => 60]);

        $this->assertFalse($product->isOnSale());
        $this->assertSame(50.0, $product->sellingPrice());
    }

    public function test_checkout_refuses_a_product_taken_down_after_it_was_carted(): void
    {
        $product = $this->product(['price' => 20, 'is_downloadable' => true]);
        $this->withStoredCart([$product->id => ['price' => 20, 'quantity' => 1]]);
        $product->update(['status' => Product::STATUS_DRAFT]);

        $this->post(route('checkout.process'), [
            'email' => 'buyer@example.com', 'country' => 'GB', 'payment_method' => 'stripe', 'stripeToken' => 'tok',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_adding_to_cart_counts_what_is_already_there_against_stock(): void
    {
        $product = $this->product(['inventory_count' => 3]);

        $this->post(route('cart.add', $product), ['quantity' => 2])->assertSessionHas('success');
        $this->post(route('cart.add', $product), ['quantity' => 2])->assertSessionHas('error', 'Not enough inventory available.');

        $this->assertSame(2, CartItem::sole()->quantity);
    }

    public function test_pay_what_you_want_takes_the_shoppers_price_above_the_minimum(): void
    {
        $product = $this->product(['pricing_type' => 'donation', 'price' => 0, 'minimum_price' => 5, 'suggested_price' => 10]);

        $this->post(route('cart.add', $product), ['price' => 2])->assertSessionHasErrors('price');
        $this->post(route('cart.add', $product), ['price' => 12.5])->assertSessionHas('success');

        $this->assertEquals(12.5, CartItem::sole()->price);
    }

    public function test_slugs_are_unique_within_a_store_and_free_across_stores(): void
    {
        $first = $this->product(['name' => 'Blue Shirt']);
        $second = $this->product(['name' => 'Blue Shirt']);

        $this->assertSame('blue-shirt', $first->slug);
        $this->assertSame('blue-shirt-2', $second->slug);

        $other = Store::factory()->create();
        $elsewhere = new Product(['name' => 'Blue Shirt', 'price' => 10]);
        $elsewhere->store_id = $other->id;
        $elsewhere->save();

        $this->assertSame('blue-shirt', $elsewhere->slug);
    }

    public function test_renaming_a_product_keeps_its_url(): void
    {
        $product = $this->product(['name' => 'Old Name']);

        $product->update(['name' => 'New Name']);

        $this->assertSame('old-name', $product->fresh()->slug);
        $this->get('/products/old-name')->assertOk()->assertSee('New Name');
    }

    public function test_the_gallery_and_sku_appear_on_the_product_page(): void
    {
        $product = $this->product(['sku' => 'LMP-001', 'featured_image' => 'products/main.webp']);
        $product->images()->create(['image' => 'products/side.webp', 'order' => 1]);

        $this->get(route('products.show', $product))
            ->assertSee('LMP-001')
            ->assertSee('/storage/products/main.webp')
            ->assertSee('/storage/products/side.webp')
            ->assertSee('"sku": "LMP-001"', false);
    }
}
