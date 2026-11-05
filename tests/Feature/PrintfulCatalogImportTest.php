<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Printful\PrintfulCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/** The Printful store becomes made-to-order products whose variants carry Printful's ids. */
class PrintfulCatalogImportTest extends TestCase
{
    use BuildsVariants;
    use RefreshDatabase;

    private array $variants;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dropshipping.suppliers.printful.auth.key' => 'Bearer pf_test']);

        $this->variants = [
            ['id' => 501, 'name' => 'Logo Hoodie / Black / M', 'sku' => 'HD-BLK-M', 'retail_price' => '45.00', 'files' => [['type' => 'preview', 'preview_url' => 'https://files.cdn.printful.com/black.png']]],
            ['id' => 502, 'name' => 'Logo Hoodie / Black / 2XL', 'sku' => 'HD-BLK-2XL', 'retail_price' => '48.00', 'files' => [['type' => 'preview', 'preview_url' => 'https://files.cdn.printful.com/black.png']]],
            ['id' => 503, 'name' => 'Logo Hoodie / Sand / M', 'sku' => 'HD-SND-M', 'retail_price' => '45.00', 'files' => [['type' => 'preview', 'preview_url' => 'https://files.cdn.printful.com/sand.png']]],
        ];
        $this->fakePrintful();
    }

    private function fakePrintful(): void
    {
        Http::fake([
            'api.printful.com/store/products/90' => fn () => Http::response(['result' => [
                'sync_product' => ['id' => 90, 'name' => 'Logo Hoodie', 'thumbnail_url' => 'https://files.cdn.printful.com/thumb.png'],
                'sync_variants' => $this->variants,
            ]]),
            'api.printful.com/store/products*' => Http::response(['result' => [['id' => 90, 'name' => 'Logo Hoodie']]]),
        ]);
    }

    public function test_the_store_imports_as_made_to_order_variants_priced_and_mapped_from_printful(): void
    {
        $this->artisan('printful:import-products')->assertSuccessful();

        $hoodie = Product::sole();
        $this->assertSame(['printful', '90', false, 45.0], [$hoodie->supplier_id, $hoodie->supplier_product_id, $hoodie->tracksInventory(), (float) $hoodie->price]);
        $this->assertSame(['Colour', 'Size'], array_column($hoodie->variant_attributes, 'name'));
        $this->assertSame('502', $this->variantOf($hoodie, 'Black / 2XL')->supplier_variant_id);
        $this->assertEquals(48.0, $this->variantOf($hoodie, 'Black / 2XL')->price, 'the larger size keeps its own price');
        $this->assertTrue($this->variantOf($hoodie, 'Sand / M')->isPurchasable(), 'made to order, so always in stock');
        $this->assertSame(['https://files.cdn.printful.com/black.png', 'https://files.cdn.printful.com/sand.png'], $hoodie->images()->orderBy('order')->pluck('image')->all());
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer pf_test'));
    }

    public function test_a_reimport_keeps_each_variant_and_retires_one_printful_dropped(): void
    {
        $this->artisan('printful:import-products')->assertSuccessful();
        $hoodie = Product::sole();
        $black = $this->variantOf($hoodie, 'Black / M');

        array_pop($this->variants);
        $this->artisan('printful:import-products')->assertSuccessful();

        $this->assertSame(1, Product::count());
        $this->assertSame($black->id, $this->variantOf($hoodie->fresh(), 'Black / M')->id);
        $this->assertSame(['Black / M', 'Black / 2XL'], $hoodie->fresh()->variants->map->label()->all());
        $this->assertSame(2, $hoodie->images()->count(), 'no duplicate mockups');
    }

    public function test_a_printful_product_can_be_mapped_onto_an_existing_product(): void
    {
        $mine = $this->variableProduct(['Colour' => ['Black'], 'Size' => ['M']], [
            $this->row(['Colour' => 'Black', 'Size' => 'M'], 40, 3, ['sku' => 'OLD-M']),
        ], ['name' => 'Our hoodie', 'slug' => 'our-hoodie']);
        $kept = $this->variantOf($mine, 'Black / M');

        app(PrintfulCatalogImporter::class)->importProduct(90, $mine);

        $mine = $mine->fresh();
        $this->assertSame(['Our hoodie', 'printful', 1], [$mine->name, $mine->supplier_id, Product::count()]);
        $this->assertSame($kept->id, $this->variantOf($mine, 'Black / M')->id, 'matched by combination');
        $this->assertSame('501', $this->variantOf($mine, 'Black / M')->supplier_variant_id);
    }

    public function test_names_without_the_product_still_give_colour_and_size_and_rules_file_the_product(): void
    {
        config(['dropshipping.suppliers.printful.categories' => [
            'Headwear' => ['keywords' => ['cap', 'beanie']],
            'Apparel' => ['keywords' => ['hoodie', 'tee'], 'description' => 'Layers and staples.'],
        ]]);
        $this->variants = [['id' => 601, 'name' => 'Black / M', 'sku' => 'H-B-M', 'retail_price' => '40.00']];

        $this->artisan('printful:import-products')->assertSuccessful();

        $hoodie = Product::sole();
        $this->assertSame('Black / M', $hoodie->variants()->sole()->label());
        $this->assertSame(['Apparel', 'Layers and staples.'], [$hoodie->category->name, $hoodie->category->description]);
    }

    public function test_without_a_token_the_import_does_nothing(): void
    {
        config(['dropshipping.suppliers.printful.auth.key' => 'Bearer ']);

        $this->artisan('printful:import-products')->expectsOutputToContain('skipped')->assertSuccessful();

        Http::assertNothingSent();
    }
}
