<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Stores\Pages\CreateStore;
use App\Filament\Admin\Resources\Stores\Pages\EditStore;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Support\Installation;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminStoreResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->admin->ownedTeams()->first());
        Filament::getPanel('admin')->boot();
    }

    private function saas(): void
    {
        config(['ecommerce.installation.mode' => Installation::SAAS]);
    }

    public function test_only_a_platform_administrator_manages_stores(): void
    {
        $merchant = User::factory()->withPersonalTeam()->create();

        $this->assertTrue($this->admin->can('viewAny', Store::class));
        $this->assertFalse($merchant->can('viewAny', Store::class));
        $this->assertFalse($merchant->can('create', Store::class));
    }

    public function test_a_store_is_created_for_a_team(): void
    {
        $this->saas();
        $team = Team::factory()->create();

        Livewire::test(CreateStore::class)
            ->fillForm(['name' => 'Acme Shop', 'slug' => 'acme', 'team_id' => $team->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($team->id, (int) Store::where('slug', 'acme')->value('team_id'));
    }

    public function test_the_installation_limit_applies_when_creating(): void
    {
        $this->saas();
        $team = Team::factory()->create();
        Store::factory()->create(['team_id' => $team->id]);

        Livewire::test(CreateStore::class)
            ->fillForm(['name' => 'Second', 'slug' => 'second', 'team_id' => $team->id])
            ->call('create')
            ->assertHasFormErrors(['team_id']);

        config(['ecommerce.installation.mode' => Installation::SINGLE]);
        Livewire::test(CreateStore::class)
            ->fillForm(['name' => 'Other', 'slug' => 'other', 'team_id' => Team::factory()->create()->id])
            ->call('create')
            ->assertHasFormErrors(['team_id']);
    }

    public function test_a_stores_team_cannot_be_changed_after_creation(): void
    {
        $store = Store::factory()->create(['team_id' => Team::factory()->create()->id]);
        $original = $store->team_id;

        Livewire::test(EditStore::class, ['record' => $store->getRouteKey()])
            ->set('data.team_id', Team::factory()->create()->id)
            ->call('save');

        $this->assertSame($original, $store->fresh()->team_id);
    }

    public function test_the_store_serving_the_main_site_is_identified(): void
    {
        $main = Store::where('slug', 'default')->firstOrFail();

        $this->assertTrue($main->servesMainSite());
        $this->assertFalse(Store::factory()->create()->servesMainSite());
    }

    public function test_a_store_with_commerce_data_or_serving_the_main_site_cannot_be_deleted(): void
    {
        $withProducts = Store::factory()->create();
        Product::factory()->create(['store_id' => $withProducts->id]);
        $empty = Store::factory()->create();

        $this->assertFalse($this->admin->can('delete', $withProducts));
        $this->assertFalse($this->admin->can('delete', Store::where('slug', 'default')->firstOrFail()));
        Livewire::test(EditStore::class, ['record' => $withProducts->getRouteKey()])->assertActionHidden(DeleteAction::class);

        Livewire::test(EditStore::class, ['record' => $empty->getRouteKey()])->callAction(DeleteAction::class);
        $this->assertNull($empty->fresh());
    }
}
