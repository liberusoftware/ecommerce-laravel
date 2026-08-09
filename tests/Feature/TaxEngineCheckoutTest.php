<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\Order;
use App\Models\Product;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Services\PaymentGateways\StripeGateway;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaxEngineCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private int $taxClassId;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->bindGateway(fn () => ['success' => true, 'transaction_id' => 'ch_1']);
        $this->taxClassId = TaxClass::create(['name' => 'Standard', 'slug' => 'standard', 'is_active' => true])->id;
    }

    private function bindGateway(Closure $result): void
    {
        $this->app->instance(StripeGateway::class, new class($result) implements PaymentGatewayInterface
        {
            public function __construct(private Closure $result) {}

            public function processPayment(float $amount, array $d): array
            {
                return ($this->result)();
            }

            public function processSubscription(string $p, array $d): array
            {
                return ['success' => true];
            }

            public function refundPayment(string $t, float $a): array
            {
                return ['success' => true];
            }
        });
    }

    private function rate(array $overrides): TaxRate
    {
        return TaxRate::create(array_merge([
            'tax_class_id' => $this->taxClassId,
            'name' => 'Rate',
            'country' => 'US',
            'rate' => 20.00,
            'priority' => 1,
            'compound' => false,
            'shipping' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function checkout(Product $product, array $address): void
    {
        $cart = [$product->id => [
            'quantity' => 1,
            'price' => (float) $product->price,
            'is_downloadable' => true,
            'name' => $product->name,
        ]];

        $this->withStoredCart($cart)->post(route('checkout.process'), array_merge([
            'email' => 'buyer@example.com',
            'has_physical_products' => 0,
            'shipping_address' => '1 Test St',
            'payment_method' => 'stripe',
            'stripeToken' => 'tok',
        ], $address));
    }

    public function test_tax_uses_the_orders_country_not_a_hardcoded_us(): void
    {
        $this->rate(['country' => 'GB', 'name' => 'UK VAT', 'rate' => 20.00]);
        $product = Product::factory()->create(['price' => 100, 'inventory_count' => 5, 'is_downloadable' => true, 'tax_status' => true]);

        $this->checkout($product, ['country' => 'GB']);

        $order = Order::first();
        $this->assertNotNull($order, 'Order not created');
        $this->assertEquals(20.00, (float) $order->tax_amount);
        $this->assertNotEmpty($order->tax_lines, 'tax_lines breakdown was not stored');
    }

    public function test_tax_exempt_product_is_not_taxed(): void
    {
        $this->rate(['country' => 'GB', 'rate' => 20.00]);
        $product = Product::factory()->create(['price' => 100, 'inventory_count' => 5, 'is_downloadable' => true, 'tax_status' => false]);

        $this->checkout($product, ['country' => 'GB']);

        $this->assertEquals(0.0, (float) Order::first()->tax_amount);
    }

    public function test_us_state_rate_is_applied(): void
    {
        $this->rate(['country' => 'US', 'state' => 'CA', 'rate' => 7.25, 'name' => 'CA Sales Tax']);
        $product = Product::factory()->create(['price' => 200, 'inventory_count' => 5, 'is_downloadable' => true, 'tax_status' => true]);

        $this->checkout($product, ['country' => 'US', 'state' => 'CA']);

        $this->assertEquals(14.50, (float) Order::first()->tax_amount); // 7.25% of 200
    }
}
