<?php

namespace Tests\Feature;

use App\Livewire\ShoppingCart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The cart must derive price / name / downloadable purely from the Product — the
 * add-to-cart paths previously trusted client-supplied values, and checkout charges
 * whatever the session cart holds. That let a shopper set price=0.01 (buy anything
 * for a cent), forge is_downloadable (skip the stock/shipping gate), or add a
 * negative quantity (drag the total down and INCREMENT stock at checkout).
 */
class CartInputTamperingTest extends TestCase
{
    use RefreshDatabase;

    public function test_livewire_add_to_cart_ignores_client_supplied_price_and_name(): void
    {
        $product = Product::factory()->create(['price' => 100, 'inventory_count' => 10, 'is_downloadable' => false]);

        Livewire::test(ShoppingCart::class)->dispatch(
            'addToCart',
            productId: $product->id, name: 'HACKED', price: 0.01, quantity: 1, isDownloadable: false, weight: 0
        );

        $item = CartItem::where('product_id', $product->id)->first();
        $this->assertNotNull($item);
        $this->assertEquals(100, $item->price, 'Cart must use the product price, not the client value');
    }

    public function test_livewire_add_to_cart_ignores_forged_downloadable_flag(): void
    {
        // Out-of-stock PHYSICAL product; forging isDownloadable=true would skip the stock gate.
        $product = Product::factory()->create(['price' => 50, 'inventory_count' => 0, 'is_downloadable' => false]);

        Livewire::test(ShoppingCart::class)->dispatch(
            'addToCart',
            productId: $product->id, name: 'x', price: 50, quantity: 1, isDownloadable: true, weight: 0
        );

        $this->assertSame(0, CartItem::where('product_id', $product->id)->count(), 'A forged downloadable flag must not bypass the stock check');
    }

    public function test_livewire_add_to_cart_never_stores_a_non_positive_quantity(): void
    {
        $product = Product::factory()->create(['price' => 100, 'inventory_count' => 10, 'is_downloadable' => false]);

        Livewire::test(ShoppingCart::class)->dispatch(
            'addToCart',
            productId: $product->id, name: 'x', price: 100, quantity: -5, isDownloadable: false, weight: 0
        );

        $quantity = CartItem::where('product_id', $product->id)->value('quantity');
        if ($quantity !== null) {
            $this->assertGreaterThanOrEqual(1, $quantity);
        }
    }

    public function test_cart_controller_add_rejects_non_positive_quantity(): void
    {
        $product = Product::factory()->create(['price' => 100, 'inventory_count' => 10]);

        $this->post(route('cart.add', $product), ['quantity' => -5])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(0, CartItem::where('product_id', $product->id)->count());
    }
}
