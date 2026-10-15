<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Invoices\Pages\ListInvoices;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_customer_column_shows_the_customers_name(): void
    {
        Gate::before(fn () => true);
        $team = Team::factory()->create();
        $store = Store::factory()->create(['team_id' => $team->id]);
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'admin']);

        $customer = Customer::forceCreate([
            'first_name' => 'Ada', 'last_name' => 'Byron', 'email' => 'ada@example.test', 'phone_number' => '1',
            'address' => 'x', 'city' => 'x', 'state' => 'x', 'postal_code' => '1',
            'store_id' => $store->id, 'team_id' => $team->id,
        ]);
        $invoice = Invoice::generateForOrder(Order::forceCreate([
            'store_id' => $store->id, 'team_id' => $team->id, 'customer_id' => $customer->id,
            'total_amount' => 10, 'status' => 'paid', 'payment_status' => 'paid', 'order_date' => now(),
        ]));
        $invoice->forceFill(['team_id' => $team->id, 'store_id' => $store->id])->save();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);
        Filament::getPanel('app')->boot();

        Livewire::test(ListInvoices::class)->assertTableColumnStateSet('customer_name', 'Ada Byron', $invoice);
    }
}
