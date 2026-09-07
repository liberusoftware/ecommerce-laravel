<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentGateways\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GraphQL shippingRates mutation: fetches live carrier rates for the user's persistent
 * cart and persists each as a user-scoped ShippingQuote, so the returned id can be
 * passed to checkout (where the STORED amount is billed — #775 quote-trust end to end).
 */
class GraphQLShippingRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shipping.carrier' => 'easypost', 'shipping.easypost.api_key' => 'ek_test']);
    }

    private function fakeEasyPost(string $amount = '12.50'): void
    {
        Http::fake(['api.easypost.com/*' => Http::response([
            'rates' => [['id' => 'rate_1', 'carrier' => 'USPS', 'service' => 'Priority', 'rate' => $amount, 'currency' => 'USD', 'delivery_days' => 3]],
        ])]);
    }

    private function gql(string $query, array $variables = []): array
    {
        return $this->postJson('/api/graphql', ['query' => $query, 'variables' => $variables])->json();
    }

    private function cartItem(User $user): Product
    {
        $product = Product::factory()->create(['price' => 100, 'inventory_count' => 5, 'is_downloadable' => false]);
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 100]);

        return $product;
    }

    private const RATES = 'mutation($i: ShippingRatesInput!) { shippingRates(input: $i) { id carrier service amount deliveryDays } }';

    public function test_shipping_rates_requires_authentication(): void
    {
        $this->fakeEasyPost();

        $data = $this->gql(self::RATES, ['i' => ['country' => 'US']]);

        $this->assertSame('Unauthenticated.', $data['errors'][0]['message']);
    }

    public function test_returns_and_persists_user_scoped_quotes(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->cartItem($user);
        $this->fakeEasyPost('12.50');

        $data = $this->gql(self::RATES, ['i' => ['country' => 'US', 'postalCode' => '10001']]);

        $this->assertSame('USPS', $data['data']['shippingRates'][0]['carrier']);
        $this->assertEqualsWithDelta(12.5, $data['data']['shippingRates'][0]['amount'], 0.001);
        $this->assertDatabaseHas('shipping_quotes', ['user_id' => $user->id, 'carrier' => 'USPS', 'amount' => 12.50]);
    }

    public function test_empty_cart_returns_no_rates(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->fakeEasyPost();

        $data = $this->gql(self::RATES, ['i' => ['country' => 'US']]);

        $this->assertSame([], $data['data']['shippingRates']);
    }

    public function test_returned_rate_id_bills_its_stored_amount_at_checkout(): void
    {
        $this->app->instance(StripeGateway::class, new class implements PaymentGatewayInterface
        {
            public function processPayment(float $amount, array $paymentDetails): array
            {
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
        });

        Sanctum::actingAs($user = User::factory()->create());
        $this->cartItem($user);
        $this->fakeEasyPost('12.50');

        // 1) Fetch rates → get a persisted quote id.
        $rates = $this->gql(self::RATES, ['i' => ['country' => 'US']]);
        $quoteId = $rates['data']['shippingRates'][0]['id'];

        // 2) Check out with that id → the stored 12.50 is billed.
        $checkout = $this->gql(
            'mutation($i: CheckoutInput!) { checkout(input: $i) { shippingCost shippingCarrier } }',
            ['i' => ['country' => 'US', 'paymentMethod' => 'stripe', 'stripeToken' => 'tok', 'shippingQuoteId' => $quoteId]],
        );

        $this->assertEqualsWithDelta(12.5, $checkout['data']['checkout']['shippingCost'], 0.001);
        $this->assertSame('USPS', $checkout['data']['checkout']['shippingCarrier']);
        $this->assertEqualsWithDelta(12.5, (float) Order::first()->shipping_cost, 0.001);
    }
}
