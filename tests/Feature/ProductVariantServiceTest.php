<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\ProductVariantService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/**
 * Variation attributes, the combinations they make, and how a product's
 * variants are written: every rule here applies to the form and the API alike.
 */
class ProductVariantServiceTest extends TestCase
{
    use BuildsVariants;
    use RefreshDatabase;

    private function service(): ProductVariantService
    {
        return app(ProductVariantService::class);
    }

    /** @return array<string, string> field => first message */
    private function errorsOf(callable $write): array
    {
        try {
            $write();
        } catch (ValidationException $e) {
            return array_map(fn (array $messages) => $messages[0], $e->errors());
        }

        $this->fail('Expected the write to be refused.');
    }

    public function test_one_attribute_makes_one_variant_per_value(): void
    {
        $combinations = $this->service()->combinations($this->attributes(['Size' => ['S', 'M', 'L']]));

        $this->assertSame([[['name' => 'Size', 'value' => 'S']], [['name' => 'Size', 'value' => 'M']], [['name' => 'Size', 'value' => 'L']]], $combinations);
    }

    public function test_several_attributes_make_every_combination_in_attribute_order(): void
    {
        $combinations = $this->service()->combinations($this->attributes(['Storage' => ['128 GB', '256 GB'], 'Colour' => ['Black', 'White', 'Blue']]));

        $this->assertCount(6, $combinations);
        $this->assertSame(['128 GB / Black', '128 GB / White', '128 GB / Blue', '256 GB / Black', '256 GB / White', '256 GB / Blue'],
            array_map([ProductVariant::class, 'labelFor'], $combinations));
    }

    public function test_attributes_are_trimmed_and_their_values_de_duplicated(): void
    {
        $this->assertSame(
            [['name' => 'Colour', 'values' => ['Black', 'white']]],
            $this->service()->normaliseAttributes([['name' => ' Colour ', 'values' => ['Black', ' white', 'White ', 'black', '']], ['name' => '', 'values' => []]]),
        );

        $this->assertArrayHasKey('variant_attributes.1.name', $this->errorsOf(fn () => $this->service()->normaliseAttributes(
            [['name' => 'Colour', 'values' => ['Black']], ['name' => 'colour', 'values' => ['Red']]],
        )));
    }

    public function test_generating_keeps_rows_already_listed_and_defaults_new_ones(): void
    {
        $rows = ['row-a' => $this->row(['Colour' => 'Black'], 25, 4, ['id' => 7, 'sku' => 'TEE-BLK'])];

        $generated = $this->service()->generate($this->attributes(['Colour' => ['Black', 'White']]), $rows, ['price' => 20, 'track_inventory' => true]);

        $this->assertSame(['row-a', 'new-1'], array_keys($generated));
        $this->assertSame([7, 'TEE-BLK', 25.0, 4], [$generated['row-a']['id'], $generated['row-a']['sku'], (float) $generated['row-a']['price'], $generated['row-a']['inventory_count']]);
        $this->assertSame(['White', 20, 0, true], [$generated['new-1']['label'], $generated['new-1']['price'], $generated['new-1']['inventory_count'], $generated['new-1']['is_active']]);
    }

    public function test_generating_refuses_more_combinations_than_a_product_can_have(): void
    {
        $values = array_map('strval', range(1, 11));

        $this->assertArrayHasKey('variant_attributes', $this->errorsOf(fn () => $this->service()->generate($this->attributes(['A' => $values, 'B' => $values]), [])));
    }

    public function test_syncing_creates_the_variants_and_prices_the_product_from_the_cheapest_on_sale(): void
    {
        $product = $this->variableProduct(['Storage' => ['128 GB', '256 GB']], [
            $this->row(['Storage' => '128 GB'], 799, 5, ['sku' => 'PH-128']),
            $this->row(['Storage' => '256 GB'], 899, 2, ['sku' => 'PH-256']),
        ], ['price' => 1, 'sale_price' => 0.5]);

        $this->assertTrue($product->hasVariants());
        $this->assertSame(['128 GB', '256 GB'], $product->variants->map->label()->all());
        $this->assertSame([5, 2], $product->variants->pluck('inventory_count')->all());
        $this->assertSame($product->store_id, $product->variants->first()->store_id);
        $this->assertEquals(799, $product->price);
        $this->assertNull($product->sale_price);
        $this->assertSame([799.0, 899.0], $product->variantPriceRange());
        $this->assertSame([['name' => 'Storage', 'values' => ['128 GB', '256 GB']]], $product->variant_attributes);
    }

