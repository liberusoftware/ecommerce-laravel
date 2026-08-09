<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingQuote;
use App\Models\User;
use App\Services\PaymentGateways\StripeGateway;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Live-rate selection at checkout. The security property under test: the server bills
 * the amount stored on the server-generated ShippingQuote — never a price posted by
 * the client, and never a foreign or expired quote.
 */
class CheckoutLiveRateTest extends TestCase
{
    use RefreshDatabase;

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->gateway = $this->bindGateway(fn () => ['success' => true, 'transaction_id' => 'ch_ok']);
        config([
            'shipping.carrier' => 'easypost',
            'shipping.easypost.api_key' => 'ek_test',
        ]);
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

    private function fakeEasyPost(string $amount = '42.00'): void
    {
        Http::fake([
            'api.easypost.com/*' => Http::response([
                'rates' => [
                    ['id' => 'rate_1', 'carrier' => 'USPS', 'service' => 'Priority', 'rate' => $amount, 'currency' => 'USD', 'delivery_days' => 2],
                ],
            ]),
        ]);
    }

    private function physicalProduct(): Product
    {
        return Product::factory()->create(['price' => 100, 'inventory_count' => 5, 'is_downloadable' => false]);
    }

    private function withCart(Product $product): self
    {
        return $this->withStoredCart([
            $product->id => [
                'quantity' => 1,
                'price' => (float) $product->price,
                'is_downloadable' => false,
                'name' => $product->name,
                'weight' => 1.0,
            ],
        ]);
    }

    private function physicalPayload(array $extra = []): array
    {
        return array_merge([
            'email' => 'buyer@example.com',
            'has_physical_products' => 1,
            'shipping_address' => '123 Test St, CA 90001',
            'country' => 'US',
            'payment_method' => 'stripe',
            'stripeToken' => 'tok_test',
        ], $extra);
    }

    /** Fetch live rates through the endpoint (persists a quote in this test session). */
    private function fetchQuoteId(): int
    {
        $response = $this->postJson(route('checkout.shipping-rates'), ['country' => 'US'])->assertOk();

        return $response->json('rates.0.id');
    }

    public function test_rates_endpoint_persists_session_scoped_quotes(): void
    {
        $this->fakeEasyPost('9.20');
        $this->withCart($this->physicalProduct());

        $response = $this->postJson(route('checkout.shipping-rates'), ['country' => 'US']);

        $response->assertOk()->assertJsonPath('rates.0.carrier', 'USPS')->assertJsonPath('rates.0.amount', 9.2);
        $this->assertDatabaseHas('shipping_quotes', ['carrier' => 'USPS', 'amount' => 9.20]);
    }

    public function test_rates_endpoint_returns_empty_without_a_carrier(): void
    {
        config(['shipping.carrier' => null]);
        $this->withCart($this->physicalProduct());

        $this->postJson(route('checkout.shipping-rates'), ['country' => 'US'])
            ->assertOk()->assertExactJson(['rates' => []]);
    }

    public function test_rates_endpoint_requires_country(): void
    {
        $this->fakeEasyPost();

        $this->withCart($this->physicalProduct())
            ->postJson(route('checkout.shipping-rates'), [])
            ->assertStatus(422);
    }

    public function test_rates_endpoint_requires_a_non_empty_cart(): void
    {
        $this->fakeEasyPost();

        // No cart in session.
        $this->postJson(route('checkout.shipping-rates'), ['country' => 'US'])->assertStatus(422);
    }

    public function test_checkout_bills_the_stored_quote_amount_not_a_client_posted_price(): void
    {
        // Authenticated so the quote (fetched in one request) resolves in the checkout
        // request — the array session driver gives each request a fresh session id, but
        // the user id is stable, and resolveQuote scopes by either.
        $this->actingAs(User::factory()->create());
        $this->fakeEasyPost('42.00');
        $product = $this->physicalProduct();
        $this->withCart($product);
        $quoteId = $this->fetchQuoteId();

        // Attacker posts a near-zero shipping_cost alongside the quote id.
        $this->post(route('checkout.process'), $this->physicalPayload([
            'shipping_quote_id' => $quoteId,
            'shipping_cost' => 0.01,
        ]));

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame(42.00, (float) $order->shipping_cost, 'Server must bill the stored quote, not the posted price');
        $this->assertSame('USPS', $order->shipping_carrier);
        $this->assertSame($quoteId, $order->shipping_quote_id);
        // Shipping was actually charged (subtotal 100 + shipping 42, plus any tax).
        $this->assertGreaterThanOrEqual(142.0, $this->gateway->chargedAmount);
    }

    public function test_checkout_rejects_an_expired_quote(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeEasyPost('42.00');
        $product = $this->physicalProduct();
        $this->withCart($product);
        $quoteId = $this->fetchQuoteId();
        ShippingQuote::find($quoteId)->update(['expires_at' => now()->subMinute()]);

        $response = $this->post(route('checkout.process'), $this->physicalPayload([
            'shipping_quote_id' => $quoteId,
        ]));

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_another_sessions_quote(): void
    {
        $product = $this->physicalProduct();
        $this->withCart($product);
        $foreign = ShippingQuote::create([
            'session_id' => 'some-other-session',
            'carrier' => 'UPS', 'service' => 'Ground', 'amount' => 1.00, 'currency' => 'USD',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->post(route('checkout.process'), $this->physicalPayload([
            'shipping_quote_id' => $foreign->id,
        ]));

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_flat_shipping_method_still_works(): void
    {
        $product = $this->physicalProduct();
        $method = ShippingMethod::create([
            'name' => 'Flat', 'description' => 'x', 'base_rate' => 5.00,
            'weight_rate' => 0.00, 'max_weight' => 100.0, 'estimated_delivery_time' => '3 days', 'is_active' => true,
        ]);
        $this->withCart($product);

        $this->post(route('checkout.process'), $this->physicalPayload([
            'shipping_method_id' => $method->id,
        ]));

        $order = Order::first();
        $this->assertSame(5.00, (float) $order->shipping_cost);
        $this->assertNull($order->shipping_quote_id);
    }
}
