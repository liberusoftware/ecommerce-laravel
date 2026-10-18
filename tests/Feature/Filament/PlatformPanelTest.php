<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Orders\OrderResource;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionProperty;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * /admin is the platform panel: no tenancy, every store, and explicit platform
 * permissions. Lifting tenancy there must not lift anything anywhere else.
 */
class PlatformPanelTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        Role::findOrCreate('super_admin', 'web');

        return User::factory()->create()->assignRole('super_admin');
    }

    private function productIn(Store $store, string $name): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $product->forceFill(['store_id' => $store->id, 'team_id' => $store->team_id])->save();

        return $product;
    }

    private function merchantStore(string $name): Store
    {
        return Store::factory()->create(['team_id' => Team::factory()->create()->id, 'name' => $name]);
    }

    public function test_the_platform_panel_has_no_tenancy(): void
    {
        $this->assertFalse(Filament::getPanel('admin')->hasTenancy());
        $this->assertTrue(Filament::getPanel('app')->hasTenancy());
    }

    public function test_every_platform_list_page_responds_for_the_super_admin(): void
    {
        $this->actingAs($this->superAdmin());
        $failures = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (! $resource::hasPage('index')) {
                continue;
            }

            $response = $this->get($resource::getUrl(panel: 'admin'));

            if ($response->getStatusCode() >= 300) {
                $failures[] = class_basename($resource).' -> '.$response->getStatusCode()
                    .($response->exception ? ' :: '.$response->exception->getMessage() : '');
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_the_super_admin_sees_every_stores_catalogue_and_orders(): void
    {
        $this->productIn($this->merchantStore('Alpha'), 'Alpha Lamp');
        $this->productIn($this->merchantStore('Beta'), 'Beta Lamp');

        $this->actingAs($this->superAdmin())
            ->get('/admin/products')
            ->assertOk()
            ->assertSee('Alpha Lamp')
            ->assertSee('Beta Lamp');
        $this->get('/admin/orders')->assertOk();
        $this->get('/admin/categories')->assertOk();
    }

    public function test_the_merchant_panel_stays_scoped_after_a_platform_request(): void
    {
        $alpha = $this->merchantStore('Alpha');
        $this->productIn($alpha, 'Alpha Lamp');
        $this->productIn($this->merchantStore('Beta'), 'Beta Lamp');
        $user = $this->superAdmin();
        $alpha->team->users()->attach($user, ['role' => 'admin']);

        $this->actingAs($user)->get('/admin/products')->assertSee('Beta Lamp');
        $this->get("/app/{$alpha->team_id}/products")->assertOk()->assertSee('Alpha Lamp')->assertDontSee('Beta Lamp');
    }

    public function test_a_record_created_on_the_platform_goes_where_the_operator_says(): void
    {
        $alpha = $this->merchantStore('Alpha');
        $beta = $this->merchantStore('Beta');
        $this->actingAs($this->superAdmin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $base = ['name' => 'Placed', 'slug' => 'placed', 'pricing_type' => 'fixed', 'price' => 5, 'inventory_count' => 1, 'low_stock_threshold' => 0];

        Livewire::test(CreateProduct::class)->fillForm($base + ['store_id' => null])->call('create')->assertHasFormErrors(['store_id']);
        Livewire::test(CreateProduct::class)->fillForm($base + ['store_id' => $beta->id])->call('create')->assertHasNoFormErrors();

        $product = Product::withoutGlobalScopes()->where('slug', 'placed')->sole();
        $this->assertSame($beta->id, $product->store_id);
        $this->assertSame($beta->team_id, $product->team_id);
        $this->assertNotSame($alpha->id, $product->store_id);
    }

    public function test_platform_staff_get_exactly_their_platform_permissions(): void
    {
        $this->productIn($this->merchantStore('Alpha'), 'Alpha Lamp');
        $staff = User::factory()->create();
        foreach (['access_admin_panel', 'view_any_product', 'view_product'] as $permission) {
            $staff->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $this->actingAs($staff)->get('/admin/products')->assertOk()->assertSee('Alpha Lamp');
        $this->get('/admin/products/create')->assertForbidden();
        $this->get('/admin/orders')->assertForbidden();
        $this->get('/admin/stores')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/manage-general-settings')->assertForbidden();
    }

    public function test_a_platform_permission_applies_to_every_store_and_a_team_role_does_not(): void
    {
        $alpha = $this->merchantStore('Alpha');
        $beta = $this->merchantStore('Beta');
        $order = Order::create(['customer_email' => 'x@example.test', 'total_amount' => 1, 'status' => Order::STATUS_PAID]);
        $order->forceFill(['store_id' => $beta->id, 'team_id' => $beta->team_id])->save();

        $alphaAdmin = User::factory()->create();
        $alpha->team->users()->attach($alphaAdmin, ['role' => 'admin']);
        $support = User::factory()->create()->givePermissionTo(Permission::findOrCreate('update_order', 'web'));

        $this->assertFalse($alphaAdmin->can('update', $order));
        $this->assertTrue($support->can('update', $order));
    }

    public function test_neither_merchants_nor_shoppers_reach_the_platform_panel(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        Store::factory()->create(['team_id' => $team->id]);

        $this->actingAs($owner)->get('/admin')->assertForbidden();
        $this->actingAs($owner)->get('/admin/products')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/orders')->assertForbidden();
    }

    public function test_no_merchant_resource_is_unscoped_without_declaring_it_itself(): void
    {
        $unscoped = [];

        foreach (Filament::getPanel('app')->getResources() as $resource) {
            $declaredHere = (new ReflectionProperty($resource, 'isScopedToTenant'))->getDeclaringClass()->getName() === $resource;

            if (! $resource::isScopedToTenant() && ! $declaredHere) {
                $unscoped[] = $resource;
            }
        }

        $this->assertSame([], $unscoped);
        $this->assertTrue(ProductResource::isScopedToTenant());
        $this->assertTrue(OrderResource::isScopedToTenant());
    }

    public function test_the_platform_product_list_lists_every_store(): void
    {
        $alpha = $this->productIn($this->merchantStore('Alpha'), 'Alpha Lamp');
        $beta = $this->productIn($this->merchantStore('Beta'), 'Beta Lamp');
        $this->actingAs($this->superAdmin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$alpha, $beta]);
    }
}
