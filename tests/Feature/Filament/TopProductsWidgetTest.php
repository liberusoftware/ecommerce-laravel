<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Widgets\TopProductsWidget;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TopProductsWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(Store $store, array $lines, string $paymentStatus = 'paid'): void
    {
        $order = Order::forceCreate([
            'store_id' => $store->id,
            'team_id' => $store->team_id,
            'order_date' => now()->subDays(2),
            'total_amount' => 0,
            'status' => $paymentStatus,
            'payment_status' => $paymentStatus,
        ]);

        foreach ($lines as [$product, $quantity, $price]) {
            OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'price' => $price]);
        }
    }

    public function test_it_lists_only_the_current_tenants_top_products(): void
    {
        Gate::before(fn () => true);
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $team = $user->ownedTeams()->first();
        $store = Store::factory()->create(['team_id' => $team->id]);
        $otherStore = Store::factory()->create(['team_id' => Team::factory()->create()->id]);

        $widget = Product::factory()->create(['store_id' => $store->id, 'team_id' => $team->id, 'name' => 'Widget']);
        $gadget = Product::factory()->create(['store_id' => $store->id, 'team_id' => $team->id, 'name' => 'Gadget']);
        $theirs = Product::factory()->create(['store_id' => $otherStore->id, 'team_id' => $otherStore->team_id]);

        $this->paidOrder($store, [[$widget, 3, 10], [$gadget, 1, 50]]);
        $this->paidOrder($store, [[$widget, 40, 10]], 'pending');
        $this->paidOrder($otherStore, [[$theirs, 100, 100], [$widget, 7, 10]]);

        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($team);
        // Registers Filament's tenancy global scopes, as its tenancy middleware does on a real request.
        Filament::getPanel('admin')->boot();

        Livewire::test(TopProductsWidget::class)
            ->assertCanSeeTableRecords([$gadget, $widget], inOrder: true)
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertTableColumnStateSet('total_quantity', 3, $widget)
            ->assertTableColumnStateSet('total_revenue', 30, $widget)
            ->assertTableColumnStateSet('total_revenue', 50, $gadget);
    }
}
