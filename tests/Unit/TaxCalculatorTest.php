<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Services\TaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private TaxCalculator $calculator;

    private int $taxClassId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new TaxCalculator;
        $this->taxClassId = TaxClass::create([
            'name' => 'Standard',
            'slug' => 'standard',
            'is_active' => true,
        ])->id;
    }

    private function makeProduct(array $overrides = []): Product
    {
        $category = ProductCategory::create([
            'name' => 'Test Category',
            'slug' => 'test-category-'.uniqid(),
        ]);

        return Product::create(array_merge([
            'name' => 'Test Product',
            'slug' => 'test-product-'.uniqid(),
            'price' => 100.00,
            'category_id' => $category->id,
            'inventory_count' => 10,
        ], $overrides));
    }

    private function makeRate(array $overrides = []): TaxRate
    {
        return TaxRate::create(array_merge([
            'tax_class_id' => $this->taxClassId,
            'name' => 'Standard Tax',
            'country' => 'US',
            'rate' => 10.00,
            'priority' => 1,
            'compound' => false,
            'shipping' => false,
            'is_active' => true,
        ], $overrides));
    }

    public function test_calculate_cart_tax_returns_zero_without_country(): void
    {
        $items = [];
        $address = [];

        $result = $this->calculator->calculateCartTax($items, $address);

        $this->assertEquals(['total' => 0, 'lines' => []], $result);
    }

    public function test_calculate_cart_tax_with_taxable_product(): void
    {
        $this->makeRate(['country' => 'US', 'rate' => 10.00]);
        $product = $this->makeProduct();

        $items = [
            ['product' => $product, 'price' => 100.00, 'quantity' => 1],
        ];
        $address = ['country' => 'US'];

        $result = $this->calculator->calculateCartTax($items, $address);

        $this->assertEquals(10.00, $result['total']);
        $this->assertCount(1, $result['lines']);
    }

    public function test_calculate_cart_tax_skips_non_product_items(): void
    {
        $this->makeRate(['country' => 'US', 'rate' => 10.00]);

        $items = [
            ['product' => null, 'price' => 100.00, 'quantity' => 1],
        ];
        $address = ['country' => 'US'];

        $result = $this->calculator->calculateCartTax($items, $address);

        $this->assertEquals(0.0, $result['total']);
    }

    public function test_calculate_product_tax_returns_correct_amount(): void
    {
        $this->makeRate(['country' => 'US', 'rate' => 8.00]);
        $product = $this->makeProduct();

        $tax = $this->calculator->calculateProductTax($product, 100.00, ['country' => 'US']);

        $this->assertEquals(8.00, $tax);
    }

    public function test_calculate_product_tax_returns_zero_for_unknown_location(): void
    {
        $product = $this->makeProduct();

        $tax = $this->calculator->calculateProductTax($product, 100.00, ['country' => 'XX']);

        $this->assertEquals(0.0, $tax);
    }

    public function test_get_price_with_tax_adds_tax_to_price(): void
    {
        $this->makeRate(['country' => 'US', 'rate' => 10.00]);
        $product = $this->makeProduct();

        $priceWithTax = $this->calculator->getPriceWithTax(100.00, $product, ['country' => 'US']);

        $this->assertEquals(110.00, $priceWithTax);
    }

    public function test_should_display_prices_with_tax_returns_bool(): void
    {
        $result = $this->calculator->shouldDisplayPricesWithTax();

        $this->assertIsBool($result);
    }

    public function test_calculate_cart_tax_includes_shipping_tax(): void
    {
        $this->makeRate(['country' => 'US', 'rate' => 5.00, 'shipping' => true, 'name' => 'Shipping Tax']);
        $product = $this->makeProduct();

        $items = [
            ['product' => $product, 'price' => 100.00, 'quantity' => 1],
        ];
        $address = ['country' => 'US'];

        $result = $this->calculator->calculateCartTax($items, $address, 20.00);

        // 5% on 100 (product) + 5% on 20 (shipping) = 5 + 1 = 6
        $this->assertEquals(6.00, $result['total']);
    }

    /**
     * The rule that came across from `TaxService` when the two tax stacks were
     * merged, and the only thing that service held which this one did not.
     *
     * Tax lands on what the shopper actually pays. Charging tax on the
     * pre-discount subtotal overcharges every order with a coupon on it, and
     * the error is invisible in the total.
     */
    public function test_a_cart_discount_moves_tax_onto_the_post_discount_amount(): void
    {
        $this->makeRate(['country' => 'US', 'rate' => 10.00]);
        $product = $this->makeProduct();

        $items = [['product' => $product, 'price' => 100.00, 'quantity' => 1]];

        // subtotal 100, discount 20 => taxable 80 => 10% = 8, not 10.
        $result = $this->calculator->calculateCartTax($items, ['country' => 'US'], 0, 20.00);

        $this->assertEquals(8.00, $result['total']);
    }

    /**
     * Why it is spread rather than subtracted. `TaxService` took the discount
     * off one blended subtotal, which is right only while every line shares a
     * rate — the moment a cart mixes rates, the answer depends on which line
     * the discount is deemed to have come off.
     */
    public function test_a_discount_is_spread_pro_rata_across_differently_taxed_items(): void
    {
        $reducedClassId = TaxClass::create(['name' => 'Reduced', 'slug' => 'reduced', 'is_active' => true])->id;

        $this->makeRate(['country' => 'US', 'rate' => 20.00, 'name' => 'Standard 20%']);
        $this->makeRate(['country' => 'US', 'rate' => 5.00, 'name' => 'Reduced 5%', 'tax_class_id' => $reducedClassId]);

        // Through the factory: `tax_class_id` is not fillable, and a product's
        // tax class is not something a request gets to set.
        $standard = Product::factory()->create(['tax_class_id' => $this->taxClassId]);
        $reduced = Product::factory()->create(['tax_class_id' => $reducedClassId]);

        $items = [
            ['product' => $standard, 'price' => 100.00, 'quantity' => 1],
            ['product' => $reduced, 'price' => 100.00, 'quantity' => 1],
        ];

        // subtotal 200, discount 100 => every line taxed on half its value:
        // 20% of 50 = 10, plus 5% of 50 = 2.50.
        $result = $this->calculator->calculateCartTax($items, ['country' => 'US'], 0, 100.00);

        $this->assertEquals(12.50, $result['total']);
    }

    public function test_a_discount_that_covers_the_cart_zeroes_the_tax(): void
    {
        $this->makeRate(['country' => 'US', 'rate' => 10.00]);
        $product = $this->makeProduct();

        $items = [['product' => $product, 'price' => 100.00, 'quantity' => 1]];

        // A coupon can zero the tax; it must never make it negative.
        $result = $this->calculator->calculateCartTax($items, ['country' => 'US'], 0, 500.00);

        $this->assertEquals(0.0, $result['total']);
    }

    public function test_a_cart_discount_does_not_reduce_shipping_tax(): void
    {
        // One rate, flagged as applying to shipping — the same shape as
        // test_calculate_cart_tax_includes_shipping_tax, so the only variable
        // here is the discount.
        $this->makeRate(['country' => 'US', 'rate' => 10.00, 'shipping' => true, 'name' => 'Shipping Tax']);
        $product = $this->makeProduct();

        $items = [['product' => $product, 'price' => 100.00, 'quantity' => 1]];

        // A cart coupon reduces what was bought, not what it cost to send:
        // 10% of 50 (discounted goods) + 10% of 20 (full shipping) = 5 + 2.
        $result = $this->calculator->calculateCartTax($items, ['country' => 'US'], 20.00, 50.00);

        $this->assertEquals(7.00, $result['total']);
    }

    public function test_an_untaxable_line_still_counts_toward_the_discount_spread(): void
    {
        $this->makeRate(['country' => 'US', 'rate' => 10.00]);
        $product = $this->makeProduct();

        // A line whose product has gone cannot be taxed — nothing says at what
        // rate — but the coupon was given against the whole cart, so it belongs
        // in the denominator. Otherwise the discount concentrates on whatever
        // is left and under-taxes it.
        $items = [
            ['product' => $product, 'price' => 100.00, 'quantity' => 1],
            ['product' => null, 'price' => 100.00, 'quantity' => 1],
        ];

        // subtotal 200, discount 100 => the taxable line is taxed on 50, not on 0.
        $result = $this->calculator->calculateCartTax($items, ['country' => 'US'], 0, 100.00);

        $this->assertEquals(5.00, $result['total']);
    }
}
