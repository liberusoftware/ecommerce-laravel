<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductOptionChoice;
use App\Models\ProductOptionGroup;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\ProductOptionResolver;
use App\Services\StoreContext;
use Database\Seeders\DummyData\DemoCatalogue;
use Database\Seeders\DummyData\DummyDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The demo catalogue shows variable and simple products side by side, with product options, and seeding it again changes nothing. */
class DemoVariantCatalogueSeederTest extends TestCase
{
    use RefreshDatabase;

    private array $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->catalogue = DemoCatalogue::load();
        $this->seed(DummyDataSeeder::class);
    }

    private function product(string $slug): Product
    {
        return Product::withoutGlobalScopes()->where('slug', $slug)->where('store_id', DemoCatalogue::storeId())->with(['variants', 'optionGroups.choices'])->sole();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'products' => Product::withoutGlobalScopes()->withTrashed()->count(),
            'variants' => ProductVariant::withoutGlobalScopes()->withTrashed()->count(),
            'groups' => ProductOptionGroup::withoutGlobalScopes()->count(),
            'choices' => ProductOptionChoice::count(),
            'attachments' => DB::table('product_option_group_product')->count(),
        ];
    }

    public function test_the_catalogue_has_both_simple_and_variable_products(): void
    {
        $products = Product::withoutGlobalScopes()->with('variants')->get();

        $variable = $products->filter->hasVariants();
        $this->assertEqualsCanonicalizing(array_keys($this->catalogue['variants']), $variable->pluck('slug')->all());
        $this->assertCount(7, $variable);
        $this->assertGreaterThan(40, $products->count() - $variable->count());
    }

    public function test_each_variable_product_has_every_combination_of_its_attributes(): void
    {
        foreach ($this->catalogue['variants'] as $slug => $spec) {
            $product = $this->product($slug);
            $expected = array_product(array_map('count', $spec['attributes']));

            $this->assertCount($expected, $product->variants, "{$slug} is missing combinations.");
            $this->assertLessThanOrEqual(100, $expected);
            $this->assertSame(array_keys($spec['attributes']), array_column($product->variant_attributes, 'name'));
        }

        $phone = $this->product('aurora-6-smartphone-128gb');
        $this->assertSame(['Storage', 'Colour'], array_column($phone->variant_attributes, 'name'));
        $this->assertSame('128 GB / Graphite', $phone->variants->first()->label());
        $this->assertCount(12, $this->product('organic-cotton-crew-tshirt')->variants);
        $this->assertCount(10, $this->product('wrap-midi-dress')->variants);
    }

    public function test_variant_skus_are_unique_and_prices_and_stock_vary(): void
    {
        $skus = ProductVariant::withoutGlobalScopes()->pluck('sku');
        $this->assertSame($skus->count(), $skus->unique()->count());
        $this->assertEmpty($skus->intersect(Product::withoutGlobalScopes()->pluck('sku')));
        $this->assertNotContains(null, $skus->all());

        $phone = $this->product('aurora-6-smartphone-128gb');
        $this->assertSame('DEMO-001-256GB-SEABLU', $phone->variants->first(fn ($v) => $v->label() === '256 GB / Sea Blue')->sku);
        $this->assertEquals([649, 749, 899], $phone->variants->pluck('price')->map(fn ($p) => (float) $p)->unique()->sort()->values()->all());
        $this->assertGreaterThan(2, $phone->variants->pluck('inventory_count')->unique()->count());
        $this->assertSame([649.0, 899.0], $phone->variantPriceRange());
        $this->assertEquals(649, $phone->price, 'The product price is kept at the lowest variant price for listings.');

        $variants = ProductVariant::withoutGlobalScopes()->get();
        $this->assertTrue($variants->every->tracksInventory());
        $this->assertTrue($variants->contains(fn ($v) => $v->inventory_count === 0), 'Some variant shows the sold-out state.');
        $this->assertTrue($variants->contains(fn ($v) => ! $v->is_active), 'Some variant shows the unavailable state.');
        $this->assertFalse($this->product('wrap-midi-dress')->variants->first(fn ($v) => $v->label() === '16 / Terracotta')->is_active);
    }

    public function test_the_book_is_sold_in_formats_each_with_its_own_sku_and_price(): void
    {
        $book = $this->product('the-weeknight-kitchen');

        $this->assertSame(['Paperback', 'Hardcover'], $book->variants->map->label()->all());
        $this->assertEquals([16.99, 29.99], $book->variants->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertSame(['DEMO-023-PAPERB', 'DEMO-023-HARDCO'], $book->variants->pluck('sku')->all());
    }

    public function test_product_options_are_reusable_store_groups_attached_to_suitable_products(): void
    {
        $storeId = DemoCatalogue::storeId();
        $this->assertTrue(ProductOptionGroup::withoutGlobalScopes()->get()->every(fn ($g) => $g->store_id === $storeId));

        $protection = $this->product('aurora-6-smartphone-128gb')->optionGroups->sole();
        $this->assertSame(['Phone protection', 0, null], [$protection->name, $protection->min_select, $protection->max_select]);
        $this->assertEquals(['Protective case' => 24, 'Tempered-glass screen protector' => 12], $protection->choices->pluck('price_adjustment', 'name')->map(fn ($p) => (float) $p)->all());

        $warranty = $this->product('nimbus-14-laptop')->optionGroups->sole();
        $this->assertTrue($warranty->isRequired());
        $this->assertTrue($warranty->allowsOne());
        $this->assertSame('1-year manufacturer warranty', $warranty->choices->firstWhere('is_default', true)->name);
        $this->assertFalse($this->product('nimbus-14-laptop')->hasVariants(), 'A simple product with options.');
        $this->assertSame($warranty->id, $this->product('aurora-tab-11-tablet')->optionGroups->sole()->id, 'One group, reused.');

        $lamp = $this->product('ceramic-table-lamp');
        $this->assertTrue($lamp->hasVariants());
        $this->assertSame(['Gift wrapping'], $lamp->optionGroups->pluck('name')->all(), 'Variants and options together.');
        $this->assertSame(['Assembly service'], $this->product('arden-oak-lounge-chair')->optionGroups->pluck('name')->all());
        $this->assertCount(6, ProductOptionGroup::withoutGlobalScopes()->where('name', 'Gift wrapping')->sole()->products);
    }

    public function test_a_demo_variant_with_an_option_is_priced_by_the_resolver(): void
    {
        $phone = $this->product('aurora-6-smartphone-128gb');
        $silver = $phone->variants->first(fn ($v) => $v->label() === '256 GB / Silver');
        $case = $phone->optionGroups->sole()->choices->firstWhere('name', 'Protective case');

        $configuration = app(ProductOptionResolver::class)->resolve($phone, [$case->id], $silver->id);

        $this->assertEquals(773.00, $configuration->unitPrice);
    }

    public function test_simple_demo_products_are_unchanged(): void
    {
        foreach ($this->catalogue['products'] as $index => $item) {
            if (isset($this->catalogue['variants'][$item['slug']])) {
                continue;
            }

            $product = $this->product($item['slug']);
            $this->assertFalse($product->hasVariants(), "{$item['slug']} should be simple.");
            $this->assertEquals($item['price'], $product->price, "{$item['slug']} price changed.");
            $this->assertSame([$item['stock'], sprintf('DEMO-%03d', $index + 1), $item['name']], [$product->inventory_count, $product->sku, $product->name]);
        }
    }

    public function test_seeding_again_creates_no_duplicates(): void
    {
        $before = $this->counts();

        $this->seed(DummyDataSeeder::class);
        $this->seed(DummyDataSeeder::class);

        $this->assertSame($before, $this->counts());
    }

    public function test_seeding_again_keeps_the_merchants_changes_to_variants_and_options(): void
    {
        $phone = $this->product('aurora-6-smartphone-128gb');
        $phone->variants->first()->update(['price' => 599]);
        $phone->variants->last()->delete();
        $phone->optionGroups()->detach();
        ProductOptionChoice::where('name', 'Gift wrap')->update(['price_adjustment' => 2.50]);

        $this->seed(DummyDataSeeder::class);

        $phone = $this->product('aurora-6-smartphone-128gb');
        $this->assertEquals(599, $phone->variants->first()->price);
        $this->assertCount(8, $phone->variants);
        $this->assertEmpty($phone->optionGroups);
        $this->assertEquals(2.50, ProductOptionChoice::where('name', 'Gift wrap')->sole()->price_adjustment);
    }

    public function test_only_the_demo_store_is_seeded(): void
    {
        $demoStore = DemoCatalogue::storeId();
        $other = Store::factory()->create();
        $theirDress = StoreContext::writingTo($other->id, fn () => Product::factory()->create(['slug' => 'wrap-midi-dress']));
        StoreContext::writingTo($other->id, fn () => ProductOptionGroup::create(['name' => 'Assembly service', 'min_select' => 0, 'max_select' => 1]));

        // Take two things out of the demo store, so the seeders have something to put back.
        $ourDress = $this->product('wrap-midi-dress');
        ProductVariant::withoutGlobalScopes()->where('product_id', $ourDress->id)->forceDelete();
        $ourDress->forceFill(['variant_attributes' => null])->save();
        ProductOptionGroup::withoutGlobalScopes()->where('store_id', $demoStore)->where('name', 'Assembly service')->delete();

        $this->seed(DummyDataSeeder::class);

        $this->assertCount(10, $this->product('wrap-midi-dress')->variants);
        $this->assertSame(0, ProductVariant::withoutGlobalScopes()->where('product_id', $theirDress->id)->count());
        $this->assertSame(['Assembly service'], $this->product('arden-oak-lounge-chair')->optionGroups->pluck('name')->all());
        $this->assertSame(0, ProductOptionGroup::withoutGlobalScopes()->where('store_id', $other->id)->sole()->products()->count());
    }
}
