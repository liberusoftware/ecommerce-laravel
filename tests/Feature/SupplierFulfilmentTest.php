<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Jobs\DispatchDropshippingOrder;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\PaymentGateways\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/** A product names the supplier that fulfils it; the order goes there with the variant's own ids. */
class SupplierFulfilmentTest extends TestCase
{
    use BuildsVariants;
    use RefreshDatabase;

    private Product $tee;

    private ShippingMethod $courier;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

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

        $made = ['track_inventory' => false];
        $this->tee = $this->variableProduct(['Size' => ['M', 'L']], [
            $this->row(['Size' => 'M'], 25, 0, $made + ['sku' => 'TEE-M']),
            $this->row(['Size' => 'L'], 25, 0, $made + ['sku' => 'TEE-L']),
        ], ['name' => 'Logo Tee', 'supplier_id' => 'printful', 'supplier_product_id' => '900']);
        // An importer maps the supplier's ids after the variants exist.
        $this->variantOf($this->tee, 'M')->update(['supplier_variant_id' => '4001']);
        $this->courier = ShippingMethod::create(['name' => 'Standard', 'base_rate' => 4, 'weight_rate' => 0, 'max_weight' => 100]);
    }

    private function addToCart(string $size, int $quantity = 1): void
    {
        $this->post(route('cart.add', $this->tee), ['variant_id' => $this->variantOf($this->tee, $size)->id, 'quantity' => $quantity])->assertSessionHasNoErrors();
    }

    private function checkout(array $overrides = [])
    {
        return $this->post(route('checkout.process'), $overrides + [
            'email' => 'buyer@example.com',
            'has_physical_products' => 1,
            'shipping_name' => 'Ada Lovelace',
            'shipping_address' => '1 High Street',
            'city' => 'Leeds',
            'state' => 'West Yorkshire',
            'postal_code' => 'LS1 1AA',
            'country' => 'GB',
            'shipping_method_id' => $this->courier->id,
            'payment_method' => 'stripe',
            'stripeToken' => 'tok_test',
        ]);
    }

    public function test_the_products_supplier_gets_the_order_whatever_the_request_names(): void
    {
        Queue::fake();
        $this->addToCart('M');

        $this->checkout(['supplier_id' => 'dropxl']);

        $order = Order::sole();
        $this->assertSame(['printful', Order::STATUS_SUPPLIER_QUEUED, true], [$order->supplier_id, $order->status, (bool) $order->is_dropshipped]);
        $this->assertSame(['Leeds', 'West Yorkshire', 'LS1 1AA'], [$order->shipping_city, $order->shipping_state, $order->shipping_postal_code]);
        Queue::assertPushed(DispatchDropshippingOrder::class, fn ($job) => $job->supplierId === 'printful');
    }

    public function test_a_supplier_needs_the_city_and_postcode(): void
    {
        $this->addToCart('M');

        $this->checkout(['shipping_name' => '', 'city' => '', 'postal_code' => ''])->assertSessionHasErrors(['shipping_name', 'city', 'postal_code']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_shopper_cannot_route_an_order_to_an_unknown_supplier(): void
    {
        $plain = Product::factory()->create(['price' => 10, 'inventory_count' => 5]);
        $this->post(route('cart.add', $plain), ['quantity' => 1]);

        $this->checkout(['dropship' => 'on', 'recipient_name' => 'Gran', 'recipient_email' => 'gran@example.com', 'supplier_id' => 'attacker'])
            ->assertSessionHasErrors('supplier_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_on_the_default_sync_queue_the_order_reaches_printful_with_each_variants_id(): void
    {
        config(['dropshipping.suppliers.printful.auth.key' => 'Bearer pf_test']);
        Http::fake(['api.printful.com/*' => Http::response(['result' => ['id' => 777]], 200)]);
        $this->addToCart('M', 2);
        $this->addToCart('L');

        $this->checkout(['gift_message' => 'Happy birthday']);

        Http::assertSent(function (Request $request) {
            $items = collect($request['items'])->keyBy(fn ($i) => $i['sync_variant_id'] ?? $i['external_variant_id']);

            return $request->url() === 'https://api.printful.com/orders?confirm=1&update_existing=1'
                && $request->hasHeader('Authorization', 'Bearer pf_test')
                && $items[4001]['quantity'] === 2
                && ! isset($items[4001]['external_variant_id'])
                && $items['TEE-L']['quantity'] === 1
                && $request['recipient']['name'] === 'Ada Lovelace'
                && $request['recipient']['city'] === 'Leeds'
                && $request['recipient']['zip'] === 'LS1 1AA'
                && $request['recipient']['country_code'] === 'GB'
                && $request['recipient']['address1'] === '1 High Street'
                && $request['gift']['message'] === 'Happy birthday';
        });

        $order = Order::sole();
        $this->assertSame([Order::STATUS_PROCESSING, '777'], [$order->status, (string) $order->supplier_order_reference]);
    }

    public function test_a_supplier_is_sent_only_its_own_lines(): void
    {
        Http::fake(['api.printful.com/*' => Http::response(['result' => ['id' => 1]], 200)]);
        $mug = Product::factory()->create(['name' => 'Own-stock mug', 'price' => 8, 'inventory_count' => 5, 'sku' => 'MUG']);
        $this->addToCart('M');
        $this->post(route('cart.add', $mug), ['quantity' => 1]);

        $this->checkout();

        Http::assertSent(fn (Request $request) => array_column($request['items'], 'sync_variant_id') === [4001] && count($request['items']) === 1);
        $this->assertSame('printful', Order::sole()->supplier_id);
    }

    public function test_the_headless_checkout_follows_the_same_rules(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $variant = $this->variantOf($this->tee, 'M');
        CartItem::create(['user_id' => $user->id, 'product_id' => $this->tee->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'price' => 25, 'session_id' => 'api']);
        $mutation = 'mutation($input: CheckoutInput!) { checkout(input: $input) { id status } }';
        $input = ['country' => 'GB', 'paymentMethod' => 'stripe', 'stripeToken' => 'tok', 'supplierId' => 'dropxl'];

        $refused = $this->postJson('/api/graphql', ['query' => $mutation, 'variables' => ['input' => $input]])->json();
        $this->assertStringContainsString('street address, city and postal code', $refused['errors'][0]['message']);

        $this->postJson('/api/graphql', ['query' => $mutation, 'variables' => ['input' => $input + ['shippingAddress' => '1 High Street', 'city' => 'Leeds', 'postalCode' => 'LS1 1AA']]]);

        $order = Order::sole();
        $this->assertSame(['printful', '1 High Street', 'LS1 1AA'], [$order->supplier_id, $order->shipping_address, $order->shipping_postal_code]);
        Queue::assertPushed(DispatchDropshippingOrder::class, fn ($job) => $job->supplierId === 'printful');
    }
}
