<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $overrides = []): Product
    {
        $category = ProductCategory::create([
            'name' => 'Test Category',
            'slug' => 'test-cat-'.uniqid(),
        ]);

        return Product::create(array_merge([
            'name' => 'Test Product',
            'slug' => 'test-prod-'.uniqid(),
            'price' => 29.99,
            'category_id' => $category->id,
            'inventory_count' => 100,
            'is_downloadable' => false,
        ], $overrides));
    }

    public function test_add_product_to_cart(): void
    {
        $product = $this->makeProduct();

        $response = $this->post(route('cart.add', $product), ['quantity' => 2]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(2, (int) CartItem::where('product_id', $product->id)->value('quantity'));
    }

    public function test_add_increments_quantity_if_already_in_cart(): void
    {
        $product = $this->makeProduct();

        $this->post(route('cart.add', $product), ['quantity' => 1]);
        $this->post(route('cart.add', $product), ['quantity' => 2]);

        $this->assertSame(3, (int) CartItem::where('product_id', $product->id)->value('quantity'));
    }

    public function test_add_returns_error_when_insufficient_inventory(): void
    {
        $product = $this->makeProduct(['inventory_count' => 2]);

        $response = $this->post(route('cart.add', $product), ['quantity' => 5]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_cart_index_returns_view(): void
    {
        $response = $this->get(route('cart.index'));

        $response->assertStatus(200);
    }

    public function test_update_cart_item_quantity(): void
    {
        $product = $this->makeProduct(['inventory_count' => 50]);
        $this->post(route('cart.add', $product), ['quantity' => 1]);

        $response = $this->put(route('cart.update', $product->id), ['quantity' => 3]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(3, (int) CartItem::where('product_id', $product->id)->value('quantity'));
    }

    public function test_update_returns_error_for_missing_product(): void
    {
        $response = $this->put(route('cart.update', 9999), ['quantity' => 1]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_update_returns_error_for_quantity_less_than_one(): void
    {
        $product = $this->makeProduct();
        $this->post(route('cart.add', $product), ['quantity' => 1]);

        $response = $this->put(route('cart.update', $product->id), ['quantity' => 0]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_update_returns_error_for_insufficient_inventory(): void
    {
        $product = $this->makeProduct(['inventory_count' => 2]);
        $this->post(route('cart.add', $product), ['quantity' => 1]);

        $response = $this->put(route('cart.update', $product->id), ['quantity' => 10]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_remove_product_from_cart(): void
    {
        $product = $this->makeProduct();
        $this->post(route('cart.add', $product), ['quantity' => 1]);

        $response = $this->delete(route('cart.remove', $product->id));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(0, CartItem::where('product_id', $product->id)->count());
    }

    public function test_remove_returns_error_when_product_not_in_cart(): void
    {
        $response = $this->delete(route('cart.remove', 9999));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_clear_empties_cart(): void
    {
        $product = $this->makeProduct();
        $this->post(route('cart.add', $product), ['quantity' => 1]);

        $response = $this->delete(route('cart.clear'));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(0, CartItem::count());
    }

    public function test_clear_also_removes_coupon(): void
    {
        session(['coupon' => ['code' => 'TEST10', 'discount' => 10]]);

        $this->delete(route('cart.clear'));

        $this->assertNull(session('coupon'));
    }

    public function test_apply_coupon_requires_coupon_code(): void
    {
        $response = $this->post(route('cart.apply-coupon'), []);

        $response->assertSessionHasErrors('coupon_code');
    }

    public function test_apply_coupon_returns_error_when_cart_empty(): void
    {
        $response = $this->post(route('cart.apply-coupon'), ['coupon_code' => 'SAVE10']);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_apply_valid_coupon_stores_in_session(): void
    {
        $product = $this->makeProduct(['price' => 100.00]);
        $this->post(route('cart.add', $product), ['quantity' => 1]);

        Coupon::create([
            'code' => 'SAVE10',
            'type' => 'percentage',
            'value' => 10,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
        ]);

        $response = $this->post(route('cart.apply-coupon'), ['coupon_code' => 'SAVE10']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertNotNull(session('coupon'));
    }

    public function test_apply_invalid_coupon_shows_error(): void
    {
        $product = $this->makeProduct(['price' => 100.00]);
        $this->post(route('cart.add', $product), ['quantity' => 1]);

        $response = $this->post(route('cart.apply-coupon'), ['coupon_code' => 'INVALID']);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_remove_coupon_clears_session(): void
    {
        session(['coupon' => ['code' => 'TEST10', 'discount' => 10]]);

        $response = $this->delete(route('cart.remove-coupon'));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertNull(session('coupon'));
    }
}
