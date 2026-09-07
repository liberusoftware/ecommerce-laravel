<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentGateways\StripeGateway;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckoutInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a spy payment gateway that records how much inventory existed at the
     * moment the payment was charged. Returns the spy so tests can inspect it.
     */
    private function bindGateway(Closure $result): object
    {
        $spy = new class($result) implements PaymentGatewayInterface
        {
            public ?int $inventoryAtCharge = null;

            public function __construct(private Closure $result) {}

            public function processPayment(float $amount, array $paymentDetails): array
            {
                // Total stock left across all products at the instant of charging.
                $this->inventoryAtCharge = (int) Product::sum('inventory_count');

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

    public function test_successful_checkout_marks_order_paid_and_decrements_inventory(): void
    {
        Notification::fake();
        $this->bindGateway(fn () => ['success' => true, 'transaction_id' => 'ch_persist']);

        $product = Product::factory()->create(['inventory_count' => 5, 'is_downloadable' => true]);

        $this->withStoredCart($this->cartFor($product, 1))
            ->post(route('checkout.process'), $this->payload());

        $order = Order::first();
        $this->assertNotNull($order, 'Order was not created');
        $this->assertSame('paid', $order->status);
        $this->assertSame(4, $product->fresh()->inventory_count);
        // The gateway charge id must be persisted so a later refund has something to void.
        $this->assertSame('ch_persist', $order->transaction_id);
    }

    public function test_inventory_is_reserved_before_payment_is_charged(): void
    {
        Notification::fake();
        $spy = $this->bindGateway(fn () => ['success' => true]);

        $product = Product::factory()->create(['inventory_count' => 5, 'is_downloadable' => true]);

        $this->withStoredCart($this->cartFor($product, 2))
            ->post(route('checkout.process'), $this->payload());

        // Stock must already be reserved (decremented) when payment runs; otherwise a
        // concurrent buyer can be charged for stock that is already gone (oversell).
        $this->assertSame(3, $spy->inventoryAtCharge, 'Payment was charged before inventory was reserved');
    }

    public function test_failed_payment_does_not_lose_inventory_and_marks_order_failed(): void
    {
        Notification::fake();
        $this->bindGateway(fn () => ['success' => false, 'error' => 'card declined']);

        $product = Product::factory()->create(['inventory_count' => 5, 'is_downloadable' => true]);

        $this->withStoredCart($this->cartFor($product, 2))
            ->post(route('checkout.process'), $this->payload());

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame('failed', $order->status);
        // Reserved stock must be released back when the charge fails.
        $this->assertSame(5, $product->fresh()->inventory_count, 'Inventory was lost on a failed payment');
    }

    public function test_authenticated_checkout_links_the_order_to_the_user(): void
    {
        Notification::fake();
        $this->bindGateway(fn () => ['success' => true, 'transaction_id' => 'ch_1']);

        $user = User::factory()->create();
        $product = Product::factory()->create(['inventory_count' => 5, 'is_downloadable' => true]);

        $this->actingAs($user)
            ->withStoredCart($this->cartFor($product, 1))
            ->post(route('checkout.process'), $this->payload());

        $this->assertSame($user->id, Order::first()->user_id);
    }

    public function test_guest_checkout_leaves_the_order_user_id_null(): void
    {
        Notification::fake();
        $this->bindGateway(fn () => ['success' => true, 'transaction_id' => 'ch_1']);

        $product = Product::factory()->create(['inventory_count' => 5, 'is_downloadable' => true]);

        $this->withStoredCart($this->cartFor($product, 1))
            ->post(route('checkout.process'), $this->payload());

        $this->assertNull(Order::first()->user_id);
    }
}
