<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\ManageGeneralSettings;
use App\Filament\App\Pages\StoreSettings;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\ShippingMethods\Pages\ManageShippingMethods;
use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The merchant and platform settings screens, and the product and shipping forms they feed. */
class MerchantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $role = 'admin'): array
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => $role]);
        $store = Store::factory()->create(['team_id' => $team->id, 'name' => 'Acme']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);

        return [$user, $team, $store];
    }

    public function test_a_team_admin_saves_their_store_settings(): void
    {
        [, , $store] = $this->merchant();

        Livewire::test(StoreSettings::class)
            ->fillForm([
                'name' => 'Acme Outdoors',
                'email' => 'shop@acme.test',
                'country_code' => 'GB',
                'currency_code' => 'GBP',
                'locale' => 'en_GB',
                'display_prices_with_tax' => true,
                'payment_gateways' => ['paypal'],
                'social_links' => ['instagram' => 'https://instagram.com/acme', 'facebook' => ''],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $store->refresh();
        $this->assertSame('Acme Outdoors', $store->name);
        $this->assertSame('GBP', $store->currency_code);
        $this->assertSame('en_GB', $store->locale);
        $this->assertTrue($store->display_prices_with_tax);
        $this->assertSame(['paypal'], $store->paymentGateways());
        $this->assertSame(['instagram' => 'https://instagram.com/acme'], $store->social_links);
    }

    public function test_an_unknown_currency_or_country_is_rejected(): void
    {
        $this->merchant();

        Livewire::test(StoreSettings::class)
            ->fillForm(['currency_code' => 'XYZ', 'country_code' => 'QQ'])
            ->call('save')
            ->assertHasFormErrors(['currency_code', 'country_code']);
    }

    public function test_a_plain_team_member_cannot_change_store_settings(): void
    {
        [$user, $team] = $this->merchant(role: 'editor');

        $this->assertFalse(StoreSettings::canAccess());
        $this->get("/app/{$team->id}/store-settings")->assertForbidden();
    }

    public function test_another_teams_store_cannot_be_reached_through_the_query_string(): void
    {
        $this->merchant();
        $theirs = Store::factory()->create(['team_id' => Team::factory()->create()->id, 'name' => 'Theirs']);

        Livewire::withQueryParams(['store' => $theirs->id])
            ->test(StoreSettings::class)
            ->assertSchemaStateSet(['name' => 'Acme']);
    }

    public function test_a_team_with_two_stores_puts_a_new_product_in_the_chosen_one(): void
    {
        [, $team] = $this->merchant();
        $second = Store::factory()->create(['team_id' => $team->id, 'name' => 'Second']);
        Gate::before(fn () => true);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'store_id' => $second->id,
                'name' => 'Rain Jacket',
                'slug' => 'rain-jacket',
                'sku' => 'RJ-1',
                'pricing_type' => 'fixed',
                'price' => 120,
                'sale_price' => 99,
                'inventory_count' => 4,
                'low_stock_threshold' => 1,
                'status' => Product::STATUS_DRAFT,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutGlobalScopes()->where('sku', 'RJ-1')->sole();
        $this->assertSame($second->id, $product->store_id);
        $this->assertSame(Product::STATUS_DRAFT, $product->status);
        $this->assertSame(99.0, $product->sellingPrice());
        $this->assertSame(4, $product->inventory_count);
    }

    public function test_the_product_form_validates_sale_price_and_sku(): void
    {
        [, , $store] = $this->merchant();
        Gate::before(fn () => true);
        $existing = Product::factory()->create(['sku' => 'TAKEN']);
        $existing->forceFill(['store_id' => $store->id])->save();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Boots', 'slug' => 'boots', 'sku' => 'TAKEN', 'pricing_type' => 'fixed',
                'price' => 50, 'sale_price' => 60, 'inventory_count' => 1, 'low_stock_threshold' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['sku', 'sale_price']);
    }

    public function test_a_merchant_can_create_a_category_from_the_product_form(): void
    {
        [, , $store] = $this->merchant();
        Gate::before(fn () => true);

        Livewire::test(CreateProduct::class)
            ->callFormComponentAction('category_id', 'createOption', data: ['name' => 'Waterproofs']);

        $category = ProductCategory::withoutGlobalScopes()->where('name', 'Waterproofs')->sole();
        $this->assertSame('waterproofs', $category->slug);
        $this->assertSame($store->id, $category->store_id);
    }

    public function test_a_merchant_manages_their_own_shipping_methods(): void
    {
        [, , $store] = $this->merchant();
        $theirs = Store::factory()->create(['team_id' => Team::factory()->create()->id]);
        $foreign = ShippingMethod::create(['name' => 'Their Courier', 'base_rate' => 1, 'estimated_delivery_time' => '1 day']);
        $foreign->forceFill(['store_id' => $theirs->id])->save();

        Livewire::test(ManageShippingMethods::class)
            ->assertCanNotSeeTableRecords([$foreign])
            ->callAction(CreateAction::class, data: [
                'name' => 'Royal Mail', 'base_rate' => 3.5, 'weight_rate' => 0, 'estimated_delivery_time' => '2 days', 'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($store->id, ShippingMethod::withoutGlobalScopes()->where('name', 'Royal Mail')->value('store_id'));
    }

    public function test_shipping_methods_are_offered_only_on_their_own_storefront(): void
    {
        // Before the second store exists, so these land in the main site's store.
        $product = Product::factory()->create(['inventory_count' => 5]);
        $this->withStoredCart([$product->id => ['price' => 10, 'quantity' => 1]]);
        $ours = ShippingMethod::create(['name' => 'Our Courier', 'base_rate' => 2, 'estimated_delivery_time' => '1 day', 'is_active' => true]);

        $other = Store::factory()->create();
        ChannelDomain::factory()->primary()->create([
            'channel_id' => Channel::factory()->create(['store_id' => $other->id])->id, 'host' => 'other.test',
        ]);
        $theirs = ShippingMethod::create(['name' => 'Other Courier', 'base_rate' => 2, 'estimated_delivery_time' => '1 day', 'is_active' => true]);
        $theirs->forceFill(['store_id' => $other->id])->save();

        $this->assertSame(Store::mainSite()->id, $ours->store_id);
        $this->get(route('checkout.initiate'))->assertOk()->assertSee('Our Courier')->assertDontSee('Other Courier');
    }

    public function test_the_platform_operator_controls_multiple_stores_and_the_main_site(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($admin->ownedTeams()->first());
        $flagship = Store::factory()->create(['name' => 'Flagship']);

        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['allow_multiple_stores_per_team' => true, 'main_store_id' => $flagship->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(app(GeneralSettings::class)->allow_multiple_stores_per_team);
        $this->assertTrue($flagship->servesMainSite());
    }

    public function test_the_settings_and_catalogue_screens_render(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $team = $admin->ownedTeams()->first();
        $store = Store::factory()->create(['team_id' => $team->id]);
        $product = Product::factory()->create();
        $product->forceFill(['store_id' => $store->id, 'team_id' => $team->id])->save();
        $this->actingAs($admin);
        Gate::before(fn () => true);

        $this->get("/app/{$team->id}/store-settings")->assertOk()->assertSee('Currency and region');
        $this->get("/app/{$team->id}/products/create")->assertOk()->assertSee('SKU');
        $this->get("/app/{$team->id}/products/{$product->slug}/edit")->assertOk()->assertSee('Sale price');
        $this->get("/app/{$team->id}/shipping-methods")->assertOk();
        $this->get('/admin/manage-general-settings')->assertOk()->assertSee('Store on the main site');
        $this->get("/admin/stores/{$store->id}/edit")->assertOk()->assertSee('Business address');
        $this->get('/admin/products')->assertOk()->assertSee($product->name);
    }

    public function test_payment_credentials_are_never_shown_in_settings(): void
    {
        config([
            'services.stripe.key' => 'pk_live_PUBLISHABLE', 'services.stripe.secret' => 'sk_live_TOPSECRET',
            'services.paypal.client_id' => 'paypal-client', 'services.paypal.secret' => 'paypal_TOPSECRET',
        ]);
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $team = $admin->ownedTeams()->first();
        Store::factory()->create(['team_id' => $team->id]);

        foreach (["/app/{$team->id}/store-settings", '/admin/manage-general-settings'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertSee('configured')
                ->assertDontSee('sk_live_TOPSECRET')
                ->assertDontSee('paypal_TOPSECRET');
        }
    }

    public function test_only_the_platform_operator_reaches_platform_settings(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->assertFalse(ManageGeneralSettings::canAccess());
    }
}
