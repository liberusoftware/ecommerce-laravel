<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Services\TaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxInclusiveDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function taxedProduct(float $price): Product
    {
        $classId = TaxClass::create(['name' => 'Standard', 'slug' => 'standard', 'is_active' => true])->id;
        TaxRate::create([
            'tax_class_id' => $classId, 'name' => 'VAT', 'country' => 'GB', 'rate' => 20.00,
            'priority' => 1, 'compound' => false, 'shipping' => false, 'is_active' => true,
        ]);

        return Product::factory()->create([
            'price' => $price, 'tax_status' => true, 'tax_class_id' => $classId,
            'inventory_count' => 5, 'pricing_type' => 'fixed',
        ]);
    }

    public function test_display_price_is_bare_when_flag_off(): void
    {
        config(['ecommerce.display_prices_with_tax' => false]);
        $product = $this->taxedProduct(100);

        $this->assertSame(100.0, app(TaxCalculator::class)->displayPrice($product));
    }

    public function test_display_price_includes_tax_when_flag_on(): void
    {
        config(['ecommerce.display_prices_with_tax' => true, 'ecommerce.store_country' => 'GB']);
        $product = $this->taxedProduct(100);

        // 100 + 20% VAT
        $this->assertSame(120.0, app(TaxCalculator::class)->displayPrice($product));
    }

    public function test_product_page_shows_tax_inclusive_price(): void
    {
        // On a storefront the store's own settings decide, not the deployment's.
        config(['ecommerce.display_prices_with_tax' => false, 'ecommerce.store_country' => 'US']);
        $product = $this->taxedProduct(100);
        $product->store->update(['display_prices_with_tax' => true, 'country_code' => 'GB']);

        $this->get(route('products.show', $product))
            ->assertStatus(200)
            ->assertSee('120.00')
            ->assertSee('inc. tax');
    }
}
