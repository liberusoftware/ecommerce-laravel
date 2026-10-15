<?php

namespace Tests\Feature;

use App\Actions\Jetstream\DeleteTeam;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Paid orders, invoices and refunds are financial records: no ordinary
 * back-office delete may erase them, directly or by cascade.
 */
class FinancialRecordDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Store $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->store = Store::factory()->create(['team_id' => $this->team->id]);
        $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'admin']);

        foreach (['view_any_order', 'view_order', 'delete_order', 'delete_any_order', 'delete_customer', 'delete_any_customer', 'delete_invoice', 'delete_any_invoice'] as $permission) {
            $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $this->actingAs($this->user);
    }

    private function customer(): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Ada', 'last_name' => 'Byron', 'email' => uniqid().'@example.test', 'phone_number' => '1',
            'address' => 'x', 'city' => 'x', 'state' => 'x', 'postal_code' => '1',
            'store_id' => $this->store->id, 'team_id' => $this->team->id,
        ]);
    }

    private function order(string $status, string $paymentStatus, ?Customer $customer = null): Order
    {
        return Order::forceCreate([
            'store_id' => $this->store->id, 'team_id' => $this->team->id, 'customer_id' => $customer?->id,
            'total_amount' => 10, 'status' => $status, 'payment_status' => $paymentStatus, 'order_date' => now(),
        ]);
    }

    public function test_only_unpaid_failed_or_cancelled_orders_can_be_deleted(): void
    {
        $this->assertFalse($this->user->can('delete', $this->order(Order::STATUS_PAID, 'paid')));
        $this->assertFalse($this->user->can('delete', $this->order(Order::STATUS_PENDING, 'pending')));
        $this->assertFalse($this->user->can('delete', $this->order(Order::STATUS_CANCELLED, 'paid')));
        $this->assertTrue($this->user->can('delete', $this->order(Order::STATUS_CANCELLED, 'pending')));
        $this->assertTrue($this->user->can('delete', $this->order(Order::STATUS_FAILED, 'failed')));
    }

    public function test_a_customer_with_orders_cannot_be_deleted(): void
    {
        $buyer = $this->customer();
        $this->order(Order::STATUS_PAID, 'paid', $buyer);

        $this->assertFalse($this->user->can('delete', $buyer));
        $this->assertTrue($this->user->can('delete', $this->customer()));
    }

    public function test_an_invoice_can_never_be_deleted(): void
    {
        $invoice = Invoice::generateForOrder($this->order(Order::STATUS_PAID, 'paid', $this->customer()));

        $this->assertFalse($this->user->can('delete', $invoice));
        $this->assertFalse($this->user->can('deleteAny', Invoice::class));
    }

    public function test_bulk_delete_skips_orders_that_may_not_be_deleted(): void
    {
        $paid = $this->order(Order::STATUS_PAID, 'paid');
        $cancelled = $this->order(Order::STATUS_CANCELLED, 'pending');

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->team);
        Filament::getPanel('app')->boot();

        Livewire::test(ListOrders::class)->callTableBulkAction('delete', [$paid, $cancelled]);

        $this->assertNotNull($paid->fresh());
        $this->assertNull($cancelled->fresh());
    }

    public function test_a_team_with_orders_cannot_be_deleted(): void
    {
        $this->order(Order::STATUS_PAID, 'paid');

        try {
            (new DeleteTeam)->delete($this->team);
            $this->fail('A team with orders was deleted.');
        } catch (ValidationException) {
            $this->assertNotNull($this->team->fresh());
            $this->assertSame(1, Order::withoutGlobalScopes()->count());
        }
    }

    public function test_the_database_refuses_to_cascade_a_customer_delete_into_orders(): void
    {
        $buyer = $this->customer();
        $order = $this->order(Order::STATUS_PAID, 'paid', $buyer);
        Invoice::generateForOrder($order);

        try {
            Customer::withoutGlobalScopes()->whereKey($buyer->id)->delete();
            $this->fail('Deleting a customer cascaded into their orders.');
        } catch (QueryException) {
            $this->assertNotNull($order->fresh());
            $this->assertSame(1, Invoice::count());
        }
    }
}