    public function test_a_combination_is_listed_once_whatever_its_case_or_order(): void
    {
        $product = Product::factory()->create();
        $attributes = $this->attributes(['Size' => ['S'], 'Colour' => ['Red']]);

        $errors = $this->errorsOf(fn () => $this->service()->sync($product, $attributes, [
            $this->row(['Size' => 'S', 'Colour' => 'Red'], 10),
            $this->row(['colour' => 'red', 'size' => 's'], 12),
        ]));

        $this->assertStringContainsString('listed more than once', $errors['variants.1.attribute_values']);
        $this->assertSame(0, ProductVariant::count());
    }

    public function test_a_variant_needs_exactly_one_listed_value_for_every_attribute(): void
    {
        $product = Product::factory()->create();
        $attributes = $this->attributes(['Size' => ['S', 'M'], 'Colour' => ['Red']]);

        $this->assertArrayHasKey('variants.0.attribute_values', $this->errorsOf(fn () => $this->service()->sync($product, $attributes, [$this->row(['Size' => 'S'], 10)])));
        $this->assertArrayHasKey('variants.0.attribute_values', $this->errorsOf(fn () => $this->service()->sync($product, $attributes, [$this->row(['Size' => 'XL', 'Colour' => 'Red'], 10)])));
    }

    public function test_prices_must_be_present_and_not_negative(): void
    {
        $product = Product::factory()->create();

        $errors = $this->errorsOf(fn () => $this->service()->sync($product, $this->attributes(['Size' => ['S', 'M']]), [
            $this->row(['Size' => 'S'], -1),
            ['attribute_values' => [['name' => 'Size', 'value' => 'M']], 'price' => null],
        ]));

        $this->assertArrayHasKey('variants.0.price', $errors);
        $this->assertArrayHasKey('variants.1.price', $errors);
    }

    public function test_skus_are_unique_in_a_store_across_products_and_variants(): void
    {
        Product::factory()->create(['sku' => 'MUG-1']);
        $this->variableProduct(['Size' => ['S']], [$this->row(['Size' => 'S'], 10, 1, ['sku' => 'TEE-S'])]);
        $product = Product::factory()->create();
        $attributes = $this->attributes(['Size' => ['S', 'M', 'L']]);

        $errors = $this->errorsOf(fn () => $this->service()->sync($product, $attributes, [
            $this->row(['Size' => 'S'], 10, 1, ['sku' => 'MUG-1']),
            $this->row(['Size' => 'M'], 10, 1, ['sku' => 'TEE-S']),
            $this->row(['Size' => 'L'], 10, 1, ['sku' => 'tee-s']),
        ]));

        $this->assertStringContainsString('already has this SKU', $errors['variants.0.sku']);
        $this->assertStringContainsString('already has this SKU', $errors['variants.1.sku']);
        $this->assertStringContainsString('Two variants', $errors['variants.2.sku']);
    }

    public function test_another_store_may_use_the_same_sku(): void
    {
        $this->variableProduct(['Size' => ['S']], [$this->row(['Size' => 'S'], 10, 1, ['sku' => 'TEE-S'])]);
        $other = Store::factory()->create();

        $product = StoreContext::writingTo($other->id, fn () => $this->variableProduct(['Size' => ['S']], [$this->row(['Size' => 'S'], 10, 1, ['sku' => 'TEE-S'])]));

        $this->assertSame('TEE-S', ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->sole()->sku);
    }

