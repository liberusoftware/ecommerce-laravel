<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/** How a product with variants is shown: a price range, a choice per attribute, and nothing to buy until one is made. */
class ProductVariantStorefrontTest extends TestCase
{
    use BuildsVariants;
    use RefreshDatabase;

    private Product $tee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tee = $this->variableProduct(['Colour' => ['Black', 'Sand'], 'Size' => ['M']], [
            $this->row(['Colour' => 'Black', 'Size' => 'M'], 20, 3, ['sku' => 'TEE-BLK-M']),
            $this->row(['Colour' => 'Sand', 'Size' => 'M'], 24, 0, ['sku' => 'TEE-SND-M']),
        ], ['name' => 'Field Tee', 'slug' => 'field-tee', 'sku' => 'TEE']);
    }

    public function test_the_product_page_offers_a_choice_for_each_attribute(): void
    {
        $page = $this->get(route('products.show', $this->tee))->assertOk();

        $page->assertSeeInOrder([Money::format(20), Money::format(24)])
            ->assertSee('Colour')
            ->assertSee('value="Black"', false)
            ->assertSee('value="Sand"', false)
            ->assertSee('name="variant_id"', false)
            ->assertSee('1 of 2 options in stock')
            // A single-valued attribute starts chosen; the rest wait for the shopper.
            ->assertSee('\u0022Size\u0022:\u0022M\u0022', false)
            ->assertSee('\u0022Colour\u0022:null', false)
            ->assertSee('x-bind:disabled="hasVariants', false)
            ->assertDontSee('SKU <span class="data">TEE</span>', false);

        // Without script, a plain list in which the sold-out version cannot be picked.
        $this->assertMatchesRegularExpression('/<option value="\d+"\s+disabled>\s*Sand \/ M — '.preg_quote(Money::format(24), '/').' \(unavailable\)/', $page->getContent());
        $page->assertSee('"@type": "AggregateOffer"', false)->assertSee('"lowPrice": 20', false);
    }

    public function test_a_simple_product_page_has_no_variant_choice(): void
    {
        $mug = Product::factory()->create(['name' => 'Mug', 'price' => 9, 'inventory_count' => 4]);

        $this->get(route('products.show', $mug))->assertOk()
            ->assertDontSee('name="variant_id"', false)
            ->assertDontSee('AggregateOffer')
            ->assertSee('<span class="data">4</span> available', false);
    }

    public function test_listings_show_a_from_price_and_send_the_shopper_to_choose(): void
    {
        $this->get(route('products.index'))->assertOk()
            ->assertSee('From')
            ->assertSee(Money::format(20))
            ->assertSee('Choose options')
            ->assertDontSee(route('cart.add', $this->tee));
    }

    public function test_an_unavailable_choice_is_refused_by_the_page_form(): void
    {
        $sand = $this->variantOf($this->tee, 'Sand / M');

        $this->from(route('products.show', $this->tee))
            ->post(route('cart.add', $this->tee), ['variant_id' => $sand->id])
            ->assertSessionHas('error', 'Not enough inventory available.');

        $this->post(route('cart.add', $this->tee), [])
            ->assertSessionHas('error', 'Choose Colour and Size for Field Tee.');
    }

    public function test_the_cart_and_confirmation_name_the_variant(): void
    {
        $this->tee->update(['is_downloadable' => true]);
        $this->post(route('cart.add', $this->tee), ['variant_id' => $this->variantOf($this->tee, 'Black / M')->id]);

        $this->get(route('cart.index'))->assertOk()->assertSee('Colour: Black, Size: M');
        $this->get(route('checkout.initiate'))->assertOk()->assertSee('Colour: Black, Size: M');
    }
}
