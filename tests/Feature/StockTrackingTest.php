<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Livewire\ShoppingCart;
use App\Models\CartItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\Facebook\CatalogItemMapper;
use App\Services\PaymentGateways\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A product either has its stock counted or does not. A made-to-order or
 * digital product is untracked: it sells in any quantity and nothing about it
 * is reserved, released, restocked or logged. Tracked products are unchanged.
 */
class StockTrackingTest extends TestCase
{
    use RefreshDatabase;

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->gateway = new class implements PaymentGatewayInterface
        {
            public bool $succeed = true;

            public ?float $chargedAmount = null;

            public function processPayment(float $amount, array $paymentDetails): array
            {
                $this->chargedAmount = $amount;

                return $this->succeed ? ['success' => true, 'transaction_id' => 'ch_ok'] : ['success' => false, 'error' => 'declined'];
            }

            public function processSubscription(string $planId, array $subscriptionDetails): array
            {
                return ['success' => true];
            }

            public function refundPayment(string $transactionId, float $amount): array
            {
                return ['success' => true, 'refund_id' => 're_1'];
            }
        };
        $this->app->instance(StripeGateway::class, $this->gateway);
    }

    private function untracked(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + ['name' => 'Cod', 'price' => 7.50, 'track_inventory' => false, 'inventory_count' => 0, 'is_downloadable' => true]);
    }

    private function webCheckout()
    {
        return $this->post(route('checkout.process'), [
            'email' => 'buyer@example.com', 'has_physical_products' => 0, 'country' => 'US',
            'payment_method' => 'stripe', 'stripeToken' => 'tok_test',
        ]);
    }

    private function gql(string $query, array $variables = []): array
    {
        return $this->postJson('/api/graphql', ['query' => $query, 'variables' => $variables])->json();
    }

    public function test_tracking_is_the_default(): void
    {
        $this->assertTrue(Product::factory()->create()->fresh()->tracksInventory());
    }

    public function test_every_door_sells_an_untracked_product_in_any_quantity(): void
    {
        $cod = $this->untracked();

        $this->post(route('cart.add', $cod), ['quantity' => 40])->assertSessionHas('success');
        $line = CartItem::sole();
        $this->put(route('cart.update', $line->id), ['quantity' => 60])->assertSessionHas('success');
        Livewire::test(ShoppingCart::class)->call('addToCart', productId: $cod->id, quantity: 5);
        $this->assertSame(65, $line->fresh()->quantity);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/cart', ['product_id' => $cod->id, 'quantity' => 30])->assertCreated();
        $this->assertArrayNotHasKey('errors', $this->gql('mutation($p: Int!) { addToCart(productId: $p, quantity: 30) { id } }', ['p' => $cod->id]));

        $this->assertSame(125, (int) CartItem::sum('quantity'));
    }

    public function test_web_checkout_reserves_and_logs_nothing_for_an_untracked_product(): void
    {
        $cod = $this->untracked();
        $tracked = Product::factory()->create(['price' => 1.10, 'inventory_count' => 4, 'is_downloadable' => true]);
        $this->post(route('cart.add', $cod), ['quantity' => 3]);
        $this->post(route('cart.add', $tracked), ['quantity' => 2]);

        $this->webCheckout()->assertSessionHas('success');

        $this->assertEqualsWithDelta(24.70, $this->gateway->chargedAmount, 0.001);
        $this->assertSame(0, $cod->fresh()->inventory_count);
        $this->assertSame(2, $tracked->fresh()->inventory_count);
        $this->assertSame([$tracked->id], InventoryLog::pluck('product_id')->all());
        $this->assertCount(2, Order::sole()->items);
    }

    public function test_graphql_checkout_sells_an_untracked_product(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $cod = $this->untracked();
        $this->gql('mutation($p: Int!) { addToCart(productId: $p, quantity: 12) { id } }', ['p' => $cod->id]);

        $data = $this->gql('mutation($i: CheckoutInput!) { checkout(input: $i) { totalAmount } }', ['i' => ['country' => 'US', 'paymentMethod' => 'stripe', 'stripeToken' => 'tok']]);

        $this->assertEqualsWithDelta(90.0, $data['data']['checkout']['totalAmount'], 0.001);
        $this->assertSame(0, $cod->fresh()->inventory_count);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_a_failed_payment_releases_nothing_for_an_untracked_product(): void
    {
        $this->gateway->succeed = false;
        $cod = $this->untracked(['inventory_count' => 5]);
        $this->post(route('cart.add', $cod), ['quantity' => 3]);

        $this->webCheckout()->assertSessionHas('error');

        $this->assertSame(Order::STATUS_FAILED, Order::sole()->status);
        $this->assertSame(5, $cod->fresh()->inventory_count);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_a_tracked_product_still_refuses_more_than_its_stock(): void
    {
        $tracked = Product::factory()->create(['inventory_count' => 2, 'is_downloadable' => true]);

        $this->post(route('cart.add', $tracked), ['quantity' => 3])->assertSessionHas('error', 'Not enough inventory available.');
        $page = $this->get(route('products.show', $tracked))->assertSee('</span> available', false);
        $this->assertMatchesRegularExpression('/name="quantity"[^>]*max="2"/s', $page->getContent());
    }

    public function test_an_untracked_product_reads_as_in_stock_everywhere(): void
    {
        $cod = $this->untracked();

        $page = $this->get(route('products.show', $cod))->assertOk();
        $page->assertSee('In stock')->assertDontSee('</span> available', false)->assertDontSee('Sold out')->assertSee('schema.org\/InStock', false);
        $this->assertDoesNotMatchRegularExpression('/name="quantity"[^>]*max=/', $page->getContent());

        $product = $this->gql('query($id: Int) { product(id: $id) { inventoryCount inStock } }', ['id' => $cod->id])['data']['product'];
        $this->assertSame(['inventoryCount' => null, 'inStock' => true], $product);

        $this->assertSame('in stock', CatalogItemMapper::forProduct($cod, 'r1')['data']['availability']);
        $this->assertFalse($cod->isLowStock());
    }

    public function test_untracked_products_are_never_low_or_out_of_stock_in_reports(): void
    {
        $this->untracked(['low_stock_threshold' => 5]);
        Product::factory()->create(['inventory_count' => 0]);
        Product::factory()->create(['inventory_count' => 2, 'low_stock_threshold' => 5]);

        $insights = app(AnalyticsService::class)->getInventoryInsights();

        $this->assertSame(['out_of_stock' => 1, 'low_stock' => 1, 'in_stock' => 1], $insights['stock_status']);
        $this->assertCount(2, $insights['low_stock_products']);
        $this->artisan('inventory:check-low-stock')->expectsOutputToContain('Low stock notification sent')->assertSuccessful();
    }

    private function refundOf(Product $product, int $quantity): Refund
    {
        $order = Order::create([
            'customer_email' => 'buyer@example.com', 'payment_method' => 'stripe', 'transaction_id' => 'ch_test',
            'total_amount' => 100, 'status' => Order::STATUS_PAID,
        ]);
        $line = $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity, 'price' => 50]);
        $refund = Refund::create(['order_id' => $order->id, 'amount' => 100, 'reason' => 'x', 'status' => 'pending', 'restock_items' => true]);
        $refund->items()->create(['order_item_id' => $line->id, 'quantity' => $quantity, 'amount' => 100, 'restock' => true]);

        return $refund;
    }

    public function test_a_refund_restocks_a_tracked_product_through_the_log(): void
    {
        $product = Product::factory()->create(['inventory_count' => 3]);

        $this->assertTrue($this->refundOf($product, 2)->process());

        $this->assertSame(5, $product->fresh()->inventory_count);
        $this->assertDatabaseHas('inventory_logs', ['product_id' => $product->id, 'quantity_change' => 2, 'reason' => 'refund']);
    }

    public function test_a_refund_restocks_nothing_for_an_untracked_product(): void
    {
        $cod = $this->untracked(['inventory_count' => 0]);

        $this->assertTrue($this->refundOf($cod, 2)->process());

        $this->assertSame(0, $cod->fresh()->inventory_count);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_a_refund_of_a_withdrawn_or_deleted_product_completes(): void
    {
        $withdrawn = Product::factory()->create(['inventory_count' => 1]);
        $withdrawnRefund = $this->refundOf($withdrawn, 1);
        $withdrawn->delete();

        $this->assertTrue($withdrawnRefund->process());
        $this->assertSame(2, Product::withTrashed()->find($withdrawn->id)->inventory_count);

        $gone = Product::factory()->create(['inventory_count' => 1]);
        $goneRefund = $this->refundOf($gone, 1);
        $gone->forceDelete();

        $this->assertTrue($goneRefund->process());
        $this->assertSame('processed', $goneRefund->fresh()->status);
    }
}
