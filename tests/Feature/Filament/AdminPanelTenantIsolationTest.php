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

        $this->enterAdminPanel($mine);

        $response = $this->get(DiscountResource::getUrl(tenant: $mine));

        $response->assertOk();
        $response->assertSee('MINE-ONLY');

        $this->assertScopeReached(DiscountResource::class, Discount::class, 'title', 'MINE-ONLY');

        $response->assertDontSee('THEIRS-ONLY');
    }

    public function test_the_menus_list_shows_this_merchants_menus_and_not_another_merchants(): void
    {
        [$mine, $theirs] = $this->twoMerchants();

        $this->menuFor($mine, 'mine-only-menu');
        $this->menuFor($theirs, 'theirs-only-menu');

        $this->enterAdminPanel($mine);

        $response = $this->get(MenuResource::getUrl(tenant: $mine));

        $response->assertOk();
        $response->assertSee('mine-only-menu');

        $this->assertScopeReached(MenuResource::class, Menu::class, 'name', 'mine-only-menu');

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
     * Two merchants and a signed-in owner of the first — and deliberately no
     * Filament panel yet.
     *
     * Entering the panel is what makes Filament register its own `creating`
     * hook, which associates the *current tenant* with every new record of a
     * tenant-scoped resource's model. Build the fixtures inside that and both
     * merchants' rows are stamped with the same team, so the isolation
     * assertion passes or fails on the fixture rather than on the scope. The
     * first version of this test did exactly that and reported a leak that was
     * its own doing.
     *
     * @return array{0: Team, 1: Team}
     */
    private function twoMerchants(): array
    {
        // Authorization allowed wholesale so this isolates the TENANCY wiring
        // rather than the Shield permission layer — the same reasoning the
        // sibling tenancy tests give.
        Gate::before(fn () => true);
        Role::findOrCreate('super_admin', 'web');

        $user = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');

        $this->actingAs($user);

        return [$user->ownedTeams()->first(), Team::factory()->create()];
    }

    /**
     * Which link in the chain broke, asserted after the request rather than
     * before it — `SetUpPanel` is what boots the panel, and booting is what
     * registers the scope, so nothing is registered until a request has been
     * through.
     *
     * @param  class-string  $resource
     * @param  class-string<Model>  $model
     */
    private function assertScopeReached(string $resource, string $model, string $column, string $expected): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue(
            $model::hasGlobalScope($panel->getTenancyScopeName()),
            implode("\n", [
                "Filament registered no tenancy global scope on {$model}.",
                'panel has tenancy: '.var_export($panel->hasTenancy(), true),
                'tenancy scope name: '.$panel->getTenancyScopeName(),
                'current panel: '.(Filament::getCurrentPanel()?->getId() ?? 'none'),
                'tenant: '.(Filament::getTenant()?->getKey() ?? 'none'),
                'scopes on the model: '.implode(', ', array_keys($this->globalScopesOf($model)) ?: ['(none)']),
                'resource registered: '.var_export(in_array($resource, $panel->getResources(), true), true),
            ]),
        );

        $this->assertSame(
            [$expected],
            $resource::getEloquentQuery()->pluck($column)->all(),
            "{$resource} does not filter its own query to the current tenant.",
        );
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, mixed>
     */
    private function globalScopesOf(string $model): array
    {
        $property = new \ReflectionProperty(Model::class, 'globalScopes');

        return $property->getValue()[$model] ?? [];
    }

    /**
     * The panel has to be current before `getUrl()` can name a route on it.
     */
    private function enterAdminPanel(Team $team): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($team);
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
