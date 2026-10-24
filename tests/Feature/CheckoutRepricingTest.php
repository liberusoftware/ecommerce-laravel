<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentGateways\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Both checkout paths charge the catalogue's price at checkout, never the price
 * a cart line froze at when it was added.
 */
class CheckoutRepricingTest extends TestCase
{
    use RefreshDatabase;

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->gateway = new class implements PaymentGatewayInterface
        {
            public ?float $chargedAmount = null;

            public function processPayment(float $amount, array $paymentDetails): array
            {
                $this->chargedAmount = $amount;

                return ['success' => true, 'transaction_id' => 'ch_ok'];
            }

            public function processSubscription(string $planId, array $subscriptionDetails): array
            {
                return ['success' => true];
            }

            public function refundPayment(string $transactionId, float $amount): array
            {
                return ['success' => true];
            }
        };

        $this->app->instance(StripeGateway::class, $this->gateway);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + ['price' => 50, 'inventory_count' => 5, 'is_downloadable' => true]);
    }

    private function webCheckout()
    {
        return $this->post(route('checkout.process'), [
            'email' => 'buyer@example.com',
            'has_physical_products' => 0,
            'country' => 'US',
            'payment_method' => 'stripe',
            'stripeToken' => 'tok_test',
        ]);
    }

    private function graphqlCheckout(): array
    {
        return $this->postJson('/api/graphql', [
            'query' => 'mutation($input: CheckoutInput!) { checkout(input: $input) { id totalAmount } }',
            'variables' => ['input' => ['country' => 'US', 'paymentMethod' => 'stripe', 'stripeToken' => 'tok']],
        ])->json();
    }

    private function assertNothingCharged(Product $product, int $stock): void
    {
        $this->assertDatabaseCount('orders', 0);
        $this->assertNull($this->gateway->chargedAmount);
        $this->assertSame($stock, $product->fresh()->inventory_count);
    }

    public function test_web_checkout_refuses_a_stale_price_and_corrects_the_cart(): void
    {
        $product = $this->product();
        $this->withStoredCart([$product->id => ['quantity' => 2, 'price' => 50]]);
        $product->update(['price' => 60]);

        $this->webCheckout()->assertSessionHas('error', fn (string $m) => str_contains($m, 'Prices in your cart have changed'));

        $this->assertNothingCharged($product, 5);
        $this->assertEquals(60, CartItem::sole()->price);
    }

    public function test_web_checkout_charges_the_corrected_price_on_the_next_attempt(): void
    {
        $product = $this->product();
        $this->withStoredCart([$product->id => ['quantity' => 2, 'price' => 50]]);
        $product->update(['price' => 60]);

        $this->webCheckout();
        $this->webCheckout()->assertSessionHas('success');

        $order = Order::sole();
        $this->assertEqualsWithDelta(120.0, (float) $order->total_amount, 0.001);
        $this->assertEqualsWithDelta(120.0, $this->gateway->chargedAmount, 0.001);
        $this->assertEquals(60, $order->items()->sole()->price);
    }

    public function test_web_checkout_refuses_a_line_whose_sale_has_started(): void
    {
        $product = $this->product();
        $this->withStoredCart([$product->id => ['quantity' => 1, 'price' => 50]]);
        $product->update(['sale_price' => 40]);

        $this->webCheckout()->assertSessionHas('error');

        $this->assertNothingCharged($product, 5);
        $this->assertEquals(40, CartItem::sole()->price);
    }

    public function test_graphql_checkout_refuses_a_stale_price_and_corrects_the_cart(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $product = $this->product();
        $this->withStoredCart([$product->id => ['quantity' => 2, 'price' => 50]], $user);
        $product->update(['price' => 60]);

        $data = $this->graphqlCheckout();

        $this->assertStringContainsString('Prices in your cart have changed', $data['errors'][0]['message']);
        $this->assertNothingCharged($product, 5);
        $this->assertEquals(60, CartItem::sole()->price);

        $data = $this->graphqlCheckout();

        $this->assertEqualsWithDelta(120.0, $data['data']['checkout']['totalAmount'], 0.001);
        $this->assertEqualsWithDelta(120.0, $this->gateway->chargedAmount, 0.001);
        $this->assertEquals(60, Order::sole()->items()->sole()->price);
    }

    public function test_a_current_price_checks_out_without_interruption(): void
    {
        $product = $this->product(['sale_price' => 45]);
        $this->withStoredCart([$product->id => ['quantity' => 1, 'price' => 45]]);

        $this->webCheckout()->assertSessionHas('success');

        $this->assertEqualsWithDelta(45.0, $this->gateway->chargedAmount, 0.001);
    }

    public function test_pay_what_you_want_keeps_the_shoppers_amount_above_the_minimum(): void
    {
        $product = $this->product(['pricing_type' => 'donation', 'price' => 0, 'minimum_price' => 5, 'suggested_price' => 8]);
        $this->withStoredCart([$product->id => ['quantity' => 1, 'price' => 20]]);

        $this->webCheckout()->assertSessionHas('success');

        $this->assertEqualsWithDelta(20.0, $this->gateway->chargedAmount, 0.001);
    }

    public function test_pay_what_you_want_is_raised_to_a_minimum_set_after_adding(): void
    {
        $product = $this->product(['pricing_type' => 'donation', 'price' => 0, 'minimum_price' => 5]);
        $this->withStoredCart([$product->id => ['quantity' => 1, 'price' => 6]]);
        $product->update(['minimum_price' => 10]);

        $this->webCheckout()->assertSessionHas('error');

        $this->assertNothingCharged($product, 5);
        $this->assertEquals(10, CartItem::sole()->price);
    }
}
