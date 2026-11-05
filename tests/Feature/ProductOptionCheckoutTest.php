<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\User;
use App\Notifications\OrderConfirmationNotification;
use App\Services\PaymentGateways\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsOptionGroups;
use Tests\TestCase;

/**
 * Customised lines through both checkout paths: the agreed price is charged,
 * the order freezes what was chosen, and nothing the catalogue does afterwards
 * changes a placed order.
 */
class ProductOptionCheckoutTest extends TestCase
{
    use BuildsOptionGroups;
    use RefreshDatabase;

    private object $gateway;

    private Product $chips;

    private ProductOptionGroup $size;

    private ProductOptionGroup $type;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->gateway = new class implements PaymentGatewayInterface
        {
            public ?float $chargedAmount = null;

            public function processPayment(float $amount, array $paymentDetails): array
            {
                $this->chargedAmount = $amount;

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
        };
        $this->app->instance(StripeGateway::class, $this->gateway);

        $this->chips = Product::factory()->create(['name' => 'Chips', 'price' => 2.90, 'inventory_count' => 10, 'is_downloadable' => true, 'track_inventory' => true]);
        $this->size = $this->optionGroup('Size', 1, 1, ['Small' => 0, 'Regular' => 0.10, 'Large' => 0.20], [$this->chips]);
        $this->type = $this->optionGroup('Type', 1, 1, ['Plain' => 0, 'Battered' => 0.20], [$this->chips]);
    }

