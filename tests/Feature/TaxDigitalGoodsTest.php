<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\Order;
use App\Models\Product;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Services\PaymentGateways\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * VAT-on-digital: a digital-only order still needs the buyer's country, and its
 * downloadable goods are taxed by it (they were previously untaxed because checkout
 * only collected a country for physical orders).
 */
class TaxDigitalGoodsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $spy = new class implements PaymentGatewayInterface
        {
            public function processPayment(float $amount, array $paymentDetails): array
            {
                return ['success' => true, 'transaction_id' => 'ch_1'];
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
        $this->app->instance(StripeGateway::class, $spy);
    }

    private function digitalCart(Product $product): array
    {
        return [$product->id => ['quantity' => 1, 'price' => (float) $product->price, 'is_downloadable' => true, 'name' => $product->name]];
    }

    public function test_digital_only_order_is_taxed_by_the_buyers_country(): void
    {
        $class = TaxClass::create(['name' => 'Standard', 'slug' => 'standard', 'is_active' => true]);
        TaxRate::create(['tax_class_id' => $class->id, 'country' => 'US', 'rate' => 10, 'name' => 'US', 'is_active' => true]);
        $product = Product::factory()->create(['price' => 200, 'inventory_count' => 5, 'is_downloadable' => true, 'tax_class_id' => $class->id]);

        $this->withStoredCart($this->digitalCart($product))->post(route('checkout.process'), [
            'email' => 'buyer@example.com',
            'has_physical_products' => 0,
            'country' => 'US',
            'payment_method' => 'stripe',
            'stripeToken' => 'tok_test',
        ]);

        // 10% of $200 = $20 tax on a digital-only order.
        $this->assertEquals(20.0, (float) Order::first()->tax_amount);
    }

    public function test_checkout_requires_a_country_even_for_a_digital_order(): void
    {
        $product = Product::factory()->create(['inventory_count' => 5, 'is_downloadable' => true]);

        $this->withStoredCart($this->digitalCart($product))->post(route('checkout.process'), [
            'email' => 'buyer@example.com',
            'has_physical_products' => 0,
            'payment_method' => 'stripe',
            'stripeToken' => 'tok_test',
        ])->assertSessionHasErrors('country');

        $this->assertNull(Order::first());
    }
}
