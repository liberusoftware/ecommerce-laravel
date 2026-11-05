<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\CartItem;
use App\Models\InventoryLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Services\PaymentGateways\StripeGateway;
use App\Services\ProductVariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsOptionGroups;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/**
 * A variant through both checkout paths: its price and stock are revalidated,
 * its own stock is reserved, and the order line freezes what was sold.
 */
class ProductVariantCheckoutTest extends TestCase
{
    use BuildsOptionGroups;
    use BuildsVariants;
    use RefreshDatabase;

    private object $gateway;

    private Product $tee;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->gateway = new class implements PaymentGatewayInterface
        {
            public ?float $chargedAmount = null;

            public bool $succeed = true;

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
                return ['success' => true];
            }
        };
        $this->app->instance(StripeGateway::class, $this->gateway);

        // A download, so neither path needs a shipping method.
        $this->tee = $this->variableProduct(['Size' => ['S', 'M'], 'Colour' => ['Black', 'White']], [
            $this->row(['Size' => 'S', 'Colour' => 'Black'], 20, 5, ['sku' => 'TEE-S-BLK']),
            $this->row(['Size' => 'S', 'Colour' => 'White'], 20, 5, ['sku' => 'TEE-S-WHT']),
            $this->row(['Size' => 'M', 'Colour' => 'Black'], 22, 1, ['sku' => 'TEE-M-BLK']),
            $this->row(['Size' => 'M', 'Colour' => 'White'], 22, 0, ['sku' => 'TEE-M-WHT']),
        ], ['name' => 'Logo Tee', 'is_downloadable' => true, 'inventory_count' => 40]);
    }

    private function id(string $label): int
    {
        return $this->variantOf($this->tee, $label)->id;
    }

    private function webAdd(string $label, int $quantity = 1, array $options = [])
    {
        return $this->post(route('cart.add', $this->tee), ['variant_id' => $this->id($label), 'quantity' => $quantity, 'options' => $options]);
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

    private function graphql(string $query, array $variables = []): array
    {
        return $this->postJson('/api/graphql', ['query' => $query, 'variables' => $variables])->json();
    }

    private function graphqlAdd(string $label, int $quantity = 1, array $options = []): array
    {
        return $this->graphql(
            'mutation($p: Int!, $q: Int!, $v: Int, $o: [Int!]) { addToCart(productId: $p, quantity: $q, variantId: $v, options: $o) { id } }',
            ['p' => $this->tee->id, 'q' => $quantity, 'v' => $this->id($label), 'o' => $options],
        );
    }

    private function graphqlCheckout(): array
    {
        return $this->graphql(
            'mutation($input: CheckoutInput!) { checkout(input: $input) { id totalAmount items { productName sku variantId variantAttributes { name value } options { name priceAdjustment } quantity price } } }',
            ['input' => ['country' => 'US', 'paymentMethod' => 'stripe', 'stripeToken' => 'tok']],
        );
    }

    private function assertSnapshot(OrderItem $line, string $label, string $sku, float $price, int $quantity): void
    {
        $this->assertSame('Logo Tee', $line->product_name);
        $this->assertSame($this->id($label), $line->product_variant_id);
        $this->assertSame($sku, $line->sku);
        $this->assertSame($this->variantOf($this->tee, $label)->attribute_values, $line->variant_attributes);
        $this->assertEquals($price, $line->price);
        $this->assertSame($quantity, $line->quantity);
    }

    public function test_web_checkout_charges_variant_prices_reserves_variant_stock_and_freezes_the_lines(): void
    {
        $wrap = $this->optionGroup('Gift wrapping', 0, 1, ['Wrapped' => 3.50], [$this->tee]);
        $this->webAdd('S / Black', 2, $this->choiceIds($wrap, 'Wrapped'));
        $this->webAdd('M / Black');

        $this->webCheckout()->assertSessionHas('success');

        $order = Order::sole();
        $this->assertEqualsWithDelta(69.00, (float) $order->total_amount, 0.001);
        $this->assertEqualsWithDelta(69.00, $this->gateway->chargedAmount, 0.001);
        [$small, $medium] = $order->items()->orderBy('id')->get()->all();
        $this->assertSnapshot($small, 'S / Black', 'TEE-S-BLK', 23.50, 2);
        $this->assertEquals([['choice_id' => $this->choice($wrap, 'Wrapped')->id, 'group' => 'Gift wrapping', 'name' => 'Wrapped', 'price_adjustment' => 3.5]], $small->options);
        $this->assertSnapshot($medium, 'M / Black', 'TEE-M-BLK', 22, 1);

        $this->assertSame([3, 5, 0, 0], $this->tee->variants()->pluck('inventory_count')->all());
        $this->assertSame(40, $this->tee->fresh()->inventory_count, 'Product-level stock is not counted for a product with variants.');
        $this->assertSame(
            [[$this->id('S / Black'), -2, 5, 3], [$this->id('M / Black'), -1, 1, 0]],
            InventoryLog::orderBy('id')->get()->map(fn ($l) => [$l->product_variant_id, $l->quantity_change, $l->old_quantity, $l->new_quantity])->all(),
        );
        $this->assertSame(0, CartItem::count());
    }

    public function test_graphql_checkout_charges_and_freezes_exactly_as_the_web_does(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $wrap = $this->optionGroup('Gift wrapping', 0, 1, ['Wrapped' => 3.50], [$this->tee]);
        $this->graphqlAdd('S / Black', 2, $this->choiceIds($wrap, 'Wrapped'));
        $this->graphqlAdd('M / Black');

        $placed = $this->graphqlCheckout()['data']['checkout'];

        $this->assertEqualsWithDelta(69.00, $placed['totalAmount'], 0.001);
        $this->assertSame(['Logo Tee', 'TEE-S-BLK', $this->id('S / Black'), 2, 23.5], [$placed['items'][0]['productName'], $placed['items'][0]['sku'], $placed['items'][0]['variantId'], $placed['items'][0]['quantity'], (float) $placed['items'][0]['price']]);
        $this->assertSame([['name' => 'Size', 'value' => 'S'], ['name' => 'Colour', 'value' => 'Black']], $placed['items'][0]['variantAttributes']);
        $this->assertSame([['name' => 'Wrapped', 'priceAdjustment' => 3.5]], $placed['items'][0]['options']);
        [$small, $medium] = Order::sole()->items()->orderBy('id')->get()->all();
        $this->assertSnapshot($small, 'S / Black', 'TEE-S-BLK', 23.50, 2);
        $this->assertSnapshot($medium, 'M / Black', 'TEE-M-BLK', 22, 1);
        $this->assertSame([3, 5, 0, 0], $this->tee->variants()->pluck('inventory_count')->all());
    }

    public function test_a_variant_price_changed_after_adding_is_corrected_and_checkout_refused_on_both_paths(): void
    {
        $this->webAdd('S / Black');
        $this->variantOf($this->tee, 'S / Black')->update(['price' => 25]);

        $this->webCheckout()->assertSessionHas('error', fn (string $m) => str_contains($m, 'Prices in your cart have changed'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(25, CartItem::sole()->price);
        $this->webCheckout()->assertSessionHas('success');
        $this->assertEqualsWithDelta(25.00, $this->gateway->chargedAmount, 0.001);

        Sanctum::actingAs(User::factory()->create());
        $this->graphqlAdd('S / White');
        $this->variantOf($this->tee, 'S / White')->update(['price' => 18]);
        $this->assertStringContainsString('Prices in your cart have changed', $this->graphqlCheckout()['errors'][0]['message']);
        $this->assertEquals(18, CartItem::sole()->price);
    }

    public function test_a_variant_switched_off_or_removed_after_adding_is_refused_on_both_paths(): void
    {
        $this->webAdd('S / Black');
        $this->variantOf($this->tee, 'S / Black')->update(['is_active' => false]);

        $this->webCheckout()->assertSessionHas('error', fn (string $m) => str_contains($m, 'The version of Logo Tee in your cart'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, CartItem::count(), 'The line is kept, so the shopper can see what to remove.');

        Sanctum::actingAs(User::factory()->create());
        $this->graphqlAdd('S / White');
        app(ProductVariantService::class)->sync($this->tee, $this->attributes(['Size' => ['S', 'M'], 'Colour' => ['Black']]), [
            $this->row(['Size' => 'S', 'Colour' => 'Black'], 20),
            $this->row(['Size' => 'M', 'Colour' => 'Black'], 22),
        ]);

        $this->assertStringContainsString('The version of Logo Tee in your cart', $this->graphqlCheckout()['errors'][0]['message']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_parent_taken_down_cannot_be_bought_through_an_active_variant(): void
    {
        $this->webAdd('S / Black');
        Sanctum::actingAs(User::factory()->create());
        $this->graphqlAdd('S / White');
        $this->tee->update(['status' => Product::STATUS_DRAFT]);

        $this->assertStringContainsString('no longer available', $this->graphqlCheckout()['errors'][0]['message']);
        $this->assertNotNull($this->graphqlAdd('S / Black')['errors'] ?? null);
        $this->postJson('/api/cart', ['product_id' => $this->tee->id, 'variant_id' => $this->id('S / Black'), 'quantity' => 1])->assertNotFound();

        auth()->forgetGuards();
        $this->webCheckout()->assertSessionHas('error', fn (string $m) => str_contains($m, 'no longer available'));
        $this->webAdd('S / White')->assertSessionHas('error', 'This product is not available.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertNull($this->gateway->chargedAmount);
        $this->assertSame(5, $this->variantOf($this->tee, 'S / Black')->inventory_count);
    }

    public function test_stock_taken_by_someone_else_is_refused_on_both_paths_and_nothing_is_charged(): void
    {
        $this->webAdd('M / Black');
        $this->variantOf($this->tee, 'M / Black')->adjustInventory(-1, 'sold in shop');

        $this->webCheckout()->assertSessionHas('error', fn (string $m) => str_contains($m, 'no longer available in the requested quantity'));
        $this->assertDatabaseCount('orders', 0);

        CartItem::query()->delete();
        Sanctum::actingAs(User::factory()->create());
        $this->graphqlAdd('S / Black', 5);
        $this->variantOf($this->tee, 'S / Black')->adjustInventory(-1, 'damaged');

        $this->assertStringContainsString('no longer available in the requested quantity', $this->graphqlCheckout()['errors'][0]['message']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNull($this->gateway->chargedAmount);
    }

    public function test_a_failed_payment_returns_the_variant_stock(): void
    {
        $this->gateway->succeed = false;
        $this->webAdd('S / Black', 2);

        $this->webCheckout()->assertSessionHas('error');

        $this->assertSame(Order::STATUS_FAILED, Order::sole()->status);
        $this->assertSame(5, $this->variantOf($this->tee, 'S / Black')->inventory_count);
        $this->assertSame(['order', 'payment_failed_release'], InventoryLog::orderBy('id')->pluck('reason')->all());
        $this->assertSame([$this->id('S / Black')], InventoryLog::distinct()->pluck('product_variant_id')->all());
    }

    public function test_the_order_and_invoice_survive_the_variant_being_edited_and_deleted(): void
    {
        $this->actingAs($buyer = User::factory()->create(['email' => 'buyer@example.com']));
        $this->webAdd('S / Black', 2);
        $this->webCheckout()->assertSessionHas('success');
        $order = Order::sole();

        $variant = $this->variantOf($this->tee, 'S / Black');
        $variant->update(['price' => 99, 'sku' => 'RENAMED', 'attribute_values' => [['name' => 'Size', 'value' => 'Small'], ['name' => 'Colour', 'value' => 'Jet']]]);
        $variant->delete();
        $this->tee->update(['name' => 'Renamed Tee']);
        $this->tee->forceDelete();

        $line = $order->items()->sole();
        $this->assertSame(['Logo Tee', 'TEE-S-BLK', 'Size: S, Colour: Black'], [$line->displayName(), $line->sku, $line->variantLabel()]);
        $this->assertEquals(20, $line->price);
        $this->assertNull($line->product_variant_id);

        $invoice = Invoice::generateForOrder($order);
        $this->actingAs($buyer)->get(route('invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('Logo Tee')
            ->assertSee('Size: S, Colour: Black')
            ->assertSee('TEE-S-BLK')
            ->assertDontSee('RENAMED');
    }

    public function test_a_refund_restocks_the_variant_that_was_sold(): void
    {
        $this->webAdd('S / Black', 2);
        $this->webCheckout()->assertSessionHas('success');
        $order = Order::sole();
        $line = $order->items()->sole();

        $refund = Refund::create(['order_id' => $order->id, 'amount' => 20, 'status' => 'pending', 'restock_items' => true]);
        $refund->items()->create(['order_item_id' => $line->id, 'quantity' => 1, 'amount' => 20, 'restock' => true]);
        $this->assertTrue($refund->process());

        $this->assertSame(4, $this->variantOf($this->tee, 'S / Black')->inventory_count);
        $this->assertSame(40, $this->tee->fresh()->inventory_count);
        $log = InventoryLog::latest('id')->first();
        $this->assertSame(['refund', $this->id('S / Black'), 1], [$log->reason, $log->product_variant_id, $log->quantity_change]);
    }
}
