<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Orders\Pages\CreateOrder;
use App\Filament\App\Resources\Orders\Pages\EditOrder;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ManualOrderTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $team = Team::factory()->create();
        $this->store = Store::factory()->create(['team_id' => $team->id]);
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'admin']);
        $this->customer = Customer::forceCreate([
            'first_name' => 'Ada', 'last_name' => 'Byron', 'email' => 'ada@example.test', 'phone_number' => '1',
            'address' => 'x', 'city' => 'x', 'state' => 'x', 'postal_code' => '1',
            'store_id' => $this->store->id, 'team_id' => $team->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);
        Filament::getPanel('app')->boot();
        Repeater::fake();
    }

    private function product(float $price, int $stock): Product
    {
        return Product::factory()->create([
            'price' => $price, 'inventory_count' => $stock,
            'store_id' => $this->store->id, 'team_id' => $this->store->team_id,
        ]);
    }

    private function pendingOrder(Product $product, int $quantity): Order
    {
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'order_date' => now()->toDateString(),
                'shipping_status' => 'pending',
                'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return Order::latest('id')->firstOrFail();
    }

    public function test_an_order_needs_at_least_one_line_item(): void
    {
        Livewire::test(CreateOrder::class)
            ->fillForm(['customer_id' => $this->customer->id, 'order_date' => now()->toDateString(), 'items' => []])
            ->call('create')
            ->assertHasFormErrors(['items']);

        $this->assertSame(0, Order::count());
    }

    public function test_the_total_comes_from_the_line_items_and_the_order_starts_pending(): void
    {
        $lamp = $this->product(12.50, 10);
        $desk = $this->product(100.00, 5);

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'order_date' => now()->toDateString(),
                'total_amount' => 1,
                'payment_status' => 'paid',
                'items' => [['product_id' => $lamp->id, 'quantity' => 2], ['product_id' => $desk->id, 'quantity' => 1]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = Order::firstOrFail();
        $this->assertEquals(125.00, $order->total_amount);
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(8, $lamp->fresh()->inventory_count);
        $this->assertSame(4, $desk->fresh()->inventory_count);
    }

    public function test_an_order_beyond_available_stock_is_refused_and_nothing_is_written(): void
    {
        $lamp = $this->product(12.50, 1);

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'order_date' => now()->toDateString(),
                'items' => [['product_id' => $lamp->id, 'quantity' => 3]],
            ])
            ->call('create')
            ->assertHasFormErrors(['items']);

        $this->assertSame(0, Order::count());
        $this->assertSame(1, $lamp->fresh()->inventory_count);
    }

    public function test_editing_cannot_change_payment_state_or_the_total(): void
    {
        $order = $this->pendingOrder($this->product(20.00, 5), 1);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->set('data.payment_status', 'paid')
            ->set('data.status', 'paid')
            ->set('data.total_amount', 1)
            ->set('data.shipping_status', 'shipped')
            ->call('save');

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertEquals(20.00, $order->total_amount);
        $this->assertSame('shipped', $order->shipping_status);
    }

    public function test_marking_paid_goes_through_the_state_machine(): void
    {
        $order = $this->pendingOrder($this->product(20.00, 5), 1);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->callAction('markPaid');

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(1, $order->statusHistory()->where('to_status', Order::STATUS_PAID)->count());
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
    }

    public function test_cancelling_a_pending_order_releases_its_stock(): void
    {
        $lamp = $this->product(20.00, 5);
        $order = $this->pendingOrder($lamp, 2);
        $this->assertSame(3, $lamp->fresh()->inventory_count);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->callAction('cancel');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(5, $lamp->fresh()->inventory_count);
    }

    public function test_only_allowed_transitions_are_offered(): void
    {
        $order = $this->pendingOrder($this->product(20.00, 5), 1);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('markPaid')
            ->assertActionVisible('cancel')
            ->assertActionHidden('startProcessing')
            ->assertActionHidden('complete');
    }
}