    public function test_editing_a_variant_keeps_its_identity_and_stock(): void
    {
        $product = $this->variableProduct(['Size' => ['S', 'M']], [$this->row(['Size' => 'S'], 10, 3), $this->row(['Size' => 'M'], 10, 4)]);
        [$small, $medium] = $product->variants->all();

        // By id, and — for an API client that sends none — by combination; stock in the request is ignored.
        $this->service()->sync($product, $product->variant_attributes, [
            ['id' => $small->id] + $this->row(['Size' => 'S'], 12, 99, ['sku' => 'S-NEW']),
            $this->row(['Size' => 'M'], 14, 99),
        ]);

        $this->assertSame([$small->id, $medium->id], $product->variants()->pluck('id')->all());
        $this->assertEquals([12, 14], $product->variants()->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertSame([3, 4], $product->variants()->pluck('inventory_count')->all());
        $this->assertSame('S-NEW', $small->fresh()->sku);
    }

    public function test_variants_may_trade_skus_in_one_save(): void
    {
        $product = $this->variableProduct(['Size' => ['S', 'M']], [$this->row(['Size' => 'S'], 10, 1, ['sku' => 'A']), $this->row(['Size' => 'M'], 10, 1, ['sku' => 'B'])]);

        $this->service()->sync($product, $product->variant_attributes, [$this->row(['Size' => 'S'], 10, 1, ['sku' => 'B']), $this->row(['Size' => 'M'], 10, 1, ['sku' => 'A'])]);

        $this->assertSame(['B', 'A'], $product->variants()->pluck('sku')->all());
    }

    public function test_a_removed_combination_is_kept_for_history_and_can_be_offered_again(): void
    {
        $product = $this->variableProduct(['Size' => ['S', 'M']], [$this->row(['Size' => 'S'], 10), $this->row(['Size' => 'M'], 10)]);
        $medium = $this->variantOf($product, 'M');

        $this->service()->sync($product, $this->attributes(['Size' => ['S']]), [$this->row(['Size' => 'S'], 10)]);

        $this->assertSoftDeleted($medium);
        $this->assertNull($medium->fresh()->combination_key);
        $this->assertSame(['S'], $product->variants()->get()->map->label()->all());

        $this->service()->sync($product, $this->attributes(['Size' => ['S', 'M']]), [$this->row(['Size' => 'S'], 10), $this->row(['Size' => 'M'], 11, 2)]);

        $this->assertNotSame($medium->id, $this->variantOf($product, 'M')->id);
        $this->assertSame(2, $this->variantOf($product, 'M')->inventory_count);
    }

    public function test_removing_every_variant_makes_the_product_simple_again(): void
    {
        $product = $this->variableProduct(['Size' => ['S']], [$this->row(['Size' => 'S'], 15)], ['inventory_count' => 6]);

        $this->service()->sync($product, [], []);

        $product->refresh();
        $this->assertFalse($product->hasVariants());
        $this->assertNull($product->variant_attributes);
        // Product-level stock was set aside, not discarded, while the variants held the stock.
        $this->assertSame(6, $product->inventory_count);
        $this->assertTrue($product->tracksInventory());
    }

    public function test_attributes_without_variants_are_refused(): void
    {
        $product = Product::factory()->create();

        $this->assertArrayHasKey('variants', $this->errorsOf(fn () => $this->service()->sync($product, $this->attributes(['Size' => ['S']]), [])));
    }

    public function test_only_fixed_price_products_take_variants(): void
    {
        $product = Product::factory()->create(['pricing_type' => 'donation']);

        $this->assertArrayHasKey('variant_attributes', $this->errorsOf(fn () => $this->service()->sync($product, $this->attributes(['Size' => ['S']]), [$this->row(['Size' => 'S'], 10)])));
    }

    public function test_a_variant_id_from_another_product_is_refused(): void
    {
        $other = $this->variableProduct(['Size' => ['S']], [$this->row(['Size' => 'S'], 10)]);
        $product = Product::factory()->create();

        $errors = $this->errorsOf(fn () => $this->service()->sync($product, $this->attributes(['Size' => ['S']]), [
            ['id' => $other->variants->first()->id] + $this->row(['Size' => 'S'], 1),
        ]));

        $this->assertArrayHasKey('variants.0.id', $errors);
        $this->assertEquals(10, $other->variants()->first()->price);
    }

    public function test_saving_again_without_changes_keeps_every_sku_and_combination(): void
    {
        $tee = $this->variableProduct(['Size' => ['S', 'M']], [
            $this->row(['Size' => 'S'], 20, 5, ['sku' => 'TEE-S']),
            $this->row(['Size' => 'M'], 22, 5, ['sku' => 'TEE-M']),
        ]);
        $rows = $tee->variants->map(fn ($v) => ['id' => $v->id, 'attribute_values' => $v->attribute_values, 'sku' => $v->sku, 'price' => $v->price, 'track_inventory' => true, 'is_active' => true])->all();

        app(ProductVariantService::class)->sync($tee, $this->attributes(['Size' => ['S', 'M']]), $rows);

        $this->assertSame(['TEE-S', 'TEE-M'], $tee->variants()->pluck('sku')->all());
        $this->assertNotContains(null, $tee->variants()->pluck('combination_key')->all());
    }
}
