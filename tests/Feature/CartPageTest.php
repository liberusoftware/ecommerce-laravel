<?php

namespace Tests\Feature;

use App\Livewire\ShoppingCart;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CartPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_lines_show_image_currency_and_quantity_controls(): void
    {
        $product = Product::factory()->create([
            'name' => 'Ceramic Lamp', 'price' => 69.00, 'inventory_count' => 10,
            'featured_image' => 'products/lamp.webp',
        ]);
        $product->store->update(['currency_code' => 'GBP']);
        $this->withStoredCart([$product->id => ['price' => 69.00, 'quantity' => 2]]);

        Livewire::test(ShoppingCart::class)
            ->assertSee('Ceramic Lamp')
            ->assertSee('/storage/products/lamp.webp')
            ->assertSee('£138.00')
            ->assertSee('Decrease quantity of Ceramic Lamp')
            ->assertSee('Proceed to checkout');
    }

    public function test_an_empty_cart_offers_a_way_back_to_the_catalogue(): void
    {
        $this->get(route('cart.index'))->assertOk()->assertSee('Your cart is empty')->assertSee(route('products.index'));
    }
}
