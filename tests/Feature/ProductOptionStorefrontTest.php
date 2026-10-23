<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsOptionGroups;
use Tests\TestCase;

/** Shoppers and headless clients can see what may be chosen and what it costs. */
class ProductOptionStorefrontTest extends TestCase
{
    use BuildsOptionGroups;
    use RefreshDatabase;

    private Product $burger;

    private ProductOptionGroup $bun;

    protected function setUp(): void
    {
        parent::setUp();
        $this->burger = Product::factory()->create(['name' => 'Smashdown', 'price' => 5.50, 'inventory_count' => 5]);
        $this->bun = $this->optionGroup('Bun', 1, 1, ['Brioche' => 0, 'Seeded' => 0.30], [$this->burger]);
        $this->choice($this->bun, 'Brioche')->update(['is_default' => true]);
        $extras = $this->optionGroup('Extras', 0, 2, ['Cheese' => 1.50, 'Egg' => 0.80], [$this->burger]);
        $this->choice($extras, 'Egg')->update(['is_available' => false]);
    }

    public function test_the_product_page_offers_each_group_as_a_form_control(): void
    {
        $html = $this->get(route('products.show', $this->burger))->assertOk()->getContent();

        $brioche = $this->choice($this->bun, 'Brioche')->id;
        $this->assertMatchesRegularExpression('/type="radio"\s+name="options\[\]"\s+value="'.$brioche.'"\s+required\s+checked/', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('(required)', $html);
        $this->assertStringContainsString('(up to 2)', $html);
        $this->assertStringContainsString('+$0.30', $html);
        $this->assertMatchesRegularExpression('/value="\d+"\s+disabled>\s*<span>Egg/', $html);
    }

    public function test_a_pay_what_you_want_product_shows_no_options(): void
    {
        $zine = Product::factory()->create(['pricing_type' => 'donation', 'price' => 0, 'minimum_price' => 2]);
        $this->optionGroup('Signed', 0, 1, ['Yes' => 5], [$zine]);

        $this->get(route('products.show', $zine))->assertOk()->assertDontSee('name="options[]"', false);
    }

    public function test_the_rest_api_lists_the_groups(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/products/{$this->burger->id}")
            ->assertOk()
            ->assertJsonPath('data.option_groups.0.name', 'Bun')
            ->assertJsonPath('data.option_groups.0.min_select', 1)
            ->assertJsonPath('data.option_groups.1.choices.0.name', 'Cheese')
            ->assertJsonPath('data.option_groups.1.choices.1.is_available', false);
    }

    public function test_graphql_lists_the_groups(): void
    {
        $data = $this->postJson('/api/graphql', [
            'query' => 'query($id: Int) { product(id: $id) { optionGroups { name minSelect maxSelect choices { name priceAdjustment isDefault isAvailable } } } }',
            'variables' => ['id' => $this->burger->id],
        ])->json('data.product.optionGroups');

        $this->assertSame(['Bun', 'Extras'], array_column($data, 'name'));
        $this->assertSame([2, 2], [$data[1]['maxSelect'], count($data[1]['choices'])]);
        $this->assertEquals(['name' => 'Seeded', 'priceAdjustment' => 0.3, 'isDefault' => false, 'isAvailable' => true], $data[0]['choices'][1]);
    }
}
