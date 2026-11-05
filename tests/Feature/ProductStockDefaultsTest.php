<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Interfaces\PaymentGatewayInterface;
use App\Livewire\ShoppingCart;
use App\Models\CartItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\PaymentGateways\StripeGateway;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Whether stock is counted follows what the product is, unless the merchant
 * says otherwise: a physical product is tracked, a download is not.
 */
class ProductStockDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function physical(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + ['name' => 'Mug', 'price' => 12, 'inventory_count' => 0]);
    }

    private function download(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + ['name' => 'Ebook', 'price' => 9, 'inventory_count' => 0, 'is_downloadable' => true]);
    }

    public function test_a_physical_product_is_tracked_and_a_download_is_not(): void
    {
        $this->assertTrue($this->physical()->fresh()->track_inventory);
        $this->assertFalse($this->download()->fresh()->track_inventory);
    }

    public function test_an_explicit_choice_overrides_the_default(): void
    {
        $this->assertTrue($this->download(['track_inventory' => true])->fresh()->track_inventory);
        $this->assertFalse($this->physical(['track_inventory' => false])->fresh()->track_inventory);
    }

    public function test_a_stored_choice_is_never_changed_behind_the_merchants_back(): void
    {
        $product = $this->physical();

        $product->update(['is_downloadable' => true]);
        $this->assertTrue($product->fresh()->track_inventory);

        $product->update(['track_inventory' => false]);
        $product->update(['is_downloadable' => false]);
        $this->assertFalse($product->fresh()->track_inventory);
    }

    public function test_an_unrelated_edit_keeps_the_merchants_choice(): void
    {
        $limited = $this->download(['track_inventory' => true, 'inventory_count' => 50]);

        $limited->update(['name' => 'Signed ebook', 'price' => 15]);

        $this->assertTrue($limited->fresh()->track_inventory);
    }

    public function test_an_unsaved_product_already_answers_by_its_kind(): void
    {
        $this->assertTrue((new Product)->tracksInventory());
        $this->assertFalse((new Product(['is_downloadable' => true]))->tracksInventory());
    }

    public function test_the_rest_api_applies_the_default_and_honours_an_explicit_value(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create()->assignRole('super_admin');
        Team::factory()->create(['id' => 1, 'user_id' => $admin->id]);
        $category = ProductCategory::factory()->create();
        $base = ['short_description' => 'x', 'price' => 5, 'category_id' => $category->id, 'pricing_type' => 'fixed'];

        $this->actingAs($admin, 'sanctum')->postJson('/api/products', $base + ['name' => 'Poster'])->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/products', $base + ['name' => 'Font', 'is_downloadable' => true])->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/products', $base + ['name' => 'Licence', 'is_downloadable' => true, 'track_inventory' => true])->assertCreated();

        $this->assertSame(['Poster' => true, 'Font' => false, 'Licence' => true], Product::orderBy('id')->pluck('track_inventory', 'name')->map(fn ($v) => (bool) $v)->all());
    }

    public function test_the_product_form_follows_the_downloadable_switch(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        $store = Store::factory()->create(['team_id' => $team->id]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);

        Livewire::test(CreateProduct::class)
            ->assertSchemaStateSet(['track_inventory' => true])
            ->fillForm(['is_downloadable' => true])
            ->assertSchemaStateSet(['track_inventory' => false])
            ->fillForm(['track_inventory' => true])
            ->assertSchemaStateSet(['is_downloadable' => true, 'track_inventory' => true])
            ->fillForm(['is_downloadable' => false])
            ->assertSchemaStateSet(['track_inventory' => true]);

        // Converting an existing product: the toggle follows, visibly, and the
        // merchant may turn tracking back on before saving.
        $mug = Product::factory()->create(['store_id' => $store->id, 'team_id' => $team->id, 'inventory_count' => 5]);
        Livewire::test(EditProduct::class, ['record' => $mug->getRouteKey()])
            ->fillForm(['is_downloadable' => true])
            ->assertSchemaStateSet(['track_inventory' => false])
            ->fillForm(['track_inventory' => true])
            ->assertSchemaStateSet(['is_downloadable' => true, 'track_inventory' => true]);
    }

    public function test_every_cart_door_sells_a_download_without_stock_and_refuses_a_physical_product_without_it(): void
    {
        $ebook = $this->download();
        $mug = $this->physical();

        $this->post(route('cart.add', $ebook), ['quantity' => 3])->assertSessionHas('success');
        $this->post(route('cart.add', $mug))->assertSessionHas('error', 'Not enough inventory available.');
        Livewire::test(ShoppingCart::class)->call('addToCart', productId: $ebook->id, quantity: 2);
        Livewire::test(ShoppingCart::class)->call('addToCart', productId: $mug->id);
        $this->put(route('cart.update', CartItem::sole()->id), ['quantity' => 20])->assertSessionHas('success');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/cart', ['product_id' => $ebook->id, 'quantity' => 4])->assertCreated();
        $this->postJson('/api/cart', ['product_id' => $mug->id, 'quantity' => 1])->assertStatus(422);
        $gql = fn (Product $p) => $this->postJson('/api/graphql', [
            'query' => 'mutation($p: Int!) { addToCart(productId: $p, quantity: 1) { id } }', 'variables' => ['p' => $p->id],
        ])->json();
        $this->assertArrayNotHasKey('errors', $gql($ebook));
        $this->assertStringContainsString('exceeds available stock', $gql($mug)['errors'][0]['message']);

        $this->assertSame(0, CartItem::where('product_id', $mug->id)->count());
        $this->assertSame(25, (int) CartItem::where('product_id', $ebook->id)->sum('quantity'));
    }

    public function test_checkout_sells_a_download_without_stock_and_reserves_a_physical_product(): void
    {
        Notification::fake();
        $this->app->instance(StripeGateway::class, new class implements PaymentGatewayInterface
        {
            public function processPayment(float $amount, array $paymentDetails): array
            {
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
        });
        $ebook = $this->download(['downloadable_file' => 'downloadable_products/ebook.pdf']);
        $mug = $this->physical(['inventory_count' => 5]);
        $this->post(route('cart.add', $ebook), ['quantity' => 2]);
        $this->post(route('cart.add', $mug), ['quantity' => 2]);

        $this->post(route('checkout.process'), [
            'email' => 'buyer@example.com', 'has_physical_products' => 1, 'shipping_address' => '1 High St',
            'shipping_method_id' => ShippingMethod::create(['name' => 'Post', 'base_rate' => 0])->id,
            'country' => 'US', 'payment_method' => 'stripe', 'stripeToken' => 'tok_test',
        ])->assertSessionHas('success');

        $this->assertSame(Order::STATUS_PAID, Order::sole()->status);
        $this->assertSame(0, $ebook->fresh()->inventory_count);
        $this->assertSame(3, $mug->fresh()->inventory_count);
        $this->assertSame([$mug->id], InventoryLog::pluck('product_id')->all());
        $this->assertNotNull(Order::sole()->items()->where('product_id', $ebook->id)->value('download_link'));
    }

    public function test_stock_adjustments_apply_to_tracked_products_only(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $this->actingAs(User::factory()->create()->assignRole('super_admin'));
        $mug = $this->physical(['inventory_count' => 2]);
        $ebook = $this->download();
        $limited = $this->download(['track_inventory' => true, 'inventory_count' => 10]);

        $adjust = fn (Product $p, int $change) => $this->postJson(route('inventory.adjust'), ['product_id' => $p->id, 'quantity_change' => $change, 'reason' => 'count']);

        $adjust($mug, 3)->assertOk();
        $adjust($limited, -4)->assertOk();
        $adjust($ebook, 5)->assertStatus(422)->assertJsonPath('message', "This product's stock is not tracked.");

        $this->assertSame([5, 6, 0], [$mug->fresh()->inventory_count, $limited->fresh()->inventory_count, $ebook->fresh()->inventory_count]);
        $this->assertEqualsCanonicalizing([$mug->id, $limited->id], InventoryLog::pluck('product_id')->all());
    }
}
