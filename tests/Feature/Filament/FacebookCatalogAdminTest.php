<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Jobs\SyncProductToFacebookCatalog;
use App\Models\FacebookConnection;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/** The merchant sees why Meta rejected an item, and can push the product again. */
class FacebookCatalogAdminTest extends TestCase
{
    use BuildsVariants;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        $store = Store::factory()->create(['team_id' => $team->id]);
        FacebookConnection::forceCreate(['team_id' => $team->id, 'access_token' => 't', 'catalog_id' => 'CAT1', 'graph_version' => 'v21.0']);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);

        $this->product = $this->variableProduct(['Colour' => ['Black', 'Silver']], [
            $this->row(['Colour' => 'Black'], 399),
            $this->row(['Colour' => 'Silver'], 419),
        ], ['store_id' => $store->id, 'team_id' => $team->id, 'list_on_facebook' => true]);

        foreach ($this->product->variants as $variant) {
            $this->product->facebookListings()->create([
                'product_variant_id' => $variant->id,
                'retailer_id' => 'variant-'.$variant->id,
                'status' => $variant->label() === 'Silver' ? 'error' : 'active',
                'errors' => $variant->label() === 'Silver' ? [['message' => 'Image too small']] : null,
            ]);
        }
    }

    public function test_the_product_form_shows_each_items_catalog_status(): void
    {
        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->assertOk()
            ->assertSee('Catalog status')
            ->assertSee('Black: active')
            ->assertSee('Silver: error — Image too small');
    }

    public function test_a_listed_product_can_be_pushed_again(): void
    {
        Livewire::test(ListProducts::class)
            ->callAction(TestAction::make('repushFacebook')->table($this->product));

        Queue::assertPushed(SyncProductToFacebookCatalog::class, fn ($job) => $job->productId === $this->product->id);
    }
}
