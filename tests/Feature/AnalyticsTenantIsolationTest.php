<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Store $mine;

    private Store $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $team = Team::factory()->create();
        $this->mine = Store::factory()->create(['team_id' => $team->id]);
        $this->theirs = Store::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->actingAs(User::factory()->create(['current_team_id' => $team->id]));
    }

    private function customer(Store $store, string $email): Customer
    {
        return Customer::forceCreate([
            'store_id' => $store->id,
            'team_id' => $store->team_id,
            'first_name' => 'First',
            'last_name' => 'Last',
            'email' => $email,
            'phone_number' => '1',
            'address' => 'x',
            'city' => 'x',
            'state' => 'x',
            'postal_code' => '1',
        ]);
    }

    private function paidOrder(Store $store, ?Customer $customer = null, array $lines = []): Order
    {
        $order = Order::forceCreate([
            'store_id' => $store->id,
            'team_id' => $store->team_id,
            'customer_id' => $customer?->id,
            'order_date' => now()->subDays(2),
            'total_amount' => 100,
            'status' => 'paid',
            'payment_status' => 'paid',
        ]);

        foreach ($lines as [$product, $quantity, $price]) {
            OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'price' => $price]);
        }

        return $order;
    }

    public function test_customer_segments_count_only_the_current_tenants_customers_and_orders(): void
    {
        $buyer = $this->customer($this->mine, 'buyer@mine.test');
        $browser = $this->customer($this->mine, 'browser@mine.test');
        $this->paidOrder($this->mine, $buyer);

        foreach (range(1, 3) as $i) {
            $customer = $this->customer($this->theirs, "c{$i}@theirs.test");
            $this->paidOrder($this->theirs, $customer);
            $this->paidOrder($this->theirs, $customer);
        }
        // Another store's order must not move one of my customers into a segment.
        $this->paidOrder($this->theirs, $browser);

        $demographics = app(AnalyticsService::class)->getCustomerDemographics();

        $segments = collect($demographics['segments'])->pluck('customer_count', 'segment')->all();
        $this->assertEqualsCanonicalizing(['One-time Buyer' => 1, 'No Orders' => 1], $segments);
        $this->assertSame(2, $demographics['total_customers']);
        $this->assertSame([$buyer->id], array_column($demographics['top_customers'], 'id'));
        $this->assertEquals(1, $demographics['top_customers'][0]['order_count']);
    }

    public function test_top_products_include_only_the_current_tenants_products_and_orders(): void
    {
        $ours = Product::factory()->create(['store_id' => $this->mine->id, 'team_id' => $this->mine->team_id]);
        $foreign = Product::factory()->create(['store_id' => $this->theirs->id, 'team_id' => $this->theirs->team_id]);

        $this->paidOrder($this->mine, lines: [[$ours, 2, 50]]);
        $this->paidOrder($this->theirs, lines: [[$foreign, 100, 100], [$ours, 7, 10]]);

        $top = json_decode(json_encode(app(AnalyticsService::class)->getTopProducts()), true);

        $this->assertSame([$ours->id], array_column($top, 'id'));
        $this->assertEquals(2, $top[0]['total_quantity']);
        $this->assertEquals(100, $top[0]['total_revenue']);
    }
}
