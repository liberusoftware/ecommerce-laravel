<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Orders\Pages\CreateOrder;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\StoreContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/** The merchant's side: attributes in, combinations generated, each variant priced and stocked, saved through the service. */
class ProductVariantFormTest extends TestCase
{
    use BuildsVariants;
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        $this->store = Store::factory()->create(['team_id' => $team->id]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);
    }

    private function product(): array
    {
        return ['store_id' => $this->store->id, 'name' => 'Canvas Tote', 'slug' => 'canvas-tote', 'pricing_type' => 'fixed', 'price' => 18, 'inventory_count' => 0, 'low_stock_threshold' => 2];
    }

    private function generate()
    {
        return TestAction::make('generateVariants')->schemaComponent('variantActions');
    }

    /** Filament keys repeater rows its own way; find one by the variant it holds. */
    private function rowKey($page, ProductVariant $variant): string
    {
        return (string) collect($page->get('data.variants'))->search(fn (array $row) => (int) $row['id'] === $variant->id);
    }

    private function inStore(callable $build)
    {
        return StoreContext::writingTo($this->store->id, $build);
    }

    public function test_a_merchant_generates_every_combination_and_saves_each_variant(): void
    {
        $page = Livewire::test(CreateProduct::class)
            ->fillForm($this->product() + ['variant_attributes' => [
                ['name' => 'Colour', 'values' => ['Natural', 'Black']],
                ['name' => 'Size', 'values' => ['Small', 'Large']],
            ]])
            ->callAction($this->generate());

        $rows = $page->get('data.variants');
        $this->assertSame(['Natural / Small', 'Natural / Large', 'Black / Small', 'Black / Large'], array_column($rows, 'label'));
        $this->assertEquals([18, 18, 18, 18], array_column($rows, 'price'), 'New variants start at the product price.');

        $keys = array_keys($rows);
        $page->set("data.variants.{$keys[0]}.sku", 'TOTE-N-S')
            ->set("data.variants.{$keys[0]}.inventory_count", 7)
            ->set("data.variants.{$keys[3]}.price", 24)
            ->set("data.variants.{$keys[3]}.is_active", false)
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutGlobalScopes()->where('slug', 'canvas-tote')->sole();
        $this->assertSame(['Natural / Small', 'Natural / Large', 'Black / Small', 'Black / Large'], $product->variants->map->label()->all());
        $this->assertSame(['TOTE-N-S', 7], [$product->variants[0]->sku, $product->variants[0]->inventory_count]);
        $this->assertSame([true, true, true, false], $product->variants->pluck('is_active')->all());
        $this->assertEquals(18, $product->price);
        $this->assertSame([18.0, 18.0], $product->variantPriceRange(), 'An inactive variant is not on sale.');
    }

    public function test_a_merchant_sets_every_price_and_fills_empty_skus_in_one_go(): void
    {
        $page = Livewire::test(CreateProduct::class)
            ->fillForm($this->product() + ['sku' => 'TOTE', 'variant_attributes' => [
                ['name' => 'Colour', 'values' => ['Natural', 'Black']],
                ['name' => 'Size', 'values' => ['Small', 'Large']],
            ]])
            ->callAction($this->generate());
        $keys = array_keys($page->get('data.variants'));
        $page->set("data.variants.{$keys[1]}.sku", 'KEEP-ME')
            ->set("data.variants.{$keys[3]}.is_active", false)
            ->callAction(TestAction::make('setVariantPrices')->schemaComponent('variantActions'), ['price' => 21.5])
            ->callAction(TestAction::make('fillVariantSkus')->schemaComponent('variantActions'));

        $rows = $page->get('data.variants');
        $this->assertEquals([21.5, 21.5, 21.5, 21.5], array_column($rows, 'price'));
        $this->assertSame(['TOTE-NATURAL-SMALL', 'KEEP-ME', 'TOTE-BLACK-SMALL', 'TOTE-BLACK-LARGE'], array_column($rows, 'sku'));
        $page->assertSee('4 variants, 3 on sale');

        $page->call('create')->assertHasNoFormErrors();
        $product = Product::withoutGlobalScopes()->where('slug', 'canvas-tote')->sole();
        $this->assertSame(['TOTE-NATURAL-SMALL', 'KEEP-ME', 'TOTE-BLACK-SMALL', 'TOTE-BLACK-LARGE'], $product->variants->pluck('sku')->all());
        $this->assertSame([21.5, 21.5], $product->variantPriceRange());
    }

    public function test_regenerating_keeps_what_the_merchant_already_entered(): void
    {
        $page = Livewire::test(CreateProduct::class)
            ->fillForm($this->product() + ['variant_attributes' => [['name' => 'Colour', 'values' => ['Natural']]]])
            ->callAction($this->generate());
        $key = array_key_first($page->get('data.variants'));
        $page->set("data.variants.{$key}.sku", 'KEEP-ME')
            ->set('data.variant_attributes', [['name' => 'Colour', 'values' => ['Natural', 'Black']]])
            ->callAction($this->generate());

        $rows = $page->get('data.variants');
        $this->assertSame('KEEP-ME', $rows[$key]['sku']);
        $this->assertSame(['Natural', 'Black'], array_column($rows, 'label'));
    }

    public function test_editing_shows_the_variants_and_keeps_their_stock(): void
    {
        $product = $this->inStore(fn () => $this->variableProduct(['Colour' => ['Natural', 'Black']], [
            $this->row(['Colour' => 'Natural'], 18, 5, ['sku' => 'TOTE-N']),
            $this->row(['Colour' => 'Black'], 20, 1, ['sku' => 'TOTE-B']),
        ], ['slug' => 'tote']));
        $black = $this->variantOf($product, 'Black');

        $page = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldHidden('price')
            ->assertFormFieldHidden('inventory_count')
            ->assertFormFieldHidden('track_inventory');

        $this->assertSame(['TOTE-N', 'TOTE-B'], array_column($page->get('data.variants'), 'sku'));
        $key = $this->rowKey($page, $black);
        $page->set("data.variants.{$key}.price", 22)
            ->set("data.variants.{$key}.inventory_count", 500)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(22, $black->fresh()->price);
        $this->assertSame(1, $black->fresh()->inventory_count, 'Existing stock changes only through logged adjustments.');
    }

    public function test_removing_a_row_removes_that_variant_but_keeps_its_history(): void
    {
        $product = $this->inStore(fn () => $this->variableProduct(['Colour' => ['Natural', 'Black']], [
            $this->row(['Colour' => 'Natural'], 18), $this->row(['Colour' => 'Black'], 20),
        ], ['slug' => 'tote']));
        $black = $this->variantOf($product, 'Black');

        $page = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $rows = $page->get('data.variants');
        unset($rows[$this->rowKey($page, $black)]);
        $page->set('data.variants', $rows)->call('save')->assertHasNoFormErrors();

        $this->assertSoftDeleted($black);
        $this->assertSame(['Natural'], $product->variants()->get()->map->label()->all());
    }

    public function test_a_variant_sku_already_in_the_store_is_refused_on_the_row(): void
    {
        $this->inStore(fn () => Product::factory()->create(['sku' => 'TAKEN']));

        $page = Livewire::test(CreateProduct::class)
            ->fillForm($this->product() + ['variant_attributes' => [['name' => 'Colour', 'values' => ['Natural']]]])
            ->callAction($this->generate());
        $key = array_key_first($page->get('data.variants'));

        $page->set("data.variants.{$key}.sku", 'TAKEN')
            ->call('create')
            ->assertHasErrors(["data.variants.{$key}.sku"]);

        $this->assertSame(0, Product::withoutGlobalScopes()->where('slug', 'canvas-tote')->count(), 'Nothing is saved when a variant is refused.');
    }

    public function test_a_simple_product_keeps_the_simple_form(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['sku' => 'TOTE', 'inventory_count' => 4] + $this->product())
            ->assertFormFieldVisible('price')
            ->assertFormFieldVisible('inventory_count')
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutGlobalScopes()->where('slug', 'canvas-tote')->sole();
        $this->assertFalse($product->hasVariants());
        $this->assertSame(['TOTE', 4], [$product->sku, $product->inventory_count]);
        $this->assertEquals(18, $product->price);
    }

    public function test_stock_adjustment_on_the_product_list_names_a_variant(): void
    {
        Repeater::fake();
        $product = $this->inStore(fn () => $this->variableProduct(['Colour' => ['Natural', 'Black']], [
            $this->row(['Colour' => 'Natural'], 18, 5), $this->row(['Colour' => 'Black'], 20, 1),
        ]));
        $black = $this->variantOf($product, 'Black');

        Livewire::test(ListProducts::class)
            ->callAction(TestAction::make('adjustInventory')->table($product), ['adjustment' => 4, 'reason' => 'delivery'])
            ->assertHasFormErrors(['variant_id' => 'required']);

        Livewire::test(ListProducts::class)
            ->callAction(TestAction::make('adjustInventory')->table($product), ['variant_id' => $black->id, 'adjustment' => 4, 'reason' => 'delivery'])
            ->assertHasNoFormErrors();

        $this->assertSame(5, $black->fresh()->inventory_count);
        $this->assertSame($black->id, InventoryLog::sole()->product_variant_id);
    }

    public function test_the_product_list_shows_the_price_range_and_stock_across_variants(): void
    {
        $this->inStore(fn () => $this->variableProduct(['Colour' => ['Natural', 'Black']], [
            $this->row(['Colour' => 'Natural'], 18, 5), $this->row(['Colour' => 'Black'], 20, 1),
        ]));

        Livewire::test(ListProducts::class)
            ->assertSee('6 across 2 variants')
            ->assertSee('–');
    }

    public function test_a_manual_order_takes_a_variant_and_reserves_its_stock(): void
    {
        Repeater::fake();
        $product = $this->inStore(fn () => $this->variableProduct(['Colour' => ['Natural', 'Black']], [
            $this->row(['Colour' => 'Natural'], 18, 5, ['sku' => 'TOTE-N']), $this->row(['Colour' => 'Black'], 20, 1),
        ]));
        $customer = $this->inStore(fn () => Customer::factory()->create());
        $natural = $this->variantOf($product, 'Natural');

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'store_id' => $this->store->id,
                'customer_id' => $customer->id,
                'order_date' => now()->toDateString(),
                'shipping_status' => 'pending',
                'items' => [['product_id' => $product->id, 'product_variant_id' => $natural->id, 'quantity' => 2]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $line = Order::withoutGlobalScopes()->sole()->items()->sole();
        $this->assertSame([$natural->id, 'TOTE-N', 'Colour: Natural'], [$line->product_variant_id, $line->sku, $line->variantLabel()]);
        $this->assertEquals(18, $line->price);
        $this->assertSame(3, $natural->fresh()->inventory_count);
        $this->assertInstanceOf(ProductVariant::class, $line->variant);
    }
}
