<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Products\ProductResource;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * ProductCategory has no /app resource, so Filament's team scope never reaches
 * the product form's category options; the store scope is their only filter.
 */
class ProductFormCategoryTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function category(Store $store, string $name): void
    {
        ProductCategory::forceCreate([
            'name' => $name,
            'slug' => strtolower($name),
            'store_id' => $store->id,
            'team_id' => $store->team_id,
        ]);
    }

    private function createProductPageAs(Team $team): string
    {
        // Over HTTP, so Filament's tenancy middleware runs. Authorization is
        // opened up to isolate the scoping from Shield's permission layer.
        Gate::before(fn () => true);
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'admin']);
        $this->actingAs($user);

        return $this->get(ProductResource::getUrl('create', tenant: $team, panel: 'app'))
            ->assertOk()
            ->getContent();
    }

    private function otherMerchant(): Store
    {
        $store = Store::factory()->create(['team_id' => Team::factory()->create()->id]);
        $this->category($store, 'TheirCategory');

        return $store;
    }

    public function test_a_storeless_team_is_not_offered_another_merchants_categories(): void
    {
        $this->otherMerchant();

        $body = $this->createProductPageAs(Team::factory()->create());

        $this->assertStringNotContainsString('TheirCategory', $body);
    }

    public function test_a_merchant_is_offered_only_their_own_categories(): void
    {
        $this->otherMerchant();
        $team = Team::factory()->create();
        $this->category(Store::factory()->create(['team_id' => $team->id]), 'OurCategory');

        $body = $this->createProductPageAs($team);

        $this->assertStringContainsString('OurCategory', $body);
        $this->assertStringNotContainsString('TheirCategory', $body);
    }
}
