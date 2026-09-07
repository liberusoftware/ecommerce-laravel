<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Services\PaymentGateways\StripeGateway;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The coupon discount is cached in the session as a fixed dollar amount at
 * apply-time. Checkout must RE-COMPUTE and RE-VALIDATE it against the live cart,
 * never trust the cached figure — otherwise a shopper can apply a coupon on a
 * large cart, shrink the cart, and drive the total negative (a free order that
 * skips payment) or simply under-charge.
 */
class CheckoutCouponRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->gateway = $this->bindGateway(fn () => ['success' => true, 'transaction_id' => 'ch_ok']);
    }

    private function bindGateway(Closure $result): object
    {
        $spy = new class($result) implements PaymentGatewayInterface
        {
            public ?float $chargedAmount = null;

            public function __construct(private Closure $result) {}

            public function processPayment(float $amount, array $paymentDetails): array
            {
                $this->chargedAmount = $amount;

                return ($this->result)();
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

        return $spy;
    }

    private function cartFor(Product $product, int $qty): array
    {
        return [
            $product->id => [
                'quantity' => $qty,
                'price' => (float) $product->price,
                'is_downloadable' => true,
                'name' => $product->name,
            ],
        ];
    }

    private function payload(): array
    {
        return [
            'email' => 'buyer@example.com',
            'has_physical_products' => 0,
            'shipping_address' => '123 Test St, CA 90001',
            'country' => 'US',
            'payment_method' => 'stripe',
            'stripeToken' => 'tok_test',
        ];
    }

    private function checkout(Product $product, int $qty, array $coupon): void
    {
        $this->withStoredCart($this->cartFor($product, $qty))
            ->withSession(['coupon' => $coupon])
            ->post(route('checkout.process'), $this->payload());
    }

    public function test_stale_discount_cannot_drive_the_total_negative_into_a_free_order(): void
    {
        // 50% coupon applied when the cart was $100 → session cached discount = $50.
        Coupon::create(['code' => 'HALF', 'type' => 'percentage', 'value' => 50]);
        // Cart has since shrunk to a $40 subtotal.
        $product = Product::factory()->create(['price' => 40, 'inventory_count' => 5, 'is_downloadable' => true]);

        $this->checkout($product, 1, ['code' => 'HALF', 'discount' => 50]);

        $order = Order::first();
        // Recomputed 50% of $40 = $20 discount, $20 total — and the customer WAS charged.
        $this->assertSame(20.0, (float) $order->discount_amount);
        $this->assertSame(20.0, (float) $order->total_amount);
        $this->assertSame(20.0, $this->gateway->chargedAmount, 'Payment was skipped — the order went through free');
    }

    public function test_stale_discount_is_recomputed_down_when_the_cart_shrinks(): void
    {
        Coupon::create(['code' => 'TENOFF', 'type' => 'percentage', 'value' => 10]);
        $product = Product::factory()->create(['price' => 60, 'inventory_count' => 5, 'is_downloadable' => true]);

        // Cached $50 from a bigger cart; live cart is $60 so the real discount is $6.
        $this->checkout($product, 1, ['code' => 'TENOFF', 'discount' => 50]);

        $order = Order::first();
        $this->assertSame(6.0, (float) $order->discount_amount);
        $this->assertSame(54.0, (float) $order->total_amount);
        $this->assertSame(54.0, $this->gateway->chargedAmount);
    }

    public function test_expired_coupon_is_dropped_at_checkout(): void
    {
        Coupon::create([
            'code' => 'EXPIRED', 'type' => 'percentage', 'value' => 50,
            'valid_until' => now()->subDay(),
        ]);
        $product = Product::factory()->create(['price' => 50, 'inventory_count' => 5, 'is_downloadable' => true]);

        $this->checkout($product, 1, ['code' => 'EXPIRED', 'discount' => 25]);

        $order = Order::first();
        $this->assertSame(0.0, (float) $order->discount_amount);
        $this->assertSame(50.0, (float) $order->total_amount);
        $this->assertNull($order->coupon_code);
    }

    public function test_coupon_below_min_spend_is_dropped_when_the_cart_shrinks(): void
    {
        Coupon::create([
            'code' => 'MIN75', 'type' => 'fixed', 'value' => 10, 'min_purchase_amount' => 75,
        ]);
        $product = Product::factory()->create(['price' => 40, 'inventory_count' => 5, 'is_downloadable' => true]);

        // Applied when the cart met the $75 min; now it's $40 so the coupon no longer qualifies.
        $this->checkout($product, 1, ['code' => 'MIN75', 'discount' => 10]);

        $order = Order::first();
        $this->assertSame(0.0, (float) $order->discount_amount);
        $this->assertSame(40.0, (float) $order->total_amount);
    }

    public function test_coupon_past_its_usage_limit_is_dropped_at_checkout(): void
    {
        Coupon::create(['code' => 'ONCE', 'type' => 'percentage', 'value' => 50, 'max_uses' => 1]);
        $product = Product::factory()->create(['price' => 50, 'inventory_count' => 5, 'is_downloadable' => true]);
        // The one allowed use is already spent by a prior order.
        Order::create(['customer_email' => 'x@x.com', 'total_amount' => 1, 'status' => 'paid', 'coupon_code' => 'ONCE']);

        $this->checkout($product, 1, ['code' => 'ONCE', 'discount' => 25]);

        $order = Order::where('coupon_code', 'ONCE')->where('total_amount', '!=', 1)->first();
        $this->assertNull($order, 'A coupon past max_uses must not be honored again');
        $fresh = Order::where('customer_email', 'buyer@example.com')->first();
        $this->assertSame(0.0, (float) $fresh->discount_amount);
        $this->assertSame(50.0, (float) $fresh->total_amount);
    }

    public function test_a_still_valid_coupon_is_applied_at_the_recomputed_amount(): void
    {
        Coupon::create(['code' => 'GOOD', 'type' => 'percentage', 'value' => 10]);
        $product = Product::factory()->create(['price' => 100, 'inventory_count' => 5, 'is_downloadable' => true]);

        // Cached figure is stale ($50) but the coupon is valid — recompute to $10.
        $this->checkout($product, 1, ['code' => 'GOOD', 'discount' => 50]);

        $order = Order::first();
        $this->assertSame(10.0, (float) $order->discount_amount);
        $this->assertSame(90.0, (float) $order->total_amount);
        $this->assertSame('GOOD', $order->coupon_code);
        $this->assertSame(90.0, $this->gateway->chargedAmount);
    }
}
