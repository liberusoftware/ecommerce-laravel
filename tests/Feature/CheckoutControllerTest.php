<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $overrides = []): Product
    {
        $category = ProductCategory::create([
            'name' => 'Checkout Category',
            'slug' => 'checkout-cat-'.uniqid(),
        ]);

        return Product::create(array_merge([
            'name' => 'Checkout Product',
            'slug' => 'checkout-prod-'.uniqid(),
            'price' => 50.00,
            'category_id' => $category->id,
            'inventory_count' => 20,
            'is_downloadable' => false,
        ], $overrides));
    }

    private function makeShippingMethod(array $overrides = []): ShippingMethod
    {
        return ShippingMethod::create(array_merge([
            'name' => 'Standard Shipping',
            'description' => 'Standard delivery',
            'base_rate' => 5.00,
            'weight_rate' => 0.00,
            'max_weight' => 100.0,
        ], $overrides));
    }

    public function test_initiate_checkout_redirects_with_empty_cart(): void
    {
        $response = $this->get(route('checkout.initiate'));

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('error');
    }

    public function test_initiate_checkout_returns_view_with_cart(): void
    {
        $product = $this->makeProduct();
        $this->withStoredCart([
            $product->id => [
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => 1,
                'is_downloadable' => false,
            ],
        ]);

        $response = $this->get(route('checkout.initiate'));

        $response->assertStatus(200);
        $response->assertViewHas('cart');
    }

    public function test_process_checkout_validation_requires_email(): void
    {
        $response = $this->post(route('checkout.process'), []);

        $response->assertSessionHasErrors('email');
    }

    public function test_process_checkout_with_empty_cart_redirects(): void
    {
        $response = $this->post(route('checkout.process'), [
            'email' => 'test@example.com',
            'country' => 'US',
            'payment_method' => 'cod',
        ]);

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('error');
    }

    public function test_guest_checkout_stores_session_data(): void
    {
        $product = $this->makeProduct();
        $this->withStoredCart([
            $product->id => [
                'name' => $product->name,
                'price' => 50.00,
                'quantity' => 1,
                'is_downloadable' => false,
            ],
        ]);

        $response = $this->post(route('checkout.initiate'));

        // The cart is not in the session any more — the session holds only the
        // token that claims these rows, and checkout reads the rows.
        $this->assertNotNull(session('cart_token'));
        $this->assertSame(1, CartItem::count());
    }

    public function test_show_confirmation_returns_view(): void
    {
        $customer = Customer::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone_number' => '555-0000',
            'address' => '1 Test St',
            'city' => 'Testville',
            'state' => 'CA',
            'postal_code' => '90001',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'customer_email' => 'test@example.com',
            'order_date' => now()->toDateString(),
            'total_amount' => 55.00,
            'payment_status' => 'paid',
            'shipping_status' => 'pending',
            'status' => 'paid',
        ]);

        // A guest is authorized to view the order they just placed via the session
        // marker the checkout flow sets — the confirmation is no longer an open IDOR.
        $response = $this->withSession(['recent_order_ids' => [$order->id]])
            ->get(route('checkout.confirmation', ['order' => $order->id]));

        $response->assertStatus(200);
        $response->assertViewHas('order');
    }
}
