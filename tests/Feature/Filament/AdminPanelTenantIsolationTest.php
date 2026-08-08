<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Discounts\DiscountResource;
use App\Filament\Admin\Resources\MenuResource;
use App\Models\Discount;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * #958, closed at the surface it was reported on.
 *
 * The sibling `AdminPanelTenancyTest` asserts these pages respond, which is the
 * *broken* half of the question `CONFORMANCE.md` §6.4 left undetermined. A page
 * that responds 200 while listing every merchant's rows answers it the other
 * way, so responding is not the assertion — seeing one merchant's rows and not
 * the other's is.
 *
 * Both directions, because a scope that returns nothing passes a one-sided test
 * and blanks the panel of the merchant it is supposed to serve.
 */
class AdminPanelTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_discounts_list_shows_this_merchants_discounts_and_not_another_merchants(): void
    {
        [$mine, $theirs] = $this->twoMerchants();

        $this->discountFor($mine, 'MINE-ONLY');
        $this->discountFor($theirs, 'THEIRS-ONLY');

        $response = $this->get(DiscountResource::getUrl(tenant: $mine));

        $response->assertOk();
        $response->assertSee('MINE-ONLY');
        $response->assertDontSee('THEIRS-ONLY');
    }

    public function test_the_menus_list_shows_this_merchants_menus_and_not_another_merchants(): void
    {
        [$mine, $theirs] = $this->twoMerchants();

        $this->menuFor($mine, 'mine-only-menu');
        $this->menuFor($theirs, 'theirs-only-menu');

        $response = $this->get(MenuResource::getUrl(tenant: $mine));

        $response->assertOk();
        $response->assertSee('mine-only-menu');
        $response->assertDontSee('theirs-only-menu');
    }

    /**
     * A menu item takes its team from its menu, including when it is created by
     * the menu builder page rather than through the resource — which is how
     * every item in this application is in fact created.
     */
    public function test_a_menu_item_inherits_the_team_of_the_menu_it_belongs_to(): void
    {
        [$mine] = $this->twoMerchants();

        $menu = $this->menuFor($mine, 'inheriting-menu');

        $item = MenuItem::create([
            'menu_id' => $menu->id,
            'name' => 'All Products',
            'type' => 'route',
            'route' => 'products.index',
        ]);

        $this->assertSame($mine->id, $item->team_id);
    }

    /**
     * Signs in as an owner of the first team and puts the admin panel in that
     * tenant, then returns both teams.
     *
     * Authorization is allowed wholesale so this isolates the TENANCY wiring
     * rather than the Shield permission layer — the same reasoning the sibling
     * tenancy tests give.
     *
     * @return array{0: Team, 1: Team}
     */
    private function twoMerchants(): array
    {
        Gate::before(fn () => true);
        Role::findOrCreate('super_admin', 'web');

        $user = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $mine = $user->ownedTeams()->first();
        $theirs = Team::factory()->create();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($mine);

        return [$mine, $theirs];
    }

    /**
     * `team_id` is not fillable — Filament associates the tenant through the
     * relationship rather than by mass assignment, so nothing needs it to be,
     * and a request that could post it would be posting its way into another
     * merchant. Set here the way the framework sets it.
     */
    private function discountFor(Team $team, string $title): Discount
    {
        return $this->owned(new Discount([
            'title' => $title,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 10,
            'target_type' => Discount::TARGET_ORDER,
        ]), $team);
    }

    private function menuFor(Team $team, string $name): Menu
    {
        return $this->owned(new Menu(['name' => $name, 'slug' => $name]), $team);
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    private function owned(Model $model, Team $team): Model
    {
        $model->team()->associate($team);
        $model->save();

        return $model;
    }
}
