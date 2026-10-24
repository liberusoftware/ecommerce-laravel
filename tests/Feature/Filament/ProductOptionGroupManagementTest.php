<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\ProductOptionGroups\Pages\CreateProductOptionGroup;
use App\Filament\App\Resources\ProductOptionGroups\Pages\ListProductOptionGroups;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\StoreContext;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsOptionGroups;
use Tests\TestCase;

class ProductOptionGroupManagementTest extends TestCase
{
    use BuildsOptionGroups;
    use RefreshDatabase;

    private Team $team;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->team = Team::factory()->create(['user_id' => $owner->id]);
        $this->store = Store::factory()->create(['team_id' => $this->team->id]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->team);
        Repeater::fake();
    }

    private function groupIn(Store $store, string $name): ProductOptionGroup
    {
        return StoreContext::writingTo($store->id, fn () => $this->optionGroup($name, 0, 1, ['Wrapped' => 3]));
    }

    public function test_a_merchant_defines_a_group_with_its_choices(): void
    {
        Livewire::test(CreateProductOptionGroup::class)
            ->fillForm([
                'name' => 'Size',
                'min_select' => 1,
                'max_select' => 1,
                'choices' => [
                    ['name' => 'Small', 'price_adjustment' => 0, 'is_default' => true, 'is_available' => true],
                    ['name' => 'Large', 'price_adjustment' => 0.20, 'is_default' => false, 'is_available' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = ProductOptionGroup::with('choices')->sole();
        $this->assertSame($this->store->id, $group->store_id);
        $this->assertSame($this->team->id, $group->team_id);
        $this->assertSame([1, 1], [$group->min_select, $group->max_select]);
        $this->assertSame(['Small', 'Large'], $group->choices->pluck('name')->all());
        $this->assertEquals(0.20, $group->choices[1]->price_adjustment);
    }

    public function test_a_maximum_below_the_minimum_is_refused(): void
    {
        Livewire::test(CreateProductOptionGroup::class)
            ->fillForm([
                'name' => 'Sauces', 'min_select' => 3, 'max_select' => 2,
                'choices' => [['name' => 'Mayo', 'price_adjustment' => 0, 'is_default' => false, 'is_available' => true]],
            ])
            ->call('create')
            ->assertHasFormErrors(['max_select']);
    }

    public function test_a_product_takes_only_its_own_stores_groups(): void
    {
        $ours = $this->groupIn($this->store, 'Gift wrap');
        $second = Store::factory()->create(['team_id' => $this->team->id]);
        $theirs = $this->groupIn($second, 'Engraving');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'store_id' => $this->store->id, 'name' => 'Lamp', 'slug' => 'lamp', 'pricing_type' => 'fixed',
                'price' => 10, 'inventory_count' => 1, 'low_stock_threshold' => 0,
                'optionGroups' => [$ours->id, $theirs->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['optionGroups']);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'store_id' => $this->store->id, 'name' => 'Lamp', 'slug' => 'lamp', 'pricing_type' => 'fixed',
                'price' => 10, 'inventory_count' => 1, 'low_stock_threshold' => 0,
                'optionGroups' => [$ours->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['Gift wrap'], Product::where('slug', 'lamp')->sole()->optionGroups->pluck('name')->all());
    }

    public function test_the_group_field_is_hidden_for_pay_what_you_want(): void
    {
        $product = StoreContext::writingTo($this->store->id, fn () => Product::factory()->create(['pricing_type' => 'donation', 'price' => 0]));

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldHidden('optionGroups');
    }

    public function test_a_merchant_lists_only_their_own_teams_groups(): void
    {
        $ours = $this->groupIn($this->store, 'Gift wrap');
        $theirs = $this->groupIn(Store::factory()->create(['team_id' => Team::factory()->create()->id]), 'Engraving');

        Livewire::test(ListProductOptionGroups::class)
            ->assertCanSeeTableRecords([$ours])
            ->assertCanNotSeeTableRecords([$theirs]);
    }
}
