<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Filament\App\Resources\Invoices\Pages\ListInvoices;
use App\Filament\App\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An invoice states what was sold, at what price, in what currency, and keeps
 * stating it whatever later happens to the catalogue. Nobody writes one by hand.
 */
class InvoiceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Order $order;

    /** @var array<string, Product> */
    private array $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create(['name' => 'Jane Doe']);
        $this->products = [
            'chips' => Product::factory()->create(['name' => 'Chips']),
            'burger' => Product::factory()->create(['name' => 'Smashdown']),
            'pop' => Product::factory()->create(['name' => 'Can of pop']),
        ];

        $this->order = Order::create([
            'user_id' => $this->buyer->id,
            'customer_email' => 'jane@example.test',
            'currency_code' => 'EUR',
            'total_amount' => 21.40,
            'discount_amount' => 1.00,
            'coupon_code' => 'WELCOME',
            'shipping_cost' => 2.50,
            'tax_amount' => 1.20,
            'status' => Order::STATUS_PENDING,
            'payment_status' => 'pending',
        ]);
        $this->order->items()->create([
            'product_id' => $this->products['chips']->id, 'product_name' => 'Chips', 'quantity' => 2, 'price' => 3.30,
            'options' => [
                ['choice_id' => 1, 'group' => 'Size', 'name' => 'Large', 'price_adjustment' => 0.2],
                ['choice_id' => 2, 'group' => 'Type', 'name' => 'Battered', 'price_adjustment' => 0.2],
            ],
        ]);
        $this->order->items()->create(['product_id' => $this->products['burger']->id, 'product_name' => 'Smashdown', 'quantity' => 1, 'price' => 7.00]);
        $this->order->items()->create(['product_id' => $this->products['pop']->id, 'product_name' => 'Can of pop', 'quantity' => 4, 'price' => 1.10]);
    }

    private function issue(): Invoice
    {
        $this->order->transitionTo(Order::STATUS_PAID);

        return Invoice::where('order_id', $this->order->id)->sole();
    }

    public function test_the_invoice_states_the_frozen_lines_and_the_orders_money(): void
    {
        $invoice = $this->issue();

        $this->actingAs($this->buyer)->get(route('invoices.show', $invoice->id))
            ->assertOk()
            ->assertSeeInOrder(['Chips', 'Size: Large (+€0.20)', 'Type: Battered (+€0.20)', '€3.30', '€6.60'])
            ->assertSeeInOrder(['Smashdown', '€7.00'])
            ->assertSeeInOrder(['Can of pop', '4', '€1.10', '€4.40'])
            ->assertSeeInOrder(['Subtotal', '€18.00', 'Discount (WELCOME)', '−€1.00', 'Shipping', '€2.50', 'Tax', '€1.20', 'Total', '€21.40'])
            ->assertSee('Jane Doe');
    }

    public function test_the_invoice_survives_rename_soft_delete_and_force_delete(): void
    {
        $invoice = $this->issue();

        $this->products['chips']->update(['name' => 'Chunky Chips', 'price' => 9.99]);
        $this->products['burger']->delete();
        $this->products['pop']->forceDelete();

        $this->assertCount(3, $invoice->fresh()->lines);
        $this->actingAs($this->buyer)->get(route('invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('Chips')->assertSee('Smashdown')->assertSee('Can of pop')
            ->assertDontSee('Chunky Chips')
            ->assertSee('€21.40');
    }

    public function test_the_invoice_list_uses_the_orders_currency(): void
    {
        $this->issue();

        $this->actingAs($this->buyer)->get(route('invoices.index'))->assertOk()->assertSee('€21.40');
    }

    public function test_nobody_creates_or_edits_an_invoice_by_hand(): void
    {
        $invoice = $this->issue();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        foreach (['create', 'update', 'delete'] as $ability) {
            $this->assertFalse($admin->can($ability, $ability === 'create' ? Invoice::class : $invoice), "super_admin {$ability}");
        }
        $this->assertSame(['index', 'view'], array_keys(InvoiceResource::getPages()));
    }

    public function test_the_merchant_views_the_invoice_read_only(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        $store = Store::factory()->create(['team_id' => $team->id]);
        $this->order->forceFill(['store_id' => $store->id, 'team_id' => $team->id])->save();
        $invoice = $this->issue();

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);
        Filament::getPanel('app')->boot();

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords([$invoice])
            ->assertTableColumnStateSet('total_amount', '€21.40', $invoice)
            ->assertActionDoesNotExist('create');
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertOk()
            ->assertSee('2 × Chips — Size: Large (+€0.20); Type: Battered (+€0.20) @ €3.30')
            ->assertSee('€21.40');
    }
}
