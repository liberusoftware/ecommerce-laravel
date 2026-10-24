<?php

namespace Tests\Feature;

use App\Exceptions\InvalidProductConfigurationException;
use App\Models\Product;
use App\Models\Store;
use App\Services\ProductOptionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOptionGroups;
use Tests\TestCase;

/**
 * The option rules every cart door and checkout path rely on: what may be
 * chosen, how many, and what it costs.
 */
class ProductOptionResolverTest extends TestCase
{
    use BuildsOptionGroups;
    use RefreshDatabase;

    private Product $chips;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chips = Product::factory()->create(['name' => 'Chips', 'price' => 2.90]);
    }

    private function resolve(array $choiceIds, ?Product $product = null)
    {
        return app(ProductOptionResolver::class)->resolve($product ?? $this->chips, $choiceIds);
    }

    private function assertRefused(string $message, array $choiceIds, ?Product $product = null): void
    {
        try {
            $this->resolve($choiceIds, $product);
            $this->fail('The configuration was accepted.');
        } catch (InvalidProductConfigurationException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_a_product_without_options_resolves_to_its_selling_price(): void
    {
        $this->chips->update(['sale_price' => 2.50]);

        $configuration = $this->resolve([]);

        $this->assertSame([], $configuration->options);
        $this->assertSame(2.5, $configuration->unitPrice);
        $this->assertSame('', $configuration->key);
    }

    public function test_choices_add_their_adjustments_to_the_price(): void
    {
        $size = $this->optionGroup('Size', 1, 1, ['Small' => 0, 'Regular' => 0.10, 'Large' => 0.20], [$this->chips]);
        $type = $this->optionGroup('Type', 1, 1, ['Plain' => 0, 'Battered' => 0.20], [$this->chips]);

        $configuration = $this->resolve([$this->choice($type, 'Battered')->id, $this->choice($size, 'Large')->id]);

        $this->assertSame(3.30, $configuration->unitPrice);
        $this->assertSame(['Size', 'Type'], array_column($configuration->options, 'group'));
        $this->assertSame(['Large', 'Battered'], array_column($configuration->options, 'name'));
        $this->assertSame([0.20, 0.20], array_column($configuration->options, 'price_adjustment'));
    }

    public function test_adjustments_apply_to_the_sale_price(): void
    {
        $this->chips->update(['sale_price' => 2.00]);
        $size = $this->optionGroup('Size', 1, 1, ['Large' => 0.50], [$this->chips]);

        $this->assertSame(2.50, $this->resolve($this->choiceIds($size, 'Large'))->unitPrice);
    }

    public function test_a_negative_adjustment_never_takes_the_price_below_zero(): void
    {
        $size = $this->optionGroup('Size', 1, 1, ['Half' => -1.00, 'Taster' => -5.00], [$this->chips]);

        $this->assertSame(1.90, $this->resolve($this->choiceIds($size, 'Half'))->unitPrice);
        $this->assertSame(0.0, $this->resolve($this->choiceIds($size, 'Taster'))->unitPrice);
    }

    public function test_a_required_group_must_be_answered(): void
    {
        $this->optionGroup('Size', 1, 1, ['Small' => 0, 'Large' => 0.20], [$this->chips]);

        $this->assertRefused('Choose an option for Size', []);
    }

    public function test_a_pick_one_group_takes_only_one(): void
    {
        $size = $this->optionGroup('Size', 1, 1, ['Small' => 0, 'Large' => 0.20], [$this->chips]);

        $this->assertRefused('Choose only one option for Size', $this->choiceIds($size, 'Small', 'Large'));
    }

    public function test_minimum_and_maximum_limits_are_enforced(): void
    {
        $sauces = $this->optionGroup('Sauces', 2, 3, ['Mayo' => 0, 'Ketchup' => 0, 'BBQ' => 0.49, 'Chilli' => 0.49], [$this->chips]);

        $this->assertRefused('Choose at least 2 options for Sauces', $this->choiceIds($sauces, 'Mayo'));
        $this->assertRefused('Choose no more than 3 options for Sauces', $this->choiceIds($sauces, 'Mayo', 'Ketchup', 'BBQ', 'Chilli'));
        $this->assertSame(3.88, $this->resolve($this->choiceIds($sauces, 'Mayo', 'BBQ', 'Chilli'))->unitPrice);
    }

    public function test_an_optional_group_with_no_limit_takes_any_number(): void
    {
        $remove = $this->optionGroup('Remove', 0, null, ['No salt' => 0, 'No vinegar' => 0, 'No peas' => 0], [$this->chips]);

        $this->assertSame(2.90, $this->resolve([])->unitPrice);
        $this->assertCount(3, $this->resolve($this->choiceIds($remove, 'No salt', 'No vinegar', 'No peas'))->options);
    }

    public function test_a_choice_from_another_products_group_is_refused(): void
    {
        $burger = Product::factory()->create(['name' => 'Burger', 'price' => 5.50]);
        $extras = $this->optionGroup('Extras', 0, null, ['Cheese' => 1.50], [$burger]);

        $this->assertRefused('not offered for Chips', $this->choiceIds($extras, 'Cheese'));
    }

    public function test_a_group_from_another_store_is_refused_even_when_attached(): void
    {
        $theirs = Store::factory()->create();
        $foreign = $this->optionGroup('Size', 0, 1, ['Huge' => -2.00], [$this->chips], ['store_id' => $theirs->id]);

        $this->assertRefused('not offered for Chips', $this->choiceIds($foreign, 'Huge'));
    }

    public function test_an_unavailable_choice_is_refused(): void
    {
        $extras = $this->optionGroup('Extras', 0, null, ['Curry sauce' => 1.20], [$this->chips]);
        $this->choice($extras, 'Curry sauce')->update(['is_available' => false]);

        $this->assertRefused('Curry sauce is not available', $this->choiceIds($extras, 'Curry sauce'));
    }

    public function test_the_same_choice_twice_is_refused(): void
    {
        $extras = $this->optionGroup('Extras', 0, null, ['Cheese' => 1.50], [$this->chips]);
        $cheese = $this->choice($extras, 'Cheese')->id;

        $this->assertRefused('more than once', [$cheese, $cheese]);
    }

    public function test_the_key_ignores_the_order_choices_were_sent_in(): void
    {
        $extras = $this->optionGroup('Extras', 0, null, ['Cheese' => 1.50, 'Bacon' => 1.00], [$this->chips]);
        [$cheese, $bacon] = $this->choiceIds($extras, 'Cheese', 'Bacon');

        $this->assertSame($this->resolve([$cheese, $bacon])->key, $this->resolve([$bacon, (string) $cheese])->key);
        $this->assertNotSame($this->resolve([$cheese])->key, $this->resolve([$bacon])->key);
    }

    public function test_pay_what_you_want_products_take_no_options(): void
    {
        $donation = Product::factory()->create(['name' => 'Zine', 'pricing_type' => 'donation', 'price' => 0, 'minimum_price' => 3]);
        $extras = $this->optionGroup('Extras', 1, 1, ['Signed' => 5], [$donation]);

        $this->assertSame([], $this->resolve([], $donation)->options);
        $this->assertRefused('not offered for Zine', $this->choiceIds($extras, 'Signed'), $donation);
    }
}
