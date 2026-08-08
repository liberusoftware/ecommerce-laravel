<?php

namespace Tests\Feature\Filament;

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The /admin panel is Team-tenant-scoped too (`ownershipRelationship: 'team'`),
 * which the sibling AppPanelTenancyTest covers for /app but nothing covered
 * here.
 *
 * That gap is what #958 is: `DiscountResource` and `MenuResource` are registered
 * in this tenant-scoped panel while `discounts` and `menus` carry no `team_id`.
 * Filament's `scopeEloquentQueryToTenant` sees a `BelongsTo` — the models do
 * declare `team()`, through `IsTenantModel` — and emits
 * `whereBelongsTo($tenant, 'team')`, so the query names a column that is not
 * there.
 *
 * The resources are read off the panel rather than listed here by hand. A hand
 * list covers the resources somebody remembered, and the two that were missing
 * from it were the two registered by a plugin — `usingMenuResource()` and
 * `usingMenuItemResource()` — which is precisely where nobody looks. Enumerating
 * the panel means a resource cannot be added without being covered.
 */
class AdminPanelTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function actingInAdminPanel(): Team
    {
        // Allow all authorization so this isolates the TENANCY wiring rather
        // than the Shield permission layer — same reasoning as AppPanelTenancyTest.
        Gate::before(fn () => true);
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $team = $user->ownedTeams()->first();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($team);

        return $team;
    }

    /**
     * Over HTTP rather than through Livewire::test, and that is the whole point.
     *
     * Filament 5 registers the tenant scope as a global scope from its tenancy
     * middleware — `BelongsToTenant` says so in as many words: "scoping is
     * applied via global scopes registered after tenant identification in
     * middleware". Mounting a page with Livewire::test skips that middleware, so
     * no scope is ever registered and the page renders clean whether or not the
     * table could survive being scoped. A first version of this test did exactly
     * that and passed against the known-broken resources.
     */
    public function test_every_admin_panel_list_page_responds(): void
    {
        $team = $this->actingInAdminPanel();

        $resources = Filament::getPanel('admin')->getResources();

        $this->assertNotEmpty($resources, 'The admin panel registered no resources — the enumeration is wrong.');

        // Collected rather than asserted one at a time: the first failure would
        // otherwise hide the rest, and the point is the whole set.
        $failures = [];

        foreach ($resources as $resource) {
            if (! $resource::hasPage('index')) {
                continue;
            }

            $response = $this->get($resource::getUrl(tenant: $team));

            if ($response->getStatusCode() >= 300) {
                $failures[] = class_basename($resource).' -> '.$response->getStatusCode()
                    .($response->exception ? ' :: '.$response->exception::class.': '.$response->exception->getMessage() : '');
            }
        }

        sort($failures);

        $this->assertSame([], $failures, "Admin panel list pages that do not respond 2xx:\n".implode("\n", $failures));
    }
}
