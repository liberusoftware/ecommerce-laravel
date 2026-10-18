<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\App\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\App\Resources\ProductCategories\Pages\ListProductCategories;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\StoreContext;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
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
    }

    private function category(Store $store, string $name, ?ProductCategory $parent = null): ProductCategory
    {
        return StoreContext::writingTo($store->id, fn () => ProductCategory::create([
            'name' => $name, 'parent_category_id' => $parent?->id,
        ]));
    }

    public function test_a_merchant_builds_a_two_level_hierarchy(): void
    {
        Livewire::test(CreateProductCategory::class)
            ->fillForm(['name' => 'Outdoor', 'slug' => 'outdoor', 'description' => 'For outside.'])
            ->call('create')
            ->assertHasNoFormErrors();
        $outdoor = ProductCategory::where('slug', 'outdoor')->sole();

        Livewire::test(CreateProductCategory::class)
            ->fillForm(['name' => 'Tents', 'slug' => 'tents', 'parent_category_id' => $outdoor->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $tents = ProductCategory::where('slug', 'tents')->sole();
        $this->assertSame($outdoor->id, $tents->parent_category_id);
        $this->assertSame($this->store->id, $tents->store_id);
        $this->assertSame($this->team->id, $tents->team_id);
    }

    public function test_a_subcategory_cannot_have_its_own_subcategory(): void
    {
        $outdoor = $this->category($this->store, 'Outdoor');
        $tents = $this->category($this->store, 'Tents', $outdoor);

        Livewire::test(CreateProductCategory::class)
            ->fillForm(['name' => 'Pegs', 'slug' => 'pegs', 'parent_category_id' => $tents->id])
            ->call('create')
            ->assertHasFormErrors(['parent_category_id']);

        $this->expectException(ValidationException::class);
        $this->category($this->store, 'Pegs', $tents);
    }

    public function test_a_parent_must_be_in_the_same_store(): void
    {
        $foreign = $this->category(Store::factory()->create(), 'Theirs');

        $this->expectException(ValidationException::class);
        $this->category($this->store, 'Mine', $foreign);
    }

    public function test_slugs_are_unique_within_a_store_only(): void
    {
        $first = $this->category($this->store, 'Lamps');
        $second = $this->category($this->store, 'Lamps');
        $elsewhere = $this->category(Store::factory()->create(), 'Lamps');

        $this->assertSame('lamps', $first->slug);
        $this->assertSame('lamps-2', $second->slug);
        $this->assertSame('lamps', $elsewhere->slug);

        Livewire::test(CreateProductCategory::class)
            ->fillForm(['name' => 'Lamps again', 'slug' => 'lamps'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_a_category_with_products_or_subcategories_is_not_deleted(): void
    {
        $outdoor = $this->category($this->store, 'Outdoor');
        $tents = $this->category($this->store, 'Tents', $outdoor);
        $product = Product::factory()->create(['category_id' => $tents->id]);
        $product->forceFill(['store_id' => $this->store->id])->save();
        $empty = $this->category($this->store, 'Empty');

        Livewire::test(EditProductCategory::class, ['record' => $outdoor->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('This category cannot be deleted');
        Livewire::test(EditProductCategory::class, ['record' => $tents->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('This category cannot be deleted');
        Livewire::test(EditProductCategory::class, ['record' => $empty->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertNotNull($outdoor->fresh());
        $this->assertNotNull($tents->fresh());
        $this->assertNull($empty->fresh());
    }

    public function test_the_list_shows_counts_and_searches(): void
    {
        $outdoor = $this->category($this->store, 'Outdoor');
        $this->category($this->store, 'Tents', $outdoor);
        $theirs = $this->category(Store::factory()->create(['team_id' => Team::factory()->create()->id]), 'Not Ours');

        Livewire::test(ListProductCategories::class)
            ->assertCanSeeTableRecords([$outdoor])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertTableColumnStateSet('children_count', 1, $outdoor)
            ->searchTable('Tent')
            ->assertCanNotSeeTableRecords([$outdoor]);
    }

    public function test_a_product_cannot_be_put_in_another_stores_category(): void
    {
        $second = Store::factory()->create(['team_id' => $this->team->id]);
        $otherStoresCategory = $this->category($second, 'Second Store Only');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'store_id' => $this->store->id, 'name' => 'Lamp', 'slug' => 'lamp', 'pricing_type' => 'fixed',
                'price' => 10, 'inventory_count' => 1, 'low_stock_threshold' => 0,
                'category_id' => $otherStoresCategory->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['category_id']);
    }

    public function test_the_storefront_respects_the_hierarchy_and_per_store_slugs(): void
    {
        ChannelDomain::factory()->primary()->create([
            'channel_id' => Channel::factory()->create(['store_id' => $this->store->id])->id, 'host' => 'mine.test',
        ]);
        $outdoor = $this->category($this->store, 'Outdoor');
        $tents = $this->category($this->store, 'Tents', $outdoor);
        $product = Product::factory()->create(['name' => 'Ridge Tent', 'category_id' => $tents->id]);
        $product->forceFill(['store_id' => $this->store->id])->save();
        $this->category(Store::factory()->create(), 'Outdoor');

        $this->get('http://mine.test/categories/outdoor')->assertOk()->assertSee('Tents')->assertSee('Ridge Tent');
        $this->get('http://mine.test/categories/outdoor/products')->assertOk()->assertSee('Ridge Tent');
        $this->get('http://mine.test/categories')->assertOk()->assertSee('Outdoor');
    }
}
