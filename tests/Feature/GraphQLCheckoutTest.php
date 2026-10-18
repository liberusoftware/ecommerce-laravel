<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Jobs\DispatchDropshippingOrder;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingQuote;
use App\Models\Store;
use App\Models\User;
use App\Services\PaymentGateways\StripeGateway;
use Closure;
use Database\Seeders\EuVatRatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GraphQL headless checkout mutation. Money path: reserve stock before charging, bill
 * the STORED shipping quote (never a client amount, #775), clear the cart on success.
 */
class GraphQLCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function gql(string $query, array $variables = []): array
    {
        return $this->postJson('/api/graphql', ['query' => $query, 'variables' => $variables])->json();
    }

    private function product(int $stock = 5, float $price = 100): Product
    {
        return Product::factory()->create(['price' => $price, 'inventory_count' => $stock, 'is_downloadable' => false]);
    }

    private function cartItem(User $user, Product $product, int $qty): void
    {
        CartItem::create([
            'user_id' => $user->id, 'product_id' => $product->id,
            'quantity' => $qty, 'price' => $product->price, 'session_id' => 'api',
        ]);
    }

    private const MUTATION = 'mutation($input: CheckoutInput!) { checkout(input: $input) { id status totalAmount shippingCost shippingCarrier } }';

    private function checkout(array $input): array
    {
        return $this->gql(self::MUTATION, ['input' => array_merge(['country' => 'US', 'paymentMethod' => 'stripe', 'stripeToken' => 'tok'], $input)]);
    }

    public function test_checkout_requires_authentication(): void
    {
        $data = $this->checkout([]);

        $this->assertSame('Unauthenticated.', $data['errors'][0]['message']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_places_a_paid_order_reserves_stock_and_clears_the_cart(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $product = $this->product(stock: 5, price: 100);
        $this->cartItem($user, $product, 2);

        $data = $this->checkout([]);

        $this->assertSame('paid', $data['data']['checkout']['status']);
        $order = Order::first();
        $this->assertSame($user->id, $order->user_id);
        $this->assertEqualsWithDelta(200.0, (float) $order->total_amount, 0.001);
        $this->assertSame(3, $product->fresh()->inventory_count);   // 5 - 2 reserved
        $this->assertDatabaseCount('cart_items', 0);                 // cart cleared
        $this->assertEqualsWithDelta(200.0, $this->gateway->chargedAmount, 0.001);
    }

    public function test_checkout_bills_the_stored_shipping_quote(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $product = $this->product();
        $this->cartItem($user, $product, 1);
        $quote = ShippingQuote::create([
            'user_id' => $user->id, 'session_id' => 'web',
            'carrier' => 'USPS', 'service' => 'Priority', 'amount' => 15.00, 'currency' => 'USD',
            'expires_at' => now()->addHour(),
        ]);

        $data = $this->checkout(['shippingQuoteId' => $quote->id]);

        $this->assertEqualsWithDelta(15.0, $data['data']['checkout']['shippingCost'], 0.001);
        $this->assertSame('USPS', $data['data']['checkout']['shippingCarrier']);
        $this->assertSame($quote->id, Order::first()->shipping_quote_id);
    }

    public function test_checkout_rejects_a_foreign_or_expired_quote(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->cartItem($user, $this->product(), 1);
        // Quote belongs to a different user.
        $foreign = ShippingQuote::create([
            'user_id' => User::factory()->create()->id, 'session_id' => 'x',
            'carrier' => 'UPS', 'service' => 'Ground', 'amount' => 99.00, 'currency' => 'USD',
            'expires_at' => now()->addHour(),
        ]);

        $data = $this->checkout(['shippingQuoteId' => $foreign->id]);

        $this->assertStringContainsString('no longer valid', $data['errors'][0]['message']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_an_empty_cart(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $data = $this->checkout([]);

        $this->assertStringContainsString('cart is empty', $data['errors'][0]['message']);
    }

    public function test_checkout_rolls_back_when_stock_is_insufficient(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $product = $this->product(stock: 5);
        $this->cartItem($user, $product, 3);
        // Stock drops below the cart quantity after the item was added.
        $product->update(['inventory_count' => 1]);

        $data = $this->checkout([]);

        $this->assertStringContainsString('no longer available', $data['errors'][0]['message']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $product->fresh()->inventory_count); // unchanged (rolled back)
    }

    public function test_payment_failure_releases_stock_and_does_not_clear_the_cart(): void
    {
        $this->bindGateway(fn () => ['success' => false, 'error' => 'declined']);
        Sanctum::actingAs($user = User::factory()->create());
        $product = $this->product(stock: 5);
        $this->cartItem($user, $product, 2);

        $data = $this->checkout([]);

        $this->assertStringContainsString('Payment failed', $data['errors'][0]['message']);
        $this->assertSame(5, $product->fresh()->inventory_count);  // reserved then released
        $this->assertSame('failed', Order::first()->status);
        $this->assertDatabaseCount('cart_items', 1);               // cart kept for retry
    }

    public function test_checkout_applies_a_coupon_revalidated_against_the_subtotal(): void
    {
        Coupon::create(['code' => 'TENOFF', 'type' => 'percentage', 'value' => 10]);
        Sanctum::actingAs($user = User::factory()->create());
        $this->cartItem($user, $this->product(price: 100), 1);

        $data = $this->checkout(['couponCode' => 'TENOFF']);

        $this->assertSame('paid', $data['data']['checkout']['status']);
        $order = Order::first();
        $this->assertEqualsWithDelta(10.0, (float) $order->discount_amount, 0.001);
        $this->assertEqualsWithDelta(90.0, (float) $order->total_amount, 0.001);   // 100 - 10
        $this->assertEqualsWithDelta(90.0, $this->gateway->chargedAmount, 0.001);
    }

    public function test_checkout_grants_download_tokens_for_downloadable_lines(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $product = Product::factory()->create(['price' => 50, 'inventory_count' => 5, 'is_downloadable' => true]);
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 50, 'session_id' => 'api']);

        $this->checkout([]);

        $item = Order::first()->items()->first();
        $this->assertNotNull($item->download_link);
        $this->assertTrue($item->download_expires_at->isFuture());
    }

    public function test_checkout_queues_a_dropship_order_with_recipient_details(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->cartItem($user, $this->product(), 1);

        $this->checkout([
            'dropship' => true, 'supplierId' => 'dropxl',
            'recipientName' => 'Grandma', 'recipientEmail' => 'gran@example.com',
        ]);

        $order = Order::first();
        $this->assertTrue((bool) $order->is_dropshipped);
        $this->assertSame('supplier_queued', $order->status);
        $this->assertSame('Grandma', $order->recipient_name);
        Queue::assertPushed(DispatchDropshippingOrder::class);
    }

    public function test_dropship_without_recipient_is_rejected(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->cartItem($user, $this->product(), 1);

        $data = $this->checkout(['dropship' => true]);

        $this->assertStringContainsString('recipient name and email', $data['errors'][0]['message']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_zero_rates_a_valid_eu_b2b_order_via_reverse_charge(): void
    {
        Store::query()->update(['country_code' => 'DE']);   // EU-established store
        $this->seed(EuVatRatesSeeder::class);          // an FR order would otherwise be taxed 20%
        Http::fake(['ec.europa.eu/*' => Http::response(['isValid' => true])]);
        Sanctum::actingAs($user = User::factory()->create());
        $this->cartItem($user, $this->product(price: 100), 1);

        $this->checkout(['country' => 'FR', 'vatNumber' => 'FR 1234 5678']);

        $order = Order::first();
        $this->assertTrue((bool) $order->reverse_charge);
        $this->assertEqualsWithDelta(0.0, (float) $order->tax_amount, 0.001);
        $this->assertSame('FR12345678', $order->vat_number);   // normalised
    }
}