    /** @return list<int> */
    private function largeBattered(): array
    {
        return [$this->choice($this->size, 'Large')->id, $this->choice($this->type, 'Battered')->id];
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

    private function graphqlAdd(array $choices, int $quantity = 1): array
    {
        return $this->graphql(
            'mutation($p: Int!, $q: Int!, $o: [Int!]) { addToCart(productId: $p, quantity: $q, options: $o) { id } }',
            ['p' => $this->chips->id, 'q' => $quantity, 'o' => $choices],
        );
    }

    private function graphqlCheckout(): array
    {
        return $this->graphql(
            'mutation($input: CheckoutInput!) { checkout(input: $input) { id totalAmount } }',
            ['input' => ['country' => 'US', 'paymentMethod' => 'stripe', 'stripeToken' => 'tok']],
        );
    }

    private function assertLargeBatteredLine(OrderItem $line, int $quantity): void
    {
        $this->assertSame('Chips', $line->product_name);
        $this->assertSame($quantity, $line->quantity);
        $this->assertEquals(3.30, $line->price);
        $this->assertSame([
            ['choice_id' => $this->choice($this->size, 'Large')->id, 'group' => 'Size', 'name' => 'Large', 'price_adjustment' => 0.2],
            ['choice_id' => $this->choice($this->type, 'Battered')->id, 'group' => 'Type', 'name' => 'Battered', 'price_adjustment' => 0.2],
        ], array_map(function (array $option) {
            // MySQL's JSON type does not keep key order.
            ksort($option);

            return $option;
        }, $line->options));
    }

    public function test_web_checkout_charges_the_configured_price_and_freezes_the_line(): void
    {
        $this->post(route('cart.add', $this->chips), ['quantity' => 2, 'options' => $this->largeBattered()]);
        $this->post(route('cart.add', $this->chips), ['quantity' => 1, 'options' => $this->choiceIds($this->size, 'Small') + [1 => $this->choice($this->type, 'Plain')->id]]);

        $this->webCheckout()->assertSessionHas('success');

        $order = Order::sole();
        $this->assertEqualsWithDelta(9.50, (float) $order->total_amount, 0.001);
        $this->assertEqualsWithDelta(9.50, $this->gateway->chargedAmount, 0.001);
        [$large, $small] = $order->items()->orderBy('id')->get()->all();
        $this->assertLargeBatteredLine($large, 2);
        $this->assertEquals(2.90, $small->price);
        $this->assertSame(['Small', 'Plain'], array_column($small->options, 'name'));
        $this->assertSame(7, $this->chips->fresh()->inventory_count);
    }

    public function test_graphql_checkout_charges_the_configured_price_and_freezes_the_line(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->graphqlAdd($this->largeBattered(), 2);

        $data = $this->graphqlCheckout();

        $this->assertEqualsWithDelta(6.60, $data['data']['checkout']['totalAmount'], 0.001);
        $this->assertEqualsWithDelta(6.60, $this->gateway->chargedAmount, 0.001);
        $this->assertLargeBatteredLine(Order::sole()->items()->sole(), 2);
    }

    public function test_web_checkout_refuses_a_moved_option_price_then_charges_the_new_one(): void
    {
        $this->post(route('cart.add', $this->chips), ['options' => $this->largeBattered()]);
        $this->choice($this->size, 'Large')->update(['price_adjustment' => 0.50]);

        $this->webCheckout()->assertSessionHas('error', fn (string $m) => str_contains($m, 'Prices in your cart have changed'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertNull($this->gateway->chargedAmount);
        $this->assertEquals(3.60, CartItem::sole()->price);
        $this->assertSame(0.5, CartItem::sole()->options[0]['price_adjustment']);

        $this->webCheckout()->assertSessionHas('success');
        $this->assertEqualsWithDelta(3.60, $this->gateway->chargedAmount, 0.001);
    }

    public function test_graphql_checkout_refuses_a_moved_option_price_then_charges_the_new_one(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->graphqlAdd($this->largeBattered());
        $this->choice($this->type, 'Battered')->update(['price_adjustment' => 0.40]);

        $this->assertStringContainsString('Prices in your cart have changed', $this->graphqlCheckout()['errors'][0]['message']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(3.50, CartItem::sole()->price);

        $this->assertEqualsWithDelta(3.50, $this->graphqlCheckout()['data']['checkout']['totalAmount'], 0.001);
    }

    public function test_a_renamed_choice_at_the_same_price_is_carried_through_without_refusing(): void
    {
        $this->post(route('cart.add', $this->chips), ['options' => $this->largeBattered()]);
        $this->choice($this->size, 'Large')->update(['name' => 'Large (500g)']);

        $this->webCheckout()->assertSessionHas('success');

        $this->assertSame('Large (500g)', Order::sole()->items()->sole()->options[0]['name']);
    }

    public function test_both_paths_refuse_a_choice_withdrawn_after_it_was_added(): void
    {
        $this->post(route('cart.add', $this->chips), ['options' => $this->largeBattered()]);
        $this->choice($this->type, 'Battered')->update(['is_available' => false]);

        $this->webCheckout()->assertSessionHas('error', fn (string $m) => str_contains($m, 'Some options chosen for Chips are no longer available'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, CartItem::count());

        CartItem::query()->delete();
        Sanctum::actingAs(User::factory()->create());
        $this->choice($this->type, 'Battered')->update(['is_available' => true]);
        $this->graphqlAdd($this->largeBattered());
        $this->choice($this->type, 'Battered')->delete();

        $this->assertStringContainsString('no longer available', $this->graphqlCheckout()['errors'][0]['message']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNull($this->gateway->chargedAmount);
    }

    public function test_a_group_made_required_after_adding_is_refused_at_checkout(): void
    {
        $this->post(route('cart.add', $this->chips), ['options' => $this->largeBattered()]);
        $this->optionGroup('Dip', 1, 1, ['Curry' => 0.50], [$this->chips]);

        $this->webCheckout()->assertSessionHas('error', fn (string $m) => str_contains($m, 'no longer available'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_placed_order_survives_every_later_catalogue_change(): void
    {
        $this->post(route('cart.add', $this->chips), ['quantity' => 2, 'options' => $this->largeBattered()]);
        $this->webCheckout()->assertSessionHas('success');
        $order = Order::sole();

        $this->chips->update(['name' => 'Chunky Chips', 'price' => 9.99]);
        $this->choice($this->size, 'Large')->update(['name' => 'Huge', 'price_adjustment' => 5]);
        $this->type->delete();
        $this->chips->forceDelete();

        $line = $order->fresh()->items()->sole();
        $this->assertNull($line->product_id);
        $this->assertSame('Chips', $line->displayName());
        $this->assertEquals(3.30, $line->price);
        $this->assertSame(['Size: Large (+$0.20)', 'Type: Battered (+$0.20)'], $line->optionLabels('USD'));

        $this->withSession(['recent_order_ids' => [$order->id]])
            ->get(route('checkout.confirmation', ['order' => $order->id]))
            ->assertOk()
            ->assertSee('Chips')
            ->assertSee('Size: Large')
            ->assertSee('Type: Battered')
            ->assertDontSee('Huge')
            ->assertDontSee('Chunky Chips');
    }

    public function test_the_confirmation_email_lists_the_frozen_choices(): void
    {
        $this->post(route('cart.add', $this->chips), ['options' => $this->largeBattered()]);
        $this->webCheckout();
        $this->chips->update(['name' => 'Renamed']);

        Notification::assertSentOnDemand(OrderConfirmationNotification::class, function (OrderConfirmationNotification $notification) {
            $mail = $notification->toMail((object) [])->render();

            return str_contains($mail, 'Chips') && str_contains($mail, 'Size: Large') && ! str_contains($mail, 'Renamed');
        });
    }

    public function test_a_line_without_options_still_checks_out_unchanged(): void
    {
        $plain = Product::factory()->create(['name' => 'Can of pop', 'price' => 1.10, 'inventory_count' => 5, 'is_downloadable' => true]);
        $this->post(route('cart.add', $plain));

        $this->webCheckout()->assertSessionHas('success');

        $line = Order::sole()->items()->sole();
        $this->assertSame('Can of pop', $line->product_name);
        $this->assertNull($line->options);
        $this->assertSame([], $line->optionLabels());
    }
}
